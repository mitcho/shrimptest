<?php

class ShrimpTest {

	// some constants based on WordPress install:
	var $cookie_domain;
	var $cookie_path;
	var $db_prefix;
	
	// should be configurable:
	var $cookie_name;
	var $cookie_dough;
	var $cookie_days;

	// versioning:	
	var $db_version = 7; // change to force database schema update

	// user agent filtering lists to be populated
	var $blocklist;
	var $blockterms;
	
	// variables to track information about/throughout the current execution
	var $visitor_id;
	var $visitor_cookie;
	var $touched_experiments;
	var $touched_metrics;
	var $override_variants;

	function ShrimpTest( ) {
		// Hint: run init( ) to get the party started.
	}

	function init( ) {
		global $wpdb;
		
		// Let other plugins modify various options
		$this->cookie_domain = apply_filters( 'shrimptest_cookie_domain', COOKIE_DOMAIN );
		$this->cookie_path   = apply_filters( 'shrimptest_cookie_path', COOKIEPATH );
		$this->cookie_name   = apply_filters( 'shrimptest_cookie_name', 'ebisen' );
		$this->db_prefix     = apply_filters( 'shrimptest_db_prefix', "{$wpdb->prefix}shrimptest_" );
		$this->cookie_dough	 = COOKIEHASH;
		$this->cookie_days   = apply_filters( 'shrimptest_cookie_days', 365 );

		add_action( 'init', array( &$this, 'versioning' ) );
		add_action( 'init', array( &$this, 'check_cookie' ) );

		add_action( 'wp_footer', array( &$this, 'print_foot' ) );
		add_action( 'wp_ajax_shrimptest_record', array( &$this, 'record_cookieability' ) );
		add_action( 'wp_ajax_nopriv_shrimptest_record', array( &$this, 'record_cookieability' ) );

		add_action( 'wp_ajax_shrimptest_override_variant', array( &$this, 'override_variant' ) );
		
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		
		add_filter( 'wpabar_menuitems', array( &$this, 'filter_adminbar' ) );
		
	}
	
	function admin_menu( ) {
		// $icon = plugins_url( null, __FILE__ ) . '/shrimp.png';
		// TODO: fix this:
		$icon = WP_PLUGIN_URL . '/shrimptest/shrimp.png';
		$slug = 'shrimptest';
		$dashboard = add_menu_page( 'ShrimpTest Dashboard', 'ShrimpTest', 'manage_options', $slug, array( &$this, 'admin_dashboard' ), $icon );
		
		$settings = add_submenu_page( $slug, 'ShrimpTest Settings', 'Settings', 'manage_options', "{$slug}_settings", array( &$this, 'admin_settings' ) );
		$experiments = add_submenu_page( $slug, 'ShrimpTest Experiments', 'Experiments', 'manage_options', "{$slug}_experiments", array( &$this, 'admin_experiments' ) );
		
		add_action( 'admin_head-'. $dashboard, array( &$this, 'admin_header' ) );
		add_action( 'admin_head-'. $settings, array( &$this, 'admin_header' ) );
		add_action( 'admin_head-'. $experiments, array( &$this, 'admin_header' ) );
		
	}
	
	function admin_header( ) {
		// TODO: fix URL path
		$icon = WP_PLUGIN_URL . '/shrimptest/shrimp-large.png';
		echo "<style type=\"text/css\">
		#icon-shrimptest {background: url($icon) no-repeat center center}
		tr.variant td {padding-left: 15px;}
		</style>
		<script>
			jQuery(document).ready(function($) {
				$('.postbox').children('h3, .handlediv').click(function(){
					$(this).siblings('.inside').toggle();
				});
			});
		</script>";
		
	}
	
	function admin_dashboard( ) {
		include SHRIMPTEST_DIR . '/admin/dashboard.php';
	}

	function admin_settings( ) {
		include SHRIMPTEST_DIR . '/admin/settings.php';
	}

	function admin_experiments( ) {
		include SHRIMPTEST_DIR . '/admin/experiments.php';
	}

	function get_active_experiments( ) {
		return $this->get_experiments( array('status'=>'active') );
	}
	
