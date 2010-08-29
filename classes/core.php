<?php

/*
 * class ShrimpTest
 */

class ShrimpTest {

	// some constants based on WordPress install:
	var $cookie_domain;
	var $cookie_path;
	
	// should be configurable:
	var $cookie_name;
	var $cookie_dough;
	var $cookie_days;

	// versioning:	
	var $db_version = 28; // change to force database schema update
	
	// variables to track information about/throughout the current execution
	var $visitor_id;
	var $visitor_cookie;
	var $touched_experiments;
	var $touched_metrics;
	var $override_variants;
		
	// references to the Interface and Model objects
	var $interface = null;
	var $model = null;

	function ShrimpTest( ) {
		// Hint: run init( ) to get the party started.
	}

	function init( ) {
		global $wpdb;
		
		// Let other plugins modify various options
		$this->cookie_domain = apply_filters( 'shrimptest_cookie_domain', COOKIE_DOMAIN );
		$this->cookie_path   = apply_filters( 'shrimptest_cookie_path', COOKIEPATH );
		$this->cookie_name   = apply_filters( 'shrimptest_cookie_name', 'ebisen' );
		$this->cookie_dough	 = COOKIEHASH;
		$this->cookie_days   = apply_filters( 'shrimptest_cookie_days', 365 );

		$this->load_model_and_interface( );
		$this->load_default_metric_and_variant( );
		$this->load_plugins( );

		add_action( 'init', array( &$this, 'versioning' ) );
		add_action( 'init', array( &$this, 'check_cookie' ) );

		add_action( 'wp_footer', array( &$this, 'print_foot' ) );
		add_action( 'wp_ajax_shrimptest_record', array( &$this, 'record_cookieability' ) );
		add_action( 'wp_ajax_nopriv_shrimptest_record', array( &$this, 'record_cookieability' ) );

		add_action( 'wp_ajax_shrimptest_override_variant', array( &$this, 'override_variant' ) );
		
		do_action( 'shrimptest_init', &$this );
		
	}
	
	function load_model_and_interface( ) {
		// load all the available classes
		foreach ( glob( SHRIMPTEST_DIR . '/classes/*.php' ) as $class ) {
			require_once $class;
		}
		
		if ( !defined( 'SHRIMPTEST_MODEL_CLASS' ) )
			define( 'SHRIMPTEST_MODEL_CLASS', 'ShrimpTest_Model' );
		$shrimptest_model_class = SHRIMPTEST_MODEL_CLASS;
		$shrimp_model = new $shrimptest_model_class( );
		$shrimp_model->init($this);
		$this->model = &$shrimp_model;
		
		if ( !defined( 'SHRIMPTEST_INTERFACE_CLASS' ) )
			define( 'SHRIMPTEST_INTERFACE_CLASS', 'ShrimpTest_Interface' );
		$shrimptest_interface_class = SHRIMPTEST_INTERFACE_CLASS;
		$shrimp_interface = new $shrimptest_interface_class( );
		$shrimp_interface->init($this);
		$this->interface = &$shrimp_interface;
		$shrimp_interface->model = &$shrimp_model; // Interface is given a reference to Model
	}
	
	function load_default_metric_and_variant( ) {
		register_shrimptest_metric_type( 'manual', array( 'label' => 'Manual (PHP required)' ) );
		register_shrimptest_variant_type( 'manual', array( 'label' => 'Manual (PHP required)' ) );
	}
	
	function load_plugins( ) {
		foreach ( glob( SHRIMPTEST_DIR . '/plugins/*.php' ) as $plugin )
			include_once $plugin;
	}
	
