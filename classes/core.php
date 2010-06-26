<?php

/*
 * class ShrimpTest
 */

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
	var $db_version = 16; // change to force database schema update

	// user agent filtering lists to be populated
	var $blocklist;
	var $blockterms;
	
	// variables to track information about/throughout the current execution
	var $visitor_id;
	var $visitor_cookie;
	var $touched_experiments;
	var $touched_metrics;
	var $override_variants;
	
	var $variant_types;
	var $metric_types;

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

		$this->load_plugins( );

		add_action( 'wp_ajax_shrimptest_override_variant', array( &$this, 'override_variant' ) );
		
		do_action( 'shrimptest_init', &$this );
		
	}
	
	function load_plugins( ) {
		$this->metric_types = array();
		$this->variant_types = array();
		foreach ( glob( SHRIMPTEST_DIR . '/plugins/*.php' ) as $plugin ) {
			include_once $plugin;

			$object = new $export_class;

			$object->init( &$this );
			
			if ( stripos( $export_class, 'variant' ) ) {
				if ( array_search( $object->code, array_keys( $this->variant_types ) ) )
					wp_die( sprintf( "The variant type code <code>%s</code> has already been registered.", $code ) );
				$this->variant_types[] = $object;
			}

			if ( stripos( $export_class, 'metric' ) ) {
				if ( array_search( $object->code, array_keys( $this->metric_types ) ) )
					wp_die( sprintf( "The metric type code <code>%s</code> has already been registered.", $code ) );
				$this->metric_types[] = $object;
			}

		}
	}
	
	/*
	 * AGGREGATE EXPERIMENT FUNCTIONS
	 */
	
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
		"select experiment_id, e.name as experiment_name, variants_type, m.name as metric_name, m.type as metric_type, status, 
		unix_timestamp(start_time) as start_time, unix_timestamp(end_time) as end_time, unix_timestamp(now()) as now
		from {$this->db_prefix}experiments as e
		left join {$this->db_prefix}metrics as m using (`metric_id`)
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

	/*
	 * INDIVIDUAL EXPERIMENT FUNCTIONS
	 */

	function get_experiment( $experiment_id ) {
		global $wpdb;
		return $wpdb->get_row( "select * from {$this->db_prefix}experiments where experiment_id = {$experiment_id}" );
	}
	
	function update_experiment( $experiment_id, $experiment_data ) {
		global $wpdb;

		// update shrimptest_experimensts
		extract( $experiment_data );
		$wpdb->query( $wpdb->prepare( "update {$this->db_prefix}experiments "
																	. "set name = %s, variants_type = %s, metric_id = %d "
																	. "where experiment_id = %d",
																	$name, $variants_type, $metric_id, $experiment_id ) );

		// update shrimptest_experiments_variants		
		foreach ( $variants as $variant_id => $variant_data )
			$this->update_experiment_variant( $experiment_id, $variant_id, $variant_data );
		$variant_count = count( $variants );
		$wpdb->query( $wpdb->prepare( "delete from {$this->db_prefix}experiments_variants "
																	. "where experiment_id = %d and variant_id >= %d",
																	$experiment_id, $variant_count ) );
		
		if ( true ) // TODO: if enough information
			$this->update_experiment_status( $experiment_id, 'inactive' );
	}
	
	function get_experiment_status( $experiment_id ) {
		global $wpdb;
		return $wpdb->get_var( "select status from {$this->db_prefix}experiments where experiment_id = {$experiment_id}" );
	}

	function update_experiment_status( $experiment_id, $status ) {
		global $wpdb;
		$data = compact( 'status' );
		$where = compact( 'experiment_id' );
		$wpdb->update( "{$this->db_prefix}experiments", $data, $where, '%s', '%d' );
	}

	function update_variants_type( $experiment_id, $variants_type ) {
		global $wpdb;
		$data = compact( 'variants_type' );
		$where = compact( 'experiment_id' );
		$wpdb->update( "{$this->db_prefix}experiments", $data, $where, '%s', '%d' );		
	}
	
	function get_experiment_stats( $experiment_id ) {
		global $wpdb;

		$metric_type = $wpdb->get_var( "select type from {$this->db_prefix}metrics join {$this->db_prefix}experiments using (`metric_id`) where experiment_id = {$experiment_id}" );
		
		$metric_id = $wpdb->get_var("select metric_id from {$this->db_prefix}experiments where experiment_id = $experiment_id");
		$metric = $this->get_metric( $metric_id );
		
		$value = "value";

		if ( $metric->data['ifnull'] )
			$value = "ifnull(value,{$metric->data[nullvalue]})";

		if ( $metric->data['direction'] == 'larger' )
			$value = "max({$value})";
		else
			$value = "min({$value})";
			
		$value = apply_filters( 'shrimptest_get_stats_value_' . $metric->type, $value );
		
		$unique = "if(cookies = 1, v.visitor_id, concat(ip,user_agent))";
	
		$uvsql = "SELECT experiment_id, variant_id, count(distinct variant_id) as variant_count, {$value} as value, {$unique} as unique_visitor_id"
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
		
		$stats['total']->assignment_weight = $wpdb->get_var( $wpdb->prepare( "select sum(assignment_weight) from {$this->db_prefix}experiments_variants where experiment_id = %d", $experiment_id ) );
		
		$variant_sql = "select ev.variant_id, variant_name, assignment_weight, count(unique_visitor_id) as N, avg(value) as avg, stddev(value) as sd from {$this->db_prefix}experiments_variants as ev left join ({$uvsql}) as uv on (ev.experiment_id = uv.experiment_id and ev.variant_id = uv.variant_id) where ev.experiment_id = {$experiment_id} group by variant_id order by variant_id asc";
		$variant_stats = $wpdb->get_results( $variant_sql );
		foreach ( $variant_stats as $variant ) {
			$stats[$variant->variant_id] = $variant;
		}
		
		return $stats;
	}
	
	/*
	 * EXPERIMENT VARIANT FUNCTIONS
	 */
	
	/*
	 * get_experiment_variants: get a list of variants for the current experiment
	 */
	function get_experiment_variants( $experiment_id ) {
		global $wpdb;
		$results = $wpdb->get_results( "select variant_id, variant_name, assignment_weight, data 
																from `{$this->db_prefix}experiments_variants`
																where `experiment_id` = {$experiment_id}" );
		foreach ( $results as $key => $variant ) {
			if ( isset( $results[$key]->data ) )
				$results[$key]->data = unserialize( $results[$key]->data );
		}
		return $results;
	}
	
	function get_experiment_variant( $experiment_id, $variant_id ) {
		global $wpdb;
		$variant = $wpdb->get_row( "select variant_id, variant_name, assignment_weight, data 
																from `{$this->db_prefix}experiments_variants`
																where `experiment_id` = {$experiment_id} and `variant_id` = {$variant_id}" );
		if ( isset( $variant->data ) )
			$variant->data = unserialize( $variant->data );
		return $variant;
	}
	
	function delete_experiment_variant( $experiment_id, $variant_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "delete from {$this->db_prefix}experiments_variants where where `experiment_id` = %s and `variant_id` = %s", $experiment_id, $variant_id ) );
	}
	
	function update_experiment_variant( $experiment_id, $variant_id, $variant_data ) {
		global $wpdb;

		extract( $variant_data );
		if ( !isset( $name ) || !isset( $assignment_weight ) || empty( $assignment_weight ) )
			wp_die( 'The variant must have a <code>name</code> and a non-zero <code>assignment_weight</code>' );
		
		unset( $variant_data['name'], $variant_data['assignment_weight'] );
		$data = '';
		if ( !empty( $variant_data ) )
			$data = serialize( $variant_data );
		$wpdb->query( $wpdb->prepare( "insert into {$this->db_prefix}experiments_variants "
																	. "(experiment_id, variant_id, variant_name, assignment_weight, data) "
																	. "values (%d, %d, %s, %d, %s) "
																	. "on duplicate key update variant_name = %s, assignment_weight = %d, data = %s",
																	$experiment_id, $variant_id, $name, $assignment_weight, $data,
																	$name, $assignment_weight, $data ) );
	}
	
	/*
	 * AGGREGATE METRIC FUNCTIONS
	 */
	
	function get_metrics( $args = array() ) {
		global $wpdb;
		$defaults = array(
			'type' => '',
			'offset' => 0,
			'orderby' => 'metric_id',
			'order' => 'ASC',
		);
		$r = wp_parse_args( $args, $defaults );
		$sql = "select metric_id, name, type, data, timestamp "
					. "from `{$this->db_prefix}metrics` "
					. "where 1 ";

		if ( !empty( $r['metric_id'] ) ) {
			if ( !is_array( $r['metric_id'] ) )
				$r['metric_id'] = array( $r['metric_id'] );
			$sql .= "and metric_id in ('".join("','",$r['metric_id'])."') ";
		}

		if ( !empty( $r['type'] ) ) {
			if ( !is_array( $r['type'] ) )
				$r['type'] = array( $r['type'] );
			$sql .= "and type in ('".join("','",$r['type'])."') ";
		}

		$metrics = $wpdb->get_results( $sql );
		foreach ( array_keys( $metrics ) as $key ) {
			if ( isset( $metrics[$key]->data ) )
				$metrics[$key]->data = unserialize( $metrics[$key]->data );
		}
		
		return $metrics;
	}
	
	/*
	 * METRIC FUNCTIONS
	 */
	
	function get_metric( $metric_id ) {
		global $wpdb;
		$metric = $wpdb->get_row( "select metric_id, name, type, data, timestamp
																from `{$this->db_prefix}metrics`
																where `metric_id` = {$metric_id}" );
		if ( isset( $metric->data ) )
			$metric->data = unserialize( $metric->data );
		return $metric;
	}
	
	function update_metric( $metric_id, $metric_data ) {
		global $wpdb;

		// data validation
		if ( !isset( $metric_data['ifnull'] ) || !isset( $metric_data['nullvalue'] )
		                                      || !isset( $metric_data['direction'] ) )
			wp_die( 'Metric data must include <code>ifnull</code>, <code>nullvalue</code>, and <code>direction</code> values.' );

		extract( $metric_data );
		unset( $metric_data['name'], $metric_data['type'] );
		$data = '';
		if ( !empty( $metric_data ) )
			$data = serialize( $metric_data );
		$wpdb->query( $wpdb->prepare( "update {$this->db_prefix}metrics "
																	. "set name = %s, type = %s, data = %s "
																	. "where metric_id = %d",
																	$name, $type, $data, $metric_id ) );		
	}
	
	/*
	 * check_cookie
	 */	
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
			$variant = (int) $this->get_override_variant( $experiment_id );
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

		if ($this->override_variants[$experiment_id])
			return (int) $this->override_variants[$experiment_id];
		else
			return 0; // control
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
		if ( !is_array( $this->touched_experiments[$experiment_id] ) )
			$this->touched_experiments[$experiment_id] = array();
		$this->touched_experiments[$experiment_id] = array_merge_recursive( $this->touched_experiments[$experiment_id], $args );
	}
	function get_touched_experiments( ) {
		return apply_filters( 'shrimptest_touched_experiments', $this->touched_experiments );
	}
	/*
	 * touch_metric: like touch_experiment, but for metrics
	 */
	function touch_metric( $metric_id, $args ) {
		if ( !is_array( $this->touched_metrics ) )
			$this->touched_metrics = array();
		$this->touched_metrics = array_merge_recursive( $this->touched_metrics, array( $metric_id => $args ) );
	}
	function get_touched_metrics( ) {
		return apply_filters( 'shrimptest_touched_metrics', $this->touched_metrics );
	}

	/*
	 * has_been_touched
	 *
	 * @return boolean whether any experiments have been touched during this execution
	 */
	function has_been_touched( ) {
		$touched_experiments = $this->get_touched_experiments();
		$touched_metrics = $this->get_touched_metrics();
		return ( !empty( $touched_experiments ) || !empty( $touched_metrics ) );
	}

	function print_foot( ) {
		global $wpdb;

		if ( $this->exempt_user( ) )
			return;

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
		
		$this->override_variants = get_user_meta( $user_ID, "shrimptest_override_variants", true );
		$this->override_variants[$experiment_id] = $variant_id;
		update_user_meta( $user_ID, "shrimptest_override_variants", $this->override_variants );

		if ( isset( $_SERVER['HTTP_REFERER'] ) )
			wp_redirect( $_SERVER['HTTP_REFERER'] );
		else
			echo "<script type=\"text/javascript\">window.history.back();</script>";
		exit;
	}

	function get_reserved_experiment_id( ) {
		global $wpdb;
		$wpdb->query( "insert into `{$this->db_prefix}experiments` (`status`) values ('reserved')" );
		return $wpdb->insert_id;
	}
	
	function get_metric_id( $experiment_id ) {
		global $wpdb;

		// first, try to see if there's a metric already set for this experiment.
		// if so, retreive it.
		if ( isset( $experiment_id ) ) {
			$metric_id = $wpdb->get_var( "select metric_id from {$this->db_prefix}experiments as e
join {$this->db_prefix}metrics as m using (metric_id)
where e.experiment_id = {$experiment_id}" );
			if ( $metric_id !== null )
				return $metric_id;
		}
		
		// create a new metric
		$wpdb->query( "insert into `{$this->db_prefix}metrics` (`type`) values ('manual')" );
		if ( isset( $experiment_id ) )
			$wpdb->query( "update {$this->db_prefix}experiments set metric_id = {$wpdb->insert_id} where experiment_id = {$experiment_id}" );
		return $wpdb->insert_id;
	}
	
	function create_metric() {
/*		$wpdb->query( "insert into `{$this->db_prefix}metrics`
							(`visitor_id`,`experiment_id`,`variant_id`)
							values ({$visitor_id},{$experiment_id},{$variant})" );*/
	}
	
	function get_variant_types_to_edit( $current_type = null ) {
		$types = array( 'manual' => (object) array( 'name' => 'Manual (requires PHP)' ) );
		foreach ( $this->variant_types as $variant ) {
			$types[ $variant->code ] = (object) array( 'name' => $variant->name );
			if ( $current_type == $metric->code )
				$types[ $metric->code ]->selected = true;
		}
		apply_filters( 'shrimptest_get_variant_types_to_edit', $types, $current_type );
		return $types;
	}
	
	function get_metric_types_to_edit( $current_type = null ) {
		$types = array( 'manual' => (object) array( 'name' => 'Manual (requires PHP)' ) );
		foreach ( $this->metric_types as $metric ) {
			$types[ $metric->code ] = (object) array( 'name' => $metric->name );
			if ( $current_type == $metric->code )
				$types[ $metric->code ]->selected = true;
		}
		apply_filters( 'shrimptest_get_metric_types_to_edit', $types, $current_type );
		return $types;
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
							`visitor_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
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
							`variants_type` VARCHAR(255) default 'manual',
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
							`data` LONGTEXT NULL,
							PRIMARY KEY (`experiment_id`,`variant_id`)
						) ENGINE = MYISAM ;",
						"CREATE TABLE `{$this->db_prefix}visitors_variants` (
							`visitor_id` BIGINT(20) NOT NULL ,
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
							`type` VARCHAR( 255 ) NOT NULL default 'conversion',
							`data` LONGTEXT NULL,
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