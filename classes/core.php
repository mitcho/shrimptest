<?php
/**
 * ShrimpTest Core class file
 *
 * @author mitcho (Michael Yoshitaka Erlewine) <mitcho@mitcho.com>, Automattic
 * @package ShrimpTest
 */

/**
 * ShrimpTest Core class
 *
 * The core ShrimpTest controller class. One global instance of this class is
 * created, as {@link $shrimp}.
 *
 * @package ShrimpTest
 */
class ShrimpTest {

	/**#@+
	 * Some cookie environment constants based on WordPress install
	 * @var string
	 */
	var $cookie_domain;
	var $cookie_path;
	/**#@-*/
	
	/**
	 * By default, set to "ebisen"
	 * @var string
	 */
	var $cookie_name;
	/**
	 * A random hash used to produce the random cookie values
	 * @var string
	 */
	var $cookie_dough;
	/**
	 * How long ShrimpTest cookies should live for
	 * @var int
	 */
	var $cookie_days;

	/**
	 * Database schema version: change to force database schema update
	 * @var int
	 */
	var $db_version = 28;
	
	/**#@+
	 * Variables to track information about/throughout the current execution
	 */
	/**
	 * @var int
	 */
	var $visitor_id;
	/**
	 * @var string
	 */
	var $visitor_cookie;
	/**#@-*/
	
	/**
	 * An array of user-settable options which can be modified in the settings
	 * @var options
	 */
	var $options = array();
	
	/**
	 * A collection of information on the experiments which were "touched"
	 * during this execution.
	 * @var array
	 */
	var $touched_experiments;
	/**
	 * A collection of information on the metrics which were "touched"
	 * during this execution.
	 * @var array
	 */
	var $touched_metrics;
	/**
	 * An array which maps experiments to overridden variants, for when the
	 * logged-in user has used the variant preview to override the variant they
	 * are viewing.
	 * @var array
	 */
	var $override_variants;
		
	/**
	 * reference to the {@link ShrimpTest_Interface} instance
	 * @var ShrimpTest_Interface
	 */
	var $interface = null;
	/**
	 * reference to the {@link ShrimpTest_Model} instance
	 * @var ShrimpTest_Model
	 */
	var $model = null;


	/**
	 * {@link ShrimpTest} constructor
	 *
	 * Hint: run {@link init()} to get the party started.
	 */
	function ShrimpTest( ) {
	}