	function get_experiments( $args = array( ) ) {
		global $wpdb;
		$defaults = array(
			'status' => '',
			'offset' => 0,
			'orderby' => 'start_time',
			'order' => 'ASC',
		);
		$r = wp_parse_args( $args, $defaults );
		
		$sql = 
		"select experiment_id, {$this->db_prefix}experiments.name as experiment_name, {$this->db_prefix}metrics.name as metric_name, status, 
		unix_timestamp(start_time) as start_time, unix_timestamp(end_time) as end_time, unix_timestamp(now()) as now
		from {$this->db_prefix}experiments
		join {$this->db_prefix}metrics using (`metric_id`)
		where 1";
		
		if ( !empty( $r['experiment_id'] ) )
			$sql .= " and experiment_id = {$r[experiment_id]}";
		
		if ( !empty( $r['status'] ) ) {
			if ( is_array( $r['status'] ) )
				$status = $r['status'];
			else
				$status = array( $r['status'] );
			$sql .= " and status in ('".implode("','",$status)."')";
		}
		
		$sql .= " order by {$r[orderby]} {$r[order]}";

		return $wpdb->get_results( $sql );
	}
	
	function get_experiment_status( $experiment_id ) {
		global $wpdb;
		return $wpdb->get_var( "select status from {$this->db_prefix}experiments where experiment_id = {$experiment_id}" );
	}
	
	function get_experiment_stats( $experiment_id ) {
		global $wpdb;

		$metric_type = $wpdb->get_var( "select type from {$this->db_prefix}metrics join {$this->db_prefix}experiments using (`metric_id`) where experiment_id = {$experiment_id}" );
		
		$metric_id = $wpdb->get_var("select metric_id from {$this->db_prefix}experiments where experiment_id = $experiment_id");
		
		if ( $metric_type == 'conversion' )
			$value = "bit_or(ifnull(vm.value,0))";
		elseif( $metric_type == 'culmulative' )
			$value = "sum(ifnull(vm.value,0))";
		elseif( $metric_type == 'average' )
			$value = "avg(ifnull(vm.value,0))";
	
		$unique = "if(cookies = 1, v.visitor_id, concat(ip,user_agent))";
	
		$uvsql = "SELECT variant_id, count(distinct variant_id) as variant_count, {$value} as value, {$unique} as unique_visitor_id"
		       . " FROM `{$this->db_prefix}visitors_variants` as vv "
		       . " join `{$this->db_prefix}visitors` as v using (`visitor_id`)"
		       . " left join `{$this->db_prefix}visitors_metrics` as vm"
		       . " on (vm.visitor_id = vv.visitor_id and vm.metric_id = {$metric_id})"
		       . " where vv.experiment_id = {$experiment_id}"
		       . " group by unique_visitor_id"
		       . " having variant_count = 1";
		$total_sql = "select count(unique_visitor_id) as N, avg(value) as avg, stddev(value) as sd from ({$uvsql}) as uv";
		$stats = array();
		$stats['total'] = $wpdb->get_row( $total_sql );
		
		$variant_sql = "select variant_id, variant_name, count(unique_visitor_id) as N, avg(value) as avg, stddev(value) as sd from ({$uvsql}) as uv join {$this->db_prefix}experiments_variants using (variant_id) group by variant_id order by variant_id asc";
		$variant_stats = $wpdb->get_results( $variant_sql );
		foreach ( $variant_stats as $variant ) {
			$stats[$variant->variant_id] = $variant;
		}
		
		return $stats;
	}
	