	/*
	 * check_cookie
	 */	
	function check_cookie( ) {
		global $wpdb;

		$this->visitor_id = $this->visitor_cookie = null;

		// check if the current user is exempt, in which case they'll get a null visitor_id
		if ( $this->exempt_visitor( ) )
			return;

		// check if this visitor is one where we don't need to activate ShrimpTest, or if the 
		// user agent is on a blacklist (implemented through plugin-blocklist now)
		$user_agent = $_SERVER['HTTP_USER_AGENT'];
		if ( $this->blocked_visit( $user_agent ) ) {
			return;
		}

		// if there's a cookie...
		if ( isset( $_COOKIE[$this->cookie_name] ) ) {
			$this->visitor_cookie = $_COOKIE[$this->cookie_name];
			
			// verify that it's actually registered with us, by getting its visitor_id.
			$sql = "select visitor_id, cookies from {$this->model->db_prefix}visitors where cookie = X'{$this->visitor_cookie}'";
			$this->visitor_id = $wpdb->get_var( $sql, 0 );
			
			// if cookie valid but visitor is marked as not having cookie support, correct that.
			if ( $this->visitor_id && $wpdb->get_var( $sql, 1 ) == 0 ) {
				$wpdb->query( "update `{$this->model->db_prefix}visitors` 
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
			$keepgoing = $wpdb->get_var( "select id from `{$this->model->db_prefix}visitors` where cookie = X'{$cookie}'" );
		} while ( $keepgoing );
		
		$success = setcookie( $this->cookie_name, $cookie, time() + 60*60*24*$this->cookie_days, $this->cookie_path, $this->cookie_domain );
		
		if ( $success ) {
			$user_agent = $_SERVER['HTTP_USER_AGENT'];
			$ip = $_SERVER['REMOTE_ADDR'];
			$wpdb->query( "insert into `{$this->model->db_prefix}visitors` (`cookie`,`user_agent`,`ip`) values (X'{$cookie}','{$user_agent}',inet_aton('{$ip}'))" );
			$this->visitor_id = $wpdb->insert_id;
			$this->visitor_cookie = $cookie;
			return $this->visitor_id;
		} else {
			// TODO: error handling? Cookie couldn't be set.
			return false;
		}		
	}

	function blocked_visit( $user_agent = false ) {

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
	
		if ( !$user_agent )
			return;
	
		return apply_filters( 'shrimptest_blocked_visit', false, $user_agent );
	}
	
	function exempt_visitor( ) {
		$exempt = false;
		if ( is_user_logged_in( ) )
			$exempt = true;
		$exempt = apply_filters( 'shrimptest_exempt_visitor', $exempt );
		return $exempt;
	}

	function get_visitor_variant( $experiment_id, $visitor_id = false ) {
		global $wpdb;

		if ( defined('WP_ADMIN') && WP_ADMIN )
			return null;

		// If the user is exempt (like a logged in admin), check if they've overridden the variant.
		// If not, it will return null for control.
		if ( $this->exempt_visitor( ) ) {
			$variant = (int) $this->get_override_variant( $experiment_id );
			$this->touch_experiment( $experiment_id, array( 'variant' => $variant ) );
			return $variant;
		}

		if ( !$visitor_id )
			$visitor_id = $this->visitor_id;
		if ( is_null( $visitor_id ) )
			return null;

		return $this->model->get_visitor_variant( $experiment_id, $visitor_id );
	}
	
	/*
	 * update_visitor_metric
	 * @param int     $experiment_id
	 * @param float   $value
	 * @param boolean $monotonic - if true, will only update if the value is greater (optional)
	 * @param int     $visitor_id
	 */ 
	function update_visitor_metric( $experiment_id, $value, $monotonic = false, $visitor_id = false ) {
		global $wpdb;

		// if the user is exempt (like a logged in admin), return control.
		if ( $this->exempt_visitor( ) ) {
			$this->touch_metric( $experiment_id, array( 'value' => null ) );
			return null;
		}

		if ( !$visitor_id )
			$visitor_id = $this->visitor_id;
		if ( is_null( $visitor_id ) )
			return null;

		$this->touch_metric( $experiment_id, array( 'value' => $value ) );
		
		return $this->model->update_visitor_metric( $experiment_id, $value, $monotonic, $visitor_id );
	}

	function get_override_variant( $experiment_id ) {
		global $user_ID;
		get_currentuserinfo();
		if ( !isset( $this->override_variants ) )
			$this->override_variants = get_user_meta( $user_ID, "shrimptest_override_variants", true );

		if ( isset( $this->override_variants[$experiment_id] ) )
			return (int) $this->override_variants[$experiment_id];
		else
			return 0; // control
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
		$this->touched_experiments[$experiment_id] = array_merge( $this->touched_experiments[$experiment_id], $args );
	}
	function get_touched_experiments( ) {
		return apply_filters( 'shrimptest_touched_experiments', $this->touched_experiments );
	}
	/*
	 * touch_metric: like touch_experiment, but for metrics
	 */
	function touch_metric( $experiment_id, $args ) {
		if ( !is_array( $this->touched_metrics ) )
			$this->touched_metrics = array();
		$this->touched_metrics = array_merge( $this->touched_metrics, array( $experiment_id => $args ) );
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

// Disabled so that we still get the footer in cached versions, even if the first user's js status
// has been recorded.
// TODO: only disable this if there's caching going on.
//	if ( $this->exempt_visitor( ) )
//		return;

//	// if we already know that they have JS, no need to record again.
//		if ( $wpdb->get_var( "select js from `{$this->db_prefix}visitors` where visitor_id = {$this->visitor_id}" ) )
//			return;

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
	
	function get_interface_slug( ) {
		return $this->interface ? $this->interface->slug : false;
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
						"CREATE TABLE {$this->model->db_prefix}visitors (
							visitor_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
							cookie BINARY(16) NOT NULL UNIQUE KEY ,
							user_agent VARCHAR(255) NOT NULL ,
							ip INT UNSIGNED NULL ,
							js BOOL NOT NULL DEFAULT 0 ,
							cookies BOOL NOT NULL DEFAULT 0 ,
							localstorage BOOL NOT NULL DEFAULT 0 ,
							timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
						);",
						// TODO: question: should experiments just be a custom post type?
						"CREATE TABLE {$this->model->db_prefix}experiments (
							experiment_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
							name VARCHAR(255) NOT NULL ,
							status varchar(30) default 'inactive' ,
							variants_type VARCHAR(255) default 'manual',
							metric_name VARCHAR(255) NOT NULL ,
							metric_type VARCHAR(255) default 'manual',
							start_time TIMESTAMP NULL ,
							end_time TIMESTAMP NULL ,
							data LONGTEXT NULL,
							timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
						);",
						"CREATE TABLE {$this->model->db_prefix}experiments_variants (
							experiment_id INT UNSIGNED NOT NULL ,
							variant_id INT UNSIGNED NOT NULL DEFAULT 0
								COMMENT 'variant 0 is always \"control\"',
							assignment_weight FLOAT UNSIGNED NOT NULL DEFAULT 1 ,
							variant_name VARCHAR( 255 ) NOT NULL ,
							data LONGTEXT NULL,
							PRIMARY KEY (experiment_id,variant_id)
						);",
						"CREATE TABLE {$this->model->db_prefix}visitors_variants (
							visitor_id BIGINT(20) NOT NULL ,
							experiment_id INT NOT NULL ,
							variant_id INT NOT NULL ,
							timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
						);",
						"CREATE TABLE {$this->model->db_prefix}visitors_metrics (
							visitor_id INT NOT NULL ,
							experiment_id INT UNSIGNED NOT NULL ,
							value FLOAT NOT NULL ,
							timestamp TIMESTAMP NOT NULL
								DEFAULT CURRENT_TIMESTAMP
								ON UPDATE CURRENT_TIMESTAMP ,
							PRIMARY KEY ( visitor_id , experiment_id )
						);");
		$dbSql = apply_filters( 'shrimptest_dbdelta_sql', $dbSql );
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