	/**
	 * The actual initialization function
	 *
	 * Initializes all the internal cookie settings, calls
	 * {@link load_model_and_interface()}, {@link load_default_metric_and_variant()},
	 * {@link load_plugins()}, and then registers a number of actions.
	 *
	 * Must be called separately, after the constructor.
	 *
	 * @filter shrimptest_cookie_domain
	 * @filter shrimptest_cookie_path
	 * @filter shrimptest_cookie_name
	 * @filter shrimptest_cookie_days
	 * @action shrimptest_init
	 */
	function init( ) {
		
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
	
	/**
	 * Initialize a {@link ShrimpTest_Model} as {@link $model} and
	 * {@link ShrimpTest_Interface} as {@link $interface}
	 *
	 * The {@link SHRIMPTEST_MODEL_CLASS} and {@link SHRIMPTEST_INTERFACE_CLASS}
	 * constants are used as the model and interface class names, respectively.
	 *
	 * @uses ShrimpTest_Model
	 * @uses ShrimpTest_Interface
	 * @uses ShrimpTest_Model::init()
	 * @uses ShrimpTest_Interface::init()
	 */
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
	
	/**
	 * Load the default metric and variant types: the "manual" type
	 *
	 * @uses register_shrimptest_metric_type()
	 * @uses register_shrimptest_variant_type()
	 */
	function load_default_metric_and_variant( ) {
		register_shrimptest_metric_type( 'manual', array( 'label' => 'Manual (PHP required)' ) );
		register_shrimptest_variant_type( 'manual', array( 'label' => 'Manual (PHP required)' ) );
	}

	/**
	 * Load all files in the /plugins directory
	 */	
	function load_plugins( ) {
		foreach ( glob( SHRIMPTEST_DIR . '/plugins/*.php' ) as $plugin )
			include_once $plugin;
	}
	
	/**
	 * Check to see if the user has a valid ShrimpTest cookie. If not, calls
	 * {@link set_cookie()}.
	 *
	 * This will only happen if the user is not "exempt", as determined by
	 * {@link exempt_visitor()}.
	 *
	 * @global wpdb
	 * @uses exempt_visitor()
	 * @uses blocked_visit()
	 * @uses set_cookie()
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

	/**
	 * Sets the cookie and returns the visitor id
	 *
	 * @global wpdb
	 * @return int
	 * @todo consider fallback for when the browser does not have cookies set.
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

	/**
	 * Check whether this visitor/visit should be "blocked" from the testing pool.
	 * 
	 * A visit is blocked if it's an AJAX or XMLRPC call, if we're in wp-admin,
	 * or if the shrimptest_blocked_visit filter returns true.
	 * 
	 * @param string user agent string
	 * @filter shrimptest_blocked_visit
	 * @return bool
	 */
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
	
	/**
	 * Check whether the visitor/visit is exempt from ShrimpTest behavior.
	 *
	 * A user is exempt if they're logged in or if the shrimptest_exempt_visitor
	 * filter returns true.
	 *
	 * @filter shrimptest_exempt_visitor
	 * @uses is_user_logged_in()
	 * @return bool
	 */
	function exempt_visitor( ) {
		$exempt = false;
		if ( is_user_logged_in( ) )
			$exempt = true;
		$exempt = apply_filters( 'shrimptest_exempt_visitor', $exempt );
		return $exempt;
	}

	/**
	 * Get the current visitor's variant id for a particular experiment id
	 *
	 * This function will return a variant id for the current (or given) visitor
	 * and the given experiment, first checking if the user has an "override" set.
	 * It will only work if the visitor is not exempt.
	 *
	 * Returns null if unavailable.
	 *
	 * @global wpdb
	 * @param int
	 * @param int
	 * @uses exempt_visitor()
	 * @uses get_override_variant()
	 * @uses touch_experiment()
	 * @uses ShrimpTest_Model::get_visitor_variant()
	 * @return int
	 */
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
	
	/**
	 * Update a goal metric's value for the current (or given user)
	 *
	 * Returns the updated value.
	 *
	 * @global wpdb
	 * @param int
	 * @param float
	 * @param boolean if true, will only update if the value is greater (optional)
	 * @param int
	 * @uses exempt_visitor()
	 * @uses touch_metric()
	 * @uses ShrimpTest_Model::update_visitor_metric()
	 * @return float
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

	/**
	 * Get the current user's "override" variant id for a particular experiment.
	 *
	 * A variant is "overridden" when the variant preview feature is used.
	 *
	 * @global int
	 * @param int
	 * @uses override_variants()
	 * @return int
	 */
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

	/**
	 * "Touch" an experiment, with the given arguments
	 *
	 * This function is used to keep track of what experiments were accessed ("touched") througout
	 * the printing of the current page. This information is not normally printed, but is used to
	 * produce the ShrimpTest bar (or ShrimpTest component of the Admin Bar) when an admin is
	 * logged in.
	 *
	 * @param int
	 * @param array
	 */
	function touch_experiment( $experiment_id, $args ) {
		if ( !isset($this->touched_experiments[$experiment_id]) || !is_array( $this->touched_experiments[$experiment_id] ) )
			$this->touched_experiments[$experiment_id] = array();
		$this->touched_experiments[$experiment_id] = array_merge( $this->touched_experiments[$experiment_id], $args );
	}
	
	/**
	 * Get the array of experiments which have been "touched" throughout this
	 * execution.
	 *
	 * @filter shrimptest_touched_experiments
	 */
	function get_touched_experiments( ) {
		return apply_filters( 'shrimptest_touched_experiments', $this->touched_experiments );
	}
	
	/**
	 * Like {@link touch_experiment}, but for metrics
	 *
	 * @param int
	 * @param array
	 */
	function touch_metric( $experiment_id, $args ) {
		if ( !is_array( $this->touched_metrics ) )
			$this->touched_metrics = array();
		$this->touched_metrics[$experiment_id] = $args;
	}
	
	/**
	 * Get the array of metrics which have been "touched" throughout this
	 * execution.
	 *
	 * @filter shrimptest_touched_metrics
	 */
	function get_touched_metrics( ) {
		return apply_filters( 'shrimptest_touched_metrics', $this->touched_metrics );
	}

	/**
	 * Check whether we have "touched" any experiments or metrics during execution.
	 *
	 * @return boolean whether any experiments have been touched during this execution
	 */
	function has_been_touched( ) {
		$touched_experiments = $this->get_touched_experiments();
		$touched_metrics = $this->get_touched_metrics();
		return ( !empty( $touched_experiments ) || !empty( $touched_metrics ) );
	}
	
	/**
	 * Prints a script in the footer to support accurate counting of
	 * "unique human visitors".
	 *
	 * @global wpdb
	 * @link http://shrimptest.com/2010/06/05/for-shrimptest-to-produce-accurate-stati/
	 * @todo only disable the code below if there's caching going on.
	 */
	function print_foot( ) {
		global $wpdb;

		/* Disabled so that we still get the footer in cached versions, even if the first user's js status
		has been recorded.
		if ( $this->exempt_visitor( ) )
			return;

		// if we already know that they have JS, no need to record again.
		if ( $wpdb->get_var( "select js from `{$this->db_prefix}visitors` where visitor_id = {$this->visitor_id}" ) )
			return;
		*/

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
	
	/**
	 * Record the "cookieability" of a user who has pinged back
	 *
	 * The script printed by {@link print_foot()} will ping back, if the user has
	 * JavaScript. This ping will then tell us whether the user actually
	 * picked up the cookie we sent them or not. Only users whose cookies
	 * were picked up are used in statistics.
	 *
	 * @link http://shrimptest.com/2010/06/05/for-shrimptest-to-produce-accurate-stati/
	 * @global wpdb
	 */
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

	/**
	 * Records the "override" variant specified in the $_POST request.
	 *
	 * @global int
	 */
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
		else if ( isset( $_REQUEST['referer'] ) )
			wp_redirect( $_REQUEST['referer'] );
		else
			echo "<script type=\"text/javascript\">window.history.back();</script>";
		exit;
	}
	
	/**
	 * Get the $slug variable from the active {@link ShrimpTest_Interface}
	 * @return string
	 */
	function get_interface_slug( ) {
		return $this->interface ? $this->interface->slug : false;
	}
	
	/**
	 * Adds DB versioning support
	 *
	 * Note here I use site_option because ShrimpTest db tables exist for each site.
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

	/**
	 * Make sure that our tables are set up.
	 *
	 * @global wpdb
	 * @filter shrimptest_dbdelta_sql
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

	/**
	 * Prints options UI for the settings screen.
	 * Currently only supports checkbox and text types well.
	 */
	function print_options() {
		foreach ($this->options as $option) {
			echo "<div>";
			if (isset($option['before']))
				echo "<label for='shrimptest[{$option['name']}]'>{$option['before']}</label> ";
			$value = $this->get_option($option['name']);
			echo "<input name='shrimptest[{$option['name']}]' id='shrimptest[{$option['name']}]' type='{$option['name']}' value='$value'>";
			if (isset($option['after']))
				echo " <label for='shrimptest[{$option['name']}]'>{$option['after']}</label>";
			echo "</div>";
		}
	}
	
	function get_option($name) {
		$options = get_option( 'shrimptest' );
		if ( !empty($options) && isset( $options[$name] ))
			return $options[$name];
		return null;
	}

	function set_option($name, $value) {
		$options = get_option( 'shrimptest' );
		$options[$name] = $value;
		set_option( 'shrimptest', $options );
	}

} // class ShrimpTest

/**
 * array_combine, implemented for PHP 4 systems
 *
 * @link http://us.php.net/array_combine
 */
if ( !function_exists( 'array_combine' ) ) {
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