	/*
	 * get_experiment_variants: get a list of variants for the current experiment
	 */
	function get_experiment_variants( $experiment_id ) {
		global $wpdb;
		return $wpdb->get_results( "select variant_id, variant_name
																from `{$this->db_prefix}experiments_variants`
																where `experiment_id` = {$experiment_id}" );
	}
	
	function check_cookie( ) {
		global $wpdb;

		$this->visitor_id = $this->visitor_cookie = null;

		// check if the current user is exempt, in which case they'll get a null visitor_id
		if ( $this->exempt_user( ) )
			return;

		// check if this visitor is on our user agent blocklist, largely a list of spiders
		$user_agent = $_SERVER['HTTP_USER_AGENT'];
		if ( $this->is_blocked( $user_agent ) ) {
			return;
		}

		// if there's a cookie...
		if ( isset( $_COOKIE[$this->cookie_name] ) ) {
			$this->visitor_cookie = $_COOKIE[$this->cookie_name];
			
			// verify that it's actually registered with us, by getting its visitor_id.
			$sql = "select visitor_id, cookies from {$this->db_prefix}visitors where cookie = X'{$this->visitor_cookie}'";
			$this->visitor_id = $wpdb->get_var( $sql, 0 );
			
			// if cookie valid but visitor is marked as not having cookie support, correct that.
			if ( $this->visitor_id && $wpdb->get_var( $sql, 1 ) == 0 ) {
				$wpdb->query( "update `{$this->db_prefix}visitors` 
											 set cookies = 1
											 where visitor_id = {$this->visitor_id}" );
			}
		}

		// if not registered, or cookie doesn't match, cookie them!
		if ( !$this->visitor_id )
			$this->set_cookie();
		
	}

	/*
	 * set_cookie: sets the cookie and returns the internal id
	 * TODO: consider fallback for when the browser does not have cookies set.
	 */	
	function set_cookie( ) {
		global $wpdb;
		
		$keepgoing = true;
		// this loop shouldn't take long, as you'd have to be *really* unlucky to get a collision
		do {
			// hash_hmac always available via compat
			$cookie = hash_hmac( 'md5', time() . mt_rand(), $this->cookie_dough );
			// if not found, $keepgoing will be false.
			$keepgoing = $wpdb->get_var( "select id from `{$this->db_prefix}visitors` where cookie = X'{$cookie}'" );
		} while ( $keepgoing );
		
		$success = setcookie( $this->cookie_name, $cookie, time() + 60*60*24*$this->cookie_days, $this->cookie_path, $this->cookie_domain );
		
		if ( $success ) {
			$user_agent = $_SERVER['HTTP_USER_AGENT'];
			$ip = $_SERVER['REMOTE_ADDR'];
			$wpdb->query( "insert into `{$this->db_prefix}visitors` (`cookie`,`user_agent`,`ip`) values (X'{$cookie}','{$user_agent}',inet_aton('{$ip}'))" );
			$this->visitor_id = $wpdb->insert_id;
			$this->visitor_cookie = $cookie;
			return $id;
		} else {
			// TODO: error handling? Cookie couldn't be set.
			return false;
		}		
	}

	/*
	 * load_blocklist: load the user agent blocklist
	 * @param array $blocklist
	 */ 	
	function load_blocklist( $blocklist ) {
		$this->blocklist = apply_filters( 'shrimptest_blocklist', $blocklist );
	}

	/*
	 * load_blocklist: load the user agent blockterms list
	 * @param array $blockterms
	 */ 	
	function load_blockterms( $blockterms ) {
		$this->blockterms = apply_filters( 'shrimptest_blockterms', $blockterms );
	}

	function is_blocked( $user_agent ) {

		// don't block record_cookieability calls
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX && $_REQUEST['action'] == 'shrimptest_record' )
			return false;
	
		if ( is_feed() )
			return true;
		if ( defined( 'WP_ADMIN' ) && WP_ADMIN )
			return true;
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			return true;
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			return true;
	
		if ( !is_array( $this->blockterms ) )
			$this->blockterms = array( 'this is a dummy string which should never match' );
		$blockterms = array_map( 'preg_quote', $this->blockterms );
		$blockterms_regexp = '%('.join('|',$blockterms).')%i';
		return ( preg_match( $blockterms_regexp, $user_agent )
						 || array_search( $user_agent, $this->blocklist ) );
	}
	
	function exempt_user( ) {
		$exempt = false;
		if ( is_user_logged_in( ) )
			$exempt = true;
		$exempt = apply_filters( 'shrimptest_exempt_user', $exempt );
		return $exempt;
	}
	
	/*
	 * update_visitor_metric
	 * @param boolean $monotonic - if true, will only update if value is greater
	 */ 
	function update_visitor_metric( $metric_id, $value, $monotonic = false, $visitor_id = false ) {
		global $wpdb;

		// if the user is exempt (like a logged in admin), return control.
		if ( $this->exempt_user( ) ) {
			$this->touch_metric( $metric_id, array( 'value' => null ) );
			return null;
		}

		if ( !$visitor_id )
			$visitor_id = $this->visitor_id;
		if ( is_null( $visitor_id ) )
			return null;

		// TODO: validate metric id and/or $value
		
		$sql = "insert into `{$this->db_prefix}visitors_metrics`
						  (`visitor_id`, `metric_id`, `value`)
						  values ({$visitor_id}, {$metric_id}, {$value})
						on duplicate key update `value` = "
						. ( $monotonic ? "greatest({$value},value)" : $value );

		$this->touch_metric( $metric_id, array( 'value' => $value ) );

		return $wpdb->query( $sql );
	}

	// NOTE: getting the value of a metric for an individual visitor...
	// I wrote it, but does this really have a use case?
	function get_visitor_metric( $metric_id, $visitor_id = false ) {
		global $wpdb;

		if ( !$visitor_id )
			$visitor_id = $this->visitor_id;
		if ( is_null( $visitor_id ) )
			return null;

		// TODO: validate metric id
		
		return $wpdb->get_var( "select value from `{$this->db_prefix}visitors_metrics`
														where `visitor_id` = {$visitor_id}" );
	}

	/*
	 * get_visitor_variant: get the variant for the given experiment and visitor
	 *
	 * @uses w_rand
	 */
	function get_visitor_variant( $experiment_id, $visitor_id = false ) {
		global $wpdb;

		// If the user is exempt (like a logged in admin), check if they've overridden the variant.
		// If not, it will return null for control.
		if ( $this->exempt_user( ) ) {
			$variant = $this->get_override_variant( $experiment_id );
			$this->touch_experiment( $experiment_id, array( 'variant' => $variant ) );
			return $variant;
		}

		if ( !$visitor_id )
			$visitor_id = $this->visitor_id;
		if ( is_null( $visitor_id ) )
			return null;
		
		// if the experiment is not turned on, use the control.
		if ( $this->get_experiment_status( $experiment_id ) != 'active' )
			return null;

		$variant = $wpdb->get_var( "select variant_id from `{$this->db_prefix}visitors_variants`
																where `experiment_id` = {$experiment_id}
																and `visitor_id` = {$visitor_id}" );

		if ( is_null( $variant ) ) { // the variant hasn't been set yet.
			$sql = "select variant_id, assignment_weight
							from {$this->db_prefix}experiments_variants
							where experiment_id = {$experiment_id}";
			$variants = $wpdb->get_col( $sql, 0 );
			$weights  = $wpdb->get_col( $sql, 1 );
			
			// there is no such experiment or no variants
			if ( !is_array($variants) || !count($variants) )
				return null;

			// use the weighted rand (w_rand) method to get a random variant
			$variant = $this->w_rand( array_combine( $variants, $weights ) );
			
			$wpdb->query( "insert into `{$this->db_prefix}visitors_variants`
										(`visitor_id`,`experiment_id`,`variant_id`)
										values ({$visitor_id},{$experiment_id},{$variant})" );
		}
		
		$this->touch_experiment( $experiment_id, array( 'variant' => $variant ) );
		
		return $variant;
	}
	
	function get_override_variant( $experiment_id ) {
		global $user_ID;
		get_currentuserinfo();
		if ( !isset( $this->override_variants ) )
			$this->override_variants = get_user_meta( $user_ID, "shrimptest_override_variants", true );
		return $this->override_variants[$experiment_id];
	}

	/*
	 * w_rand: takes an associated array with numerical values and returns a weighted-random key
	 * Based on code from http://20bits.com/articles/random-weighted-elements-in-php/
	 *
	 * required for get_visitor_variant()
	 */
	function w_rand($weights) {

		// normalize the weights first so that they sum to 1
		$sum = array_sum($weights);
		foreach ( $weights as $k => $w ) {
			$weights[$k] = $w / $sum;
		}
		
		// pick 
		$r = mt_rand( 1, 1000 );
		$offset = 0;
		foreach ( $weights as $k => $w ) {
			$offset += $w * 1000;
			if ( $r <= $offset ) {
				return $k;
			}
		}
	}

	/*
	 * touch_experiment
	 *
	 * This function is used to keep track of what experiments were accessed ("touched") througout
	 * the printing of the current page. This information is not normally printed, but is used to
	 * produce the ShrimpTest bar (or ShrimpTest component of the Admin Bar) when an admin is
	 * logged in.
	 */
	function touch_experiment( $experiment_id, $args ) {
		$this->touched_experiments[$experiment_id] = array_merge_recursive( $this->touched_experiments[$experiment_id], $args );
	}
	/*
	 * touch_metric: like touch_experiment, but for metrics
	 */
	function touch_metric( $metric_id, $args ) {
		$this->touched_metrics = array_merge_recursive( $this->touched_metrics, array( $metric_id => $args ) );
	}

	function print_foot( ) {
		global $wpdb, $WPAdminBar;

		if ( is_user_logged_in( ) && ( !empty( $this->touched_experiments ) || !empty( $this->touched_metrics ) ) ) {
			if ( empty( $WPAdminBar ) )
				$this->print_shrimptest_widget( );
			else
				$this->print_adminbar( );
		}

		// if we already know that they have JS, no need to record again.
		if ( $wpdb->get_var( "select js from `{$this->db_prefix}visitors` where visitor_id = {$this->visitor_id}" ) )
			return;

		$cookie_name = preg_quote($this->cookie_name);
	?>
<script type="text/javascript">
setTimeout(function() {
	var tests = {};
	tests.a = ( 'sessionStorage' in window );
	tests.b = ( 'localStorage' in window );
	var cookieMatch = document.cookie.match( /<?php echo $cookie_name;?>=([a-f0-9]+)/ );
	if ( cookieMatch !== null )
		tests.c = cookieMatch[1];
	var query = 'action=shrimptest_record';
	for ( var key in tests ) {
		query += '&' + key + '=' + escape( tests[key] );
	}
	var adminajax = "<?php echo admin_url('admin-ajax.php');?>";
	var req = new XMLHttpRequest( );
	req.open( 'GET', adminajax + '?' + query, true );
	req.send( null );
}, 5);
</script>
<?php
	}
	
	function print_shrimptest_widget( ) {
		$icon = WP_PLUGIN_URL . '/shrimptest/shrimp.png';
		$menus = $this->get_menus( );
?>
<style type="text/css">
#shrimptest-menu {
position: fixed; top: 0pt;
color: #fff; left: 0pt;
}
#shrimptest-menu li.brand {
/*cursor: default;*/
border-left: none;
}
#shrimptest-menu ul {
list-style: none outside none !important;
padding: 0;
margin: 0;
font: 12px/28px "Lucida Grande","Lucida Sans Unicode",Tahoma,Verdana;
}
#shrimptest-menu li {
	margin: 0;
display: inline;
display: inline-block;
background-color: #333;
border-left: 2px solid rgb(170, 170, 170);
cursor: hand;
cursor: pointer;
font-weight: bold;
float: left;
}
#shrimptest-menu sup, #shrimptest-menu sub {
	font-size: 0.7em;
}
#shrimptest-menu li a, #shrimptest-menu li span {
text-decoration: none;
color: #fff;
padding: 0 0.75em;
display: inline-block;
height: 28px;
text-shadow: -1px -1px 2px rgba(0,0,0,0.2);
}
#shrimptest-menu li:hover {
	background-color: #666;
}
#shrimptest-menu li ul {
	border: 1px solid #999;
	display: none;
}
#shrimptest-menu li ul li {
	background-color: #eee;
	border-left: none;
}
#shrimptest-menu li ul li a, #shrimptest-menu li ul li span {
	min-width: 180px;
	display: block;
	color: #333;
	font-weight: normal;
	text-shadow: -1px -1px 1px rgba(0,0,0,0.1);
}
#shrimptest-menu li:hover ul {
	display: block;
	position: absolute;
}

#shrimptest-menu li ul li:hover {
	background-color: #ccc;
}

</style>
<div id="shrimptest-menu">
<ul>
<li class="brand"><a href="<?php echo admin_url('admin.php?page=shrimptest');?>">ShrimpTest</a></li><?php
	foreach ( $menus as $key => $menu ) {

		echo "<li>";
		if ( empty( $key ) )
			$str = "<span>{$menu[0][title]}</span>";
		else
			$str = "<a href=\"" . admin_url($key) . "\">{$menu[0][title]}</a>";
		echo "{$str}";
		
		if ( count($menu) > 1 ) {
			echo "<ul>";
			foreach ( $menu as $key => $menuitem ) {
				if ( $menuitem == $menu[0] )
					continue;
				if ( empty( $key ) )
					$str = "<span>{$menuitem[title]}<span>";
				else
					$str = "<a href=\"" . admin_url($key) . "\">{$menuitem[title]}</a>";
				echo "<li>{$str}</li>";
			}
			echo "</ul>";
		}
		echo "</li>";
	}
?></ul>
</div>
<?php
	}
	
	function print_adminbar( ) {
		// we don't actually need to print anything independently in wp_footer as WP Admin Bar's 
		// OutputMenuBar operates in wp_footer, so we just filter it with filter_adminbar.
	}
	
	function get_menus( ) {
		$menus = array();
		if ( !empty( $this->touched_experiments ) ) {
			$experiments = array( array( 'id'=>'20', 'title'=>'<sup>A</sup>/<sub>B</sub>', 'custom'=>false ) );

			foreach( $this->touched_experiments as $experiment_id => $data ) {
				$status = $this->get_experiment_status( $experiment_id );
				// TODO: display experiment name
				$experiments["admin.php?page=shrimptest_experiments&id={$experiment_id}"] = array( 'id'=>$experiment_id, 'title'=>"Experiment {$experiment_id} <small>(status: {$status})</small>", 'custom'=>false );
				
				// display each of the variants
				foreach ( $this->get_experiment_variants( $experiment_id ) as $variant ) {
					if ( $variant->variant_id == 0 )
						$title = "Control";
					else
						$title = "Variant {$variant->variant_id}";

					// add variant name
					if ( !empty( $variant->variant_name ) )
						$title .= ": {$variant->variant_name}";

					if ( $data['variant'] == $variant->variant_id )
						$title = "&#x2714; {$title}";
					else
						$title = "&#x3000; {$title}";
						
					$experiments["admin-ajax.php?action=shrimptest_override_variant&experiment_id={$experiment_id}&variant_id={$variant->variant_id}"] = array( 'id'=>$variant->variant_id, 'title'=>$title, 'custom'=>false );					
				}
			}
			$menus[] = $experiments;
		}

		if ( !empty( $this->touched_metrics ) ) {
			$metrics = array( array( 'id'=>'21', 'title'=>'&#x2605;', 'custom'=>false ) );

			foreach( $this->touched_metrics as $metric_id => $data ) {
				// TODO: display metric name
				if ( isset( $data->value ) )
					$value = " <small>(value: $data->value)</small>";
				else
					$value = "";
				$metrics[] = array( 'id' => $metric_id, 'title'=>"Metric {$metric_id}{$value}", 'custom'=>false );
			}
			$menus[] = $metrics;
		}

		return $menus;
	}
	
	function filter_adminbar( $menus ) {

		// we want to be on the left side of the menu, so find the magical point where we're on the
		// right edge of the left side.
		$i = 0;
		foreach ( $menus as $key => $menu ) {
			if ( $menu[0]['id'] > 39 )
				break;
			$i++;
		}
		// now $i has the index for where we want to splice in our new menus.

		array_splice( $menus, $i, 0, $this->get_menus( ) );
		return $menus;
	}
	
	function record_cookieability( ) {
		global $wpdb;

		if ( is_null( $this->visitor_id ) )
			die( 'null' );
		
		if ( $this->visitor_cookie !== $_REQUEST['c'] ) {
			// how did they get a different cookie!?
		}

		$wpdb->query( "update `{$this->db_prefix}visitors` 
									 set js = 1, 
									 cookies = " . ( isset( $_REQUEST['c'] ) ? '1' : '0' ) . ", 
									 localstorage = " . ( $_REQUEST['b'] == 'true' ? '1' : '0' ) . " 
									 where visitor_id = {$this->visitor_id}" );
		echo "shrimpity shrimp shrimp shrimp"; // just a friendly message
		exit;
	}

	function override_variant( ) {
		global $user_ID;
		get_currentuserinfo();

		// TODO: validate experiment and variant ID's
		$experiment_id = (int) $_REQUEST["experiment_id"];
		$variant_id = (int) $_REQUEST["variant_id"];
		
		$this->override_variants[$experiment_id] = $variant_id;
		update_user_meta( $user_ID, "shrimptest_override_variants", $this->override_variants );

		if ( isset( $_SERVER['HTTP_REFERER'] ) )
			wp_redirect( $_SERVER['HTTP_REFERER'] );
		else
			echo "<script type=\"text/javascript\">window.history.back();</script>";
		exit;
	}
	
	/*
	 * versioning: adds DB versioning support
	 * note here I use site_option's because ShrimpTest db tables exist for each site.
	 */
	function versioning( ) {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX  )
			return;
		$current = array( 'version' => SHRIMPTEST_VERSION, 'db' => $this->db_version );
		if ( $current !== get_site_option('shrimptest_version') ) {
			$this->ensure_db();
			update_site_option( 'shrimptest_version', $current );
		}
	}

	/*
	 * ensure_db: make sure that our tables are set up.
	 */
	function ensure_db( ) {
		global $wpdb;
		
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		
		$dbSql = array(
						"CREATE TABLE `{$this->db_prefix}visitors` (
							`visitor_id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
							`cookie` BINARY(16) NOT NULL UNIQUE KEY ,
							`user_agent` VARCHAR(255) NOT NULL ,
							`ip` INT UNSIGNED NULL ,
							`js` BOOL NOT NULL DEFAULT 0 ,
							`cookies` BOOL NOT NULL DEFAULT 0 ,
							`localstorage` BOOL NOT NULL DEFAULT 0 ,
							`timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
						) ENGINE = MYISAM ;",
						// TODO: question: should experiments just be a custom post type?
						"CREATE TABLE `{$this->db_prefix}experiments` (
							`experiment_id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
							`name` VARCHAR(255) NOT NULL ,
							`metric_id` INT UNSIGNED NOT NULL ,
							`status` varchar(30) default 'inactive' ,
							`start_time` TIMESTAMP NULL ,
							`end_time` TIMESTAMP NULL ,
							`timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
						) ENGINE = MYISAM ;",
						"CREATE TABLE `{$this->db_prefix}experiments_variants` (
							`experiment_id` INT UNSIGNED NOT NULL ,
							`variant_id` INT UNSIGNED NOT NULL DEFAULT 0
								COMMENT 'variant 0 is always \"control\"',
							`assignment_weight` FLOAT UNSIGNED NOT NULL DEFAULT 1 ,
							`variant_name` VARCHAR( 255 ) NOT NULL ,
							INDEX ( `experiment_id` )
						) ENGINE = MYISAM ;",
						"CREATE TABLE `{$this->db_prefix}visitors_variants` (
							`visitor_id` INT NOT NULL ,
							`experiment_id` INT NOT NULL ,
							`variant_id` INT NOT NULL ,
							`timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
						) ENGINE = MYISAM ;",
						"CREATE TABLE `{$this->db_prefix}visitors_metrics` (
							`visitor_id` INT NOT NULL ,
							`metric_id` INT UNSIGNED NOT NULL 
								COMMENT 'right now metric_id is tied to experiment_id',
							`value` FLOAT NOT NULL ,
							`timestamp` TIMESTAMP NOT NULL
								DEFAULT CURRENT_TIMESTAMP
								ON UPDATE CURRENT_TIMESTAMP ,
							PRIMARY KEY ( `visitor_id` , `metric_id` )
						) ENGINE = MYISAM ;",
						"CREATE TABLE `{$this->db_prefix}metrics` (
							`metric_id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
							`name` VARCHAR( 255 ) NOT NULL,
							`type` VARCHAR( 255 ) NOT NULL,
							`timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
						) ENGINE = MYISAM ");
		dbDelta( $dbSql );
		
	}

} // class ShrimpTest

if ( !function_exists( 'array_combine' ) ) { // for PHP4
	function array_combine( $a, $b ) {
		$c = array( );
	 
		$a = array_values( $a );
		$b = array_values( $b );
	 
		foreach( $a as $k => $v ) {
			$c[ $v ] = $b[ $k ];
		}
	 
		return $c;
	}
}