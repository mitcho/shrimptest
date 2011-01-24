<?php
/**
 * ShrimpTest Model class file
 *
 * @author mitcho (Michael Yoshitaka Erlewine) <mitcho@mitcho.com>, Automattic
 * @package ShrimpTest
 */

/**
 * ShrimpTest Model class
 *
 * Implements the default ShrimpTest "Model". "Model" is model in the MVC sense, so it essentially is used to access the experiment store.
 *
 * @package ShrimpTest
 */
class ShrimpTest_Model {

	/**
	 * Reference to the global {@link ShrimpTest} instance
	 * @var ShrimpTest
	 */
	var $shrimp;
	
	/**
	 * Prefix for all ShrimpTest database tables.
	 *
	 * Includes $wpdb->prefix
	 *
	 * @var string
	 */
	var $db_prefix;

	/**
	 * Array of registered variant types and their arguments
	 * @var array
	 */
	var $variant_types = array();
	/**
	 * Array of registered metric types and their arguments
	 * @var array
	 */
	var $metric_types = array();
	
	/**
	 * Timeout value, in seconds, for experiment stats cache.
	 *
	 * By default, 60 minutes (3600). Updatable via the shrimptest_stats_timeout filter.
	 * Also the timeout used to setup the stats-generating WP Cron process.
	 *
	 * @var int
	 */
	var $stats_timeout;
	/**
	 * A prefix for the experiment stats transient name
	 *
	 * By default, 'shrimptest_stats_'. Updatable via the shrimptest_stats_transient filter.
	 *
	 * @var string
	 */
	var $stats_transient;
	
	/**
	 * Dummy constructor
	 *
	 * Hint: run {@link init()}
	 */
	function ShrimpTest_Model( ) {
	}

	/**
	 * Initialization
	 *
	 * @param ShrimpTest
	 * @global wpdb
	 * @uses 
	 * @filter shrimptest_db_prefix
	 * @filter shrimptest_stats_timeout
	 * @filter shrimptest_stats_transient
	 */
	function init( &$shrimptest_instance ) {
		global $wpdb;
		$this->shrimp = &$shrimptest_instance;
		$this->db_prefix = apply_filters( 'shrimptest_db_prefix', "{$wpdb->prefix}shrimptest_" );
		$this->stats_timeout = apply_filters( 'shrimptest_stats_timeout', 60 * 60 );
		$this->stats_transient = apply_filters( 'shrimptest_stats_transient', 'shrimptest_stats_' );
	}
	
	/**#@+
	 * AGGREGATE EXPERIMENT FUNCTIONS<br/>
	 */
	/**
	 * Get an array of active experiments
	 *
	 * @uses get_experiments()
	 * @return array
	 */
	function get_active_experiments( ) {
		return $this->get_experiments( array('status'=>'active') );
	}
	
	/**
	 * Get an array of experiments, using the parameters provided.
	 *
	 * Possible parameters: <ul>
	 * <li>status, default ''</li>
	 * <li>orderby, default 'experiment_id'</li>
	 * <li>order, default 'ASC'</li>
	 * </ul>
	 *
	 * @param array
	 * @global wpdb
	 * @return array
	 */
	function get_experiments( $args = array( ) ) {
		global $wpdb;
		$defaults = array(
			'status' => '',
			'orderby' => 'experiment_id',
			'order' => 'ASC',
		);
		$r = wp_parse_args( $args, $defaults );
		
		$sql = 
		"select experiment_id, name as experiment_name, variants_type, metric_name, metric_type, status, 
		unix_timestamp(start_time) as start_time, unix_timestamp(end_time) as end_time, unix_timestamp(now()) as now, data
		from {$this->db_prefix}experiments
		where 1";
		
		if ( !empty( $r['experiment_id'] ) )
			$sql .= " and experiment_id = {$r['experiment_id']}";
		
		if ( !empty( $r['status'] ) ) {
			if ( is_array( $r['status'] ) )
				$status = $r['status'];
			else
				$status = array( $r['status'] );
			$sql .= " and status in ('".implode("','",$status)."')";
		}
		
		$sql .= " order by {$r['orderby']} {$r['order']}";

		$experiments = $wpdb->get_results( $sql );
		foreach ($experiments as $key => $experiment) {
			if ( isset( $experiments[$key]->data ) )
				$experiments[$key]->data = unserialize( $experiments[$key]->data );
		}

		return $experiments;
	}
	/**#@-*/
	
	/**#@+
	 * INDIVIDUAL EXPERIMENT FUNCTIONS<br/>
	 *
	 */
	/**
	 * Get a reserved experiment id
	 *
	 * This is used to create a new "reserved" experiment and return that experiment
	 * id. This can then be used to take the user to the "add new experiment" screen.
	 *
	 * @global wpdb
	 * @return int
	 */
	function get_reserved_experiment_id( ) {
		global $wpdb;
		$wpdb->query( "insert into `{$this->db_prefix}experiments` (`status`) values ('reserved')" );
		return $wpdb->insert_id;
	}

	/**
	 * Get an experiment's specification
	 *
	 * Returns all the data from the experiments table, with the "data" column, if
	 * available, unserialized into an array. All associated variants from the
	 * experiments_variants table will also be put in the "variants" entry as
	 * an array.
	 *
	 * @global wpdb
	 * @param int
	 * @uses get_experiment_variants()
	 * @return array
	 */
	function get_experiment( $experiment_id ) {
		global $wpdb;
		$experiment = $wpdb->get_row( "select * from {$this->db_prefix}experiments where experiment_id = {$experiment_id}" );
		if (is_null($experiment))
			return false;

		if ( isset( $experiment->data ) )
			$experiment->data = unserialize( $experiment->data );
		$experiment->variants = $this->get_experiment_variants( $experiment_id, ARRAY_A );
		return $experiment;
	}
	
	/**
	 * Update an experiment's specification
	 *
	 * Update the experiments table's entry for the given experiment id,
	 * putting some pre-specified parameters into columns, and everything else
	 * gets serialized into the "data" column. Information on variants and a new
	 * status string can also be specified in the experiment data, but will
	 * simply be passed on to {@link update_experiment_variants()} and
	 * {@link update_experiment_status()}
	 *
	 * The given experiment data is first merged with the current experiment
	 * data so that no data is lost, using {@link array_replace_recursive()}.
	 *
	 * @global wpdb
	 * @param int
	 * @use get_experiment()
	 * @uses update_experiment_variants()
	 * @uses update_experiment_status()
	 * @todo make sure we have enough information before making the status "inactive"
	 * @param array|object
	 */
	function update_experiment( $experiment_id, $experiment_data ) {
		global $wpdb;

		$experiment_data = (array) $experiment_data;
		if ( !isset( $experiment_data['data'] ) ) {
			extract( $experiment_data );
			unset( $experiment_data['metric_name'], $experiment_data['metric_type'], $experiment_data['name'], $experiment_data['variants_type'], $experiment_data['experiment_id'], $experiment_data['variants'] );
			// everything else goes in "data"
			$data = $experiment_data;

			// all of this gets wrapped up in to experiment_data
			$experiment_data = compact('metric_name','metric_type','name','variants_type','data','variants');
			unset( $data );
		}
		$old_experiment = (array) $this->get_experiment( $experiment_id );
		$experiment_data = array_replace_recursive( $old_experiment, $experiment_data );
		
		extract( $experiment_data );

		// data validation
		if ( !isset( $experiment_data['data']['ifnull'] )
		    || !isset( $experiment_data['data']['nullvalue'] )
		    || !isset( $experiment_data['data']['direction'] ) )
			wp_die( 'Metric data must include <code>ifnull</code>, <code>nullvalue</code>, and <code>direction</code> values.' );

		extract( $experiment_data );
		unset( $experiment_data['metric_name'], $experiment_data['metric_type'], $experiment_data['name'], $experiment_data['variants_type'], $experiment_data['experiment_id'] );
		// put everything else into a serialized string.
		$data = '';
		if ( !empty( $experiment_data['data'] ) )
			$data = serialize( $experiment_data['data'] );

		// update shrimptest_experimensts
		$wpdb->query( $wpdb->prepare( "update {$this->db_prefix}experiments "
																	. "set name = %s, variants_type = %s, metric_name = %s, metric_type = %s, data = %s"
																	. "where experiment_id = %d",
																	$name, $variants_type, $metric_name, $metric_type, $data, $experiment_id ) );

		// update shrimptest_experiments_variants
		$this->update_experiment_variants( $experiment_id, $variants );
		
		$this->update_experiment_status( $experiment_id, 'inactive' );
	}
	
	/**
	 * Delete the specified experiment, if the status will allow.
	 *
	 * Will delete data not only from the experiments data, but will also delete
	 * associated data from other tables.
	 *
	 * @param int
	 * @param bool
	 * @global wpdb
	 * @return int the number of rows that were deleted
	 */
	function delete_experiment( $experiment_id, $force = false ) {
		global $wpdb;
		
		if ( !$force && $this->get_experiment_status( $experiment_id ) == 'active' )
			wp_die( sprintf( "Experiment %d cannot be deleted as it is currently active.", $experiment_id ) );
		
		$wpdb->query( $wpdb->prepare( 
			"delete from {$this->db_prefix}experiments_variants where `experiment_id` = %d", 
			$experiment_id ) );
		$wpdb->query( $wpdb->prepare( 
			"delete from {$this->db_prefix}visitors_variants where `experiment_id` = %d", 
			$experiment_id ) );
		$wpdb->query( $wpdb->prepare( 
			"delete from {$this->db_prefix}visitors_metrics where `experiment_id` = %d", 
			$experiment_id ) );

		$deleted = $wpdb->query( $wpdb->prepare( 
			"delete from {$this->db_prefix}experiments where `experiment_id` = %d", 
			$experiment_id ) );

		return $deleted;
	}
	
	/**
	 * Get the status string for the specified experiment.
	 *
	 * @param int
	 * @global wpdb
	 * @return string
	 */
	function get_experiment_status( $experiment_id ) {
		global $wpdb;
		return $wpdb->get_var( "select status from {$this->db_prefix}experiments where experiment_id = {$experiment_id}" );
	}

	/**
	 * Update the specified experiment's status code.
	 *
	 * Will set the start_time when setting to "active", and the end_time when
	 * setting to "finished".
	 *
	 * @global wpdb
	 * @param int
	 * @param string
	 * @action shrimptest_update_experiment_status
	 */
	function update_experiment_status( $experiment_id, $status ) {
		global $wpdb;
		$data = array( 'status' => $status );
		if ( $status == 'active' )
			$data = array( 'status' => $status, 'start_time' => date('Y-m-d H:i:s') );
		if ( $status == 'finished' )
			$data = array( 'status' => $status, 'end_time' => date('Y-m-d H:i:s') );
		$where = compact( 'experiment_id' );

		$wpdb->update( "{$this->db_prefix}experiments", $data, $where, '%s', '%d' );

		do_action( 'shrimptest_update_experiment_status', $experiment_id, $status );
	}

	/**
	 * Update the variant type of the specified experiment
	 *
	 * @global wpdb
	 * @param int
	 * @param string
	 * @action shrimptest_update_variants_type
	 */
	function update_variants_type( $experiment_id, $variants_type ) {
		global $wpdb;
		$data = compact( 'variants_type' );
		$where = compact( 'experiment_id' );
		$wpdb->update( "{$this->db_prefix}experiments", $data, $where, '%s', '%d' );
		do_action( 'shrimptest_update_variants_type', $experiment_id, $variants_type );
	}
	
	/**
	 * Get the computed stats for the specified experiment
	 *
	 * Will try to pull the stats from the stats cache (which uses the WordPress
	 * Transients API), but will recreate it if it has expired or if
	 * {@link $force} is set.
	 *
	 * The return value is organized as follows: for an experiment with X variants,
	 * there will be X+2 entries. Entries 0..(X-1) will have statistics for each
	 * of the variants. There is also an entry called "total". The variant and total
	 * statistics entries are themselves arrays, with the following entries:
	 * <ul>
	 * <li>variant_name</li>
	 * <li>N, the number of participants in the experiment</li>
	 * <li>avg, the average value of the metric</li>
	 * <li>sum, the aggregate total value of the metric</li>
	 * <li>sd, the variance of the metric, in standard deviations</li>
	 * </ul>
	 * In addition, the X-1 variant entries which are not the control (entries
	 * 1..(X-1)) will have "zscore" and "p" entries, which are the z-score and
	 * p-value of the variant, when compared to the control.
	 *
	 * Finally, the return value also has a "stats" entry which contains the
	 * following properties:
	 * <ul>
	 * <li>unix, human, two representations for the time when this stat was
	 * computed</li>
	 * <li>time, how long this experiment calculation took</li>
	 * <li>duration_reached, true if the experiment has specified an experiment
	 * duration and it has been reached.</li>
	 * </ul>
	 *
	 * If the experiment specification specified an experiment duration, and it
	 * has been met with this experiment stats computation, the action
	 * shrimptest_experiment_duration_reached will be triggered, and the
	 * duration_reached property will be added to the experiment data.
	 *
	 * @global wpdb
	 * @param int
	 * @param bool
	 * @return array see description for specification
	 * @uses zscore()
	 * @uses normal_cdf()
	 * @uses update_experiment()
	 * @filter shrimptest_get_stats_value_*
	 * @action shrimptest_experiment_duration_reached
	 * @filter shrimptest_experiment_stats
	 * @todo support metric types whose "type" is not "better"
	 */
	function get_experiment_stats( $experiment_id, $force = false ) {
		global $wpdb;

		if ( !$force ) {
			$cached_stats = get_transient( $this->stats_transient . $experiment_id );
			if ( $cached_stats !== false ) {
				$cached_stats['stats']['cached'] = true;
				return $cached_stats;
			}
		}

		timer_start();

		$experiment = $this->get_experiment( $experiment_id );
		
		$value = "value";
		if ( $experiment->data['ifnull'] )
			$value = "ifnull(value,{$experiment->data['nullvalue']})";
		if ( $experiment->data['direction'] == 'larger' )
			$value = "max({$value})";
		else
			$value = "min({$value})";
			
		$value = apply_filters( 'shrimptest_get_stats_value_' . $experiment->metric_type, $value );
		
		$unique = "if(cookies = 1, v.visitor_id, concat(ip,user_agent))";
	
		$uvsql = "SELECT vv.experiment_id, variant_id, count(distinct variant_id) as variant_count, {$value} as value, {$unique} as unique_visitor_id"
		       . " FROM `{$this->db_prefix}visitors_variants` as vv "
		       . " join `{$this->db_prefix}visitors` as v using (`visitor_id`)"
		       . " left join `{$this->db_prefix}visitors_metrics` as vm"
		       . " on (vm.visitor_id = vv.visitor_id and vm.experiment_id = vv.experiment_id)"
		       . " where vv.experiment_id = {$experiment_id}"
		       . " group by unique_visitor_id"
		       . " having variant_count = 1";
		// note: having variant_count = 1 ensures that we throw out invalid experiments
		$total_sql = "select count(unique_visitor_id) as N, avg(value) as avg, sum(value) as sum, stddev(value) as sd from ({$uvsql}) as uv";

		$stats = array();
		$stats['total'] = $wpdb->get_row( $total_sql );
		
		$stats['total']->assignment_weight = $wpdb->get_var( $wpdb->prepare( "select sum(assignment_weight) from {$this->db_prefix}experiments_variants where experiment_id = %d", $experiment_id ) );
		
		$variant_sql = "select ev.variant_id, variant_name, assignment_weight, count(unique_visitor_id) as N, avg(value) as avg, sum(value) as sum, stddev(value) as sd from {$this->db_prefix}experiments_variants as ev left join ({$uvsql}) as uv on (ev.experiment_id = uv.experiment_id and ev.variant_id = uv.variant_id) where ev.experiment_id = {$experiment_id} group by variant_id order by variant_id asc";
		$variant_stats = $wpdb->get_results( $variant_sql );
		foreach ( $variant_stats as $variant ) {
			$variant->zscore = $this->zscore( $stats['total'], $variant );
			if ( $variant->zscore !== null ) {
				$variant->type = 'better';
				$variant->p = $this->normal_cdf($variant->zscore,$variant->type == 'better');
			}
			$stats[$variant->variant_id] = $variant;
		}
		
		if ( function_exists('date_default_timezone_set') )
			date_default_timezone_set('UTC');
		$stats['stats'] = array( 'unix' => time(),
														 'human' => date('F j, Y, g:i:s a'),
														 'time' => timer_stop() );
		
		if ( isset( $experiment->data['duration'] ) && $experiment->data['duration'] ) {
			$duration_reached = ( isset( $experiment->data['duration_reached'] ) && $experiment->data['duration_reached'] );
			if ( !$duration_reached && ( (int) $stats['total']->N ) >= ( (int) $experiment->data['duration'] ) ) {
				// the experiment duration has been reached!
				$duration_reached = true;
				$experiment->data['duration_reached'] = true;
				$this->update_experiment( $experiment->experiment_id, $experiment );
				do_action( 'shrimptest_experiment_duration_reached', $stats, $experiment );
			}
			$stats['duration_reached'] = $duration_reached;
		}

		$stats = apply_filters( 'shrimptest_experiment_stats', $stats, $experiment );
		
		$cache_timeout = $this->stats_timeout;
		if ( isset( $experiment->data['cache_timeout'] ) )
			$cache_timeout = $experiment->data['cache_timeout'];
		// if the experiment is inactive, the cache can be forever!
		if ( $experiment->status != 'active')
			$cache_timeout = 0; // a timeout of 0 means it will never expire
		set_transient($this->stats_transient . $experiment_id, $stats, $cache_timeout);
		
		return $stats;
	}
	/**#@-*/
	
	/**#@+
	 * EXPERIMENT VARIANT FUNCTIONS<br/>
	 */
	/**
	 * Get a list of variants for the given experiment
	 *
	 * @global wpdb
	 * @param int
	 * @param wpdb_result_type
	 * @return array
	 */
	function get_experiment_variants( $experiment_id, $type = OBJECT ) {
		global $wpdb;
		$results = $wpdb->get_results( "select variant_id, variant_name, assignment_weight, data 
																from `{$this->db_prefix}experiments_variants`
																where `experiment_id` = {$experiment_id}", $type );
		foreach ( $results as $key => $variant ) {
			if ( $type == OBJECT && isset( $results[$key]->data ) )
				$results[$key]->data = unserialize( $results[$key]->data );
			if ( $type == ARRAY_A && isset( $results[$key]['data'] ) )
				$results[$key]['data'] = unserialize( $results[$key]['data'] );
		}
		return $results;
	}
	
	/**
	 * Get a particular experiment variant and its specification
	 *
	 * @global wpdb
	 * @param int
	 * @param int
	 * @return array
	 */
	function get_experiment_variant( $experiment_id, $variant_id = 0 ) {
		global $wpdb;
		if ( !$variant_id ) // the default (control) is 0
			$variant_id = 0;
		$variant = $wpdb->get_row( "select variant_id, variant_name, assignment_weight, data 
																from `{$this->db_prefix}experiments_variants`
																where `experiment_id` = {$experiment_id} and `variant_id` = {$variant_id}" );
		if ( isset( $variant->data ) )
			$variant->data = unserialize( $variant->data );
		return $variant;
	}
	
	/**
	 * Delete an experiment variant
	 *
	 * @global wpdb
	 * @param int
	 * @param int
	 */
	function delete_experiment_variant( $experiment_id, $variant_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "delete from {$this->db_prefix}experiments_variants where where `experiment_id` = %d and `variant_id` = %d", $experiment_id, $variant_id ) );
	}
	
	/**
	 * Update an experiment variant
	 *
	 * @global wpdb
	 * @param int
	 * @param int
	 * @param array|object
	 * @uses array_replace_recursive()
	 */
	function update_experiment_variant( $experiment_id, $variant_id, $variant_data ) {
		global $wpdb;
		
		$old_variant = (array) $this->get_experiment_variant( $experiment_id, $variant_id );

		if ( isset( $variant_data['name'] ) ) {
			$variant_data['variant_name'] = $variant_data['name'];
			unset( $variant_data['name'] );
		}
		if ( !isset( $variant_data['data'] ) ) {
			extract( $variant_data );
			unset( $variant_data['variant_name'], $variant_data['assignment_weight'] );
			$data = $variant_data;
			// variant_data should be the compacting of name, assignment_weight, data
			$variant_data = compact('variant_name', 'assignment_weight', 'data');
			unset( $data );
		}
		
		$variant_data = array_replace_recursive( $old_variant, $variant_data );

		extract( $variant_data );
		if ( !isset( $variant_name ) || !isset( $assignment_weight ) || empty( $assignment_weight ) )
			wp_die( 'The variant must have a <code>name</code> and a non-zero <code>assignment_weight</code>' );

		$data = '';
		if ( isset( $variant_data['data'] ) )
			$data = serialize( $variant_data['data'] );
		$wpdb->query( $wpdb->prepare( "insert into {$this->db_prefix}experiments_variants "
																	. "(experiment_id, variant_id, variant_name, assignment_weight, data) "
																	. "values (%d, %d, %s, %d, %s) "
																	. "on duplicate key update variant_name = %s, assignment_weight = %d, data = %s",
																	$experiment_id, $variant_id, $variant_name, (int) $assignment_weight, $data,
																	$variant_name, (int) $assignment_weight, $data ) );
	}
	
	/**
	 * Update multiple variants of an experiment
	 *
	 * @global wpdb
	 * @param int
	 * @param array variants specifications, keyed by variant id
	 * @uses update_experiment_variant()
	 * @todo rewrite this to decrease the number of queries that are called.
	 */
	function update_experiment_variants( $experiment_id, $variants ) {
		global $wpdb;
		// let's first order the variants and make sure there are no skipped ID's.
		ksort( $variants );
		$variants = array_values( $variants );

		foreach ( $variants as $variant_id => $variant_data )
			$this->update_experiment_variant( $experiment_id, $variant_id, $variant_data );

		$variant_count = count( $variants );
		// delete extra (probably old) variants:
		$wpdb->query( $wpdb->prepare( "delete from {$this->db_prefix}experiments_variants "
																	. "where experiment_id = %d and variant_id >= %d",
																	$experiment_id, $variant_count ) );
	}
	/**#@-*/
	
	/**
	 * Update a metric value for the given visitor
	 *
	 * @global wpdb
	 * @param int
	 * @param float
	 * @param boolean if true, will only update if the value is greater (optional)
	 * @param int
	 * @todo validate metric id and/or $value, but note, we've already touched in 
	 *   {@link ShrimpTest::update_visitor_metric()}
	 */ 
	function update_visitor_metric( $experiment_id, $value, $monotonic, $visitor_id ) {
		global $wpdb;
		
		$sql = "insert into `{$this->db_prefix}visitors_metrics`
						  (`visitor_id`, `experiment_id`, `value`)
						  values ({$visitor_id}, {$experiment_id}, {$value})
						on duplicate key update `value` = "
						. ( $monotonic ? "greatest({$value},value)" : $value );

		return $wpdb->query( $sql );
	}

	/**
	 * Get the variant specification for the given experiment and visitor so that
	 * we can use it
	 *
	 * @global wpdb
	 * @param int
	 * @param int
	 * @uses w_rand()
	 * @uses get_visitor_variant()
	 * @uses ShrimpTest::touch_experiment()
	 * @return array
	 */
	function get_visitor_variant( $experiment_id, $visitor_id ) {
		global $wpdb;
			
		// if the experiment is not turned on, use the control.
		$status = $this->get_experiment_status( $experiment_id );
		if ( $status != 'active' ) {
			$this->shrimp->touch_experiment( $experiment_id, array( 'variant' => null ) );
			return null;
		}

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
		
		$this->shrimp->touch_experiment( $experiment_id, array( 'variant' => $variant ) );
		return $variant;
	}

	/**
	 * Get an array of variant type objects with some basic metadata, for editing.
	 *
	 * Used by the ShrimpTest UI, so variants which are _programmatic will be
	 * disabled, unless they are turned on, for example.
	 *
	 * @param string
	 * @uses sort_by_defaultness()
	 * @filter shrimptest_get_variant_types_to_edit
	 * @return array
	 */
	function get_variant_types_to_edit( $current_type = null ) {
		$types = array();
		$locked = false;
		if ( isset($this->variant_types[ $current_type ]->_programmatic)
				    && $this->variant_types[ $current_type ]->_programmatic )
			$locked = true;
		foreach ( $this->variant_types as $variant ) {
			$types[ $variant->name ] = (object) array( 'label' => $variant->label );
			if ( isset($variant->_programmatic) && $variant->_programmatic )
				$types[ $variant->name ]->disabled = true;
			if ( $current_type == $variant->name ) {
				$types[ $variant->name ]->selected = true;
				$types[ $variant->name ]->disabled = false; // the selected option should not be disabled.
			} else if ( $locked )
				$types[ $variant->name ]->disabled = true;
		}
		uasort( $types, array( $this, 'sort_by_defaultness' ) );		
		apply_filters( 'shrimptest_get_variant_types_to_edit', $types, $current_type );
		return $types;
	}

	/**
	 * Return a "display-friendly" string of a given metric value, based on the
	 * metric that it is.
	 * 
	 * For example, if the metric is counting currency, the function may take 
	 * 5 and return '$5'.
	 *
	 * @param string
	 * @param float
	 * @param float
	 * @filter shrimptest_display_metric_*_value
	 * @return string
	 */
	function display_metric_value( $metric_name, $value, $raw = null ) {
		if ( is_numeric( $value ) && !is_int( $value ) )
			$value = round( $value, 4 );
		return apply_filters( 'shrimptest_display_metric_'.$metric_name.'_value', $value, $value, $raw );
	}
	
	/**
	 * Get an array of metric type objects with some basic metadata, for editing.
	 *
	 * Used by the ShrimpTest UI, so metrics which are _programmatic will be
	 * disabled, unless they are turned on, for example.
	 *
	 * @param string
	 * @uses sort_by_defaultness()
	 * @filter shrimptest_get_metric_types_to_edit
	 * @return array
	 */
	function get_metric_types_to_edit( $current_type = null ) {
		$types = array();
		$locked = false;
		if ( isset($this->metric_types[ $current_type ]->_programmatic) 
				    && $this->metric_types[ $current_type ]->_programmatic )
			$locked = true;
		foreach ( $this->metric_types as $metric ) {
			$types[ $metric->name ] = (object) array( 'label' => $metric->label );
			if ( isset($metric->_programmatic) && $metric->_programmatic )
				$types[ $metric->name ]->disabled = true;
			if ( $current_type == $metric->name ) {
				$types[ $metric->name ]->selected = true;
				$types[ $metric->name ]->disabled = false; // the selected option should not be disabled.
			} else if ( $locked )
				$types[ $metric->name ]->disabled = true;
		}
		uasort( $types, array( $this, 'sort_by_defaultness' ) );
		apply_filters( 'shrimptest_get_metric_types_to_edit', $types, $current_type );
		return $types;
	}
	
	/**#@+
	 * HELPER FUNCTIONS<br/>
	 */
	/**
	 * Sort by "defaultness"
	 *
	 * A sorting function which will prefer to order objects which have the _default
	 * property further forward.
	 *
	 * @param object
	 * @param object
	 * @return int
	 */
	function sort_by_defaultness( $a, $b ) {
		if ( isset($a->_default) && $a->_default && !$b->_default )
			return -1;
		if ( isset($b->_default) && $b->_default && !$a->_default )
			return 1;
		if ( isset($a->_default) && isset($b->_default) )
			return gmp_cmp($a->_default, $b->_default);
		if ( isset($a->name) && isset($b->name) )
			return strcmp( $a->name, $b->name );
		return 1;
	}
	
	/**
	 * Take an associated array with numerical values and returns a weighted-random key
	 *
	 * @link http://20bits.com/articles/random-weighted-elements-in-php/
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
	 
	/**
	 * Compute a z-score based on the control's stats and the variant's stats
	 *
	 * @param array
	 * @param array
	 * @return float
	 */
	function zscore( $control, $variant ) {
		if ( isset( $control ) && $variant->N && $control->N && ( $variant->sd || $control->sd ) )
			return ( $variant->avg - $control->avg ) / sqrt( (pow($variant->sd, 2) / ($variant->N)) + (pow($control->sd, 2) / ($control->N)) );
		else
			return null;
	}

	/**
	 * Implements a normal culmulative distribution function (CDF), the integral of
	 * the probability density function.
	 *
	 * {@link $type} can be 'worse', 'better', or 'different'.
	 *
	 * @param float
	 * @param string which type of integral we want to compute
	 * return float
	 */
	function normal_cdf( $z, $type = 'different' ) {

		// first, compute the single- (right-)tailed area:
		// \int_{z}^{+\infty} Norm(x) dx
		$absz = abs($z);

		// coefficients
		$a1 = 0.0000053830;
		$a2 = 0.0000488906;
		$a3 = 0.0000380036;
		$a4 = 0.0032776263;
		$a5 = 0.0211410061;
		$a6 = 0.0498673470;

		$right_tail = pow(((((($a1*$absz+$a2)*$absz+$a3)*$absz+$a4)*$absz+$a5)*$absz+$a6)*$absz+1,-16) / 2;
		if ( $z < 0 )
			$right_tail = 1 - $right_tail;
		
		switch ( $type ) {
			case 'worse':
				return $right_tail;
			case 'better':
				return 1 - $right_tail;
			case 'different':
				return abs(1 - 2 * $right_tail);
		}
	}
	/**#@-*/
	
} // class ShrimpTest_Model

/**
 * Register a custom variant type
 *
 * The variant type specification will be stored within
 * {@link ShrimpTest_Model::variant_types}, keyed by the variant type {@link $name}.
 * If {@link $args} is a class name, an instance of that class will be initialized 
 * and used.
 * 
 * @param string
 * @param object|array|classname
 * @link http://shrimptest.com/docs/variant-and-metric-api/
 */
function register_shrimptest_variant_type( $name, $args ) {
	global $shrimp;

	if ( !isset( $shrimp->model ) )
		wp_die( 'The ShrimpTest Model has not yet loaded!' );

	if ( !is_array( $shrimp->model->variant_types ) )
		$shrimp->model->variant_types = array();

	if ( array_search( $name, array_keys( $shrimp->model->variant_types ) ) )
		wp_die( sprintf( "The variant type code <code>%s</code> has already been registered.", $name ) );

	// Args prefixed with an underscore are reserved for internal use.
	$defaults = array(
		'labels' => array(),
		'description' => '',
		'_default' => false
	);
	
	if ( !is_object( $args ) ) {
		if ( is_array( $args ) )
			$args = (object) $args;
		else if ( class_exists( $args ) )
			$args = new $args( &$shrimp );
		else
			wp_die( "Could not load the variant type {$name}." );
	}
	
	foreach ( $defaults as $key => $value ) {
		if ( !isset( $args->{$key} ) )
			$args->{$key} = $value;
	}
	
	$args->name = $name;

	$shrimp->model->variant_types[$name] = $args;
}

/**
 * Register a custom metric type
 *
 * The variant type specification will be stored within
 * {@link ShrimpTest_Model::metric_types}, keyed by the metric type {@link $name}.
 * If {@link $args} is a class name, an instance of that class will be initialized 
 * and used.
 * 
 * @param string
 * @param object|array|classname
 * @link http://shrimptest.com/docs/variant-and-metric-api/
 */
function register_shrimptest_metric_type( $name, $args ) {
	global $shrimp;

	if ( !isset( $shrimp->model ) )
		wp_die( 'The ShrimpTest Model has not yet loaded!' );

	if ( !is_array( $shrimp->model->metric_types ) )
		$shrimp->model->metric_types = array();

	if ( array_search( $name, array_keys( $shrimp->model->metric_types ) ) )
		wp_die( sprintf( "The metric type code <code>%s</code> has already been registered.", $name ) );

	// Args prefixed with an underscore are reserved for internal use.
	$defaults = array(
		'labels' => array(),
		'description' => '',
		'_default' => false
	);
	
	if ( !is_object( $args ) ) {
		if ( is_array( $args ) )
			$args = (object) $args;
		else if ( class_exists( $args ) )
			$args = new $args( &$shrimp );
		else
			wp_die( "Could not load the metric type {$name}." );
	}
	
	foreach ( $defaults as $key => $value ) {
		if ( !isset( $args->{$key} ) )
			$args->{$key} = $value;
	}
	
	$args->name = $name;

	$shrimp->model->metric_types[$name] = $args;
}

/**
 * Implements array_replace_recursive() for PHP <5.3.0.
 *
 * @link http://us3.php.net/array_replace_recursive
 * @uses array_replace_recursive_recurse()
 * @param array
 * @param array
 * @return array
 */
if ( !function_exists('array_replace_recursive') ) {
  function array_replace_recursive($array, $array1) {
		if ( !function_exists('array_replace_recursive_recurse') ) {
			/**
			 * Helper function for {@link array_replace_recursive()}
			 * @param array
			 * @param array
			 * @return array
			 */
			function array_replace_recursive_recurse($array, $array1) {
				foreach ($array1 as $key => $value) {
					// create new key in $array, if it is empty or not an array
					if (!isset($array[$key]) || (isset($array[$key]) && !is_array($array[$key]))) {
						$array[$key] = array();
					}
	 
					// overwrite the value in the base array
					if (is_array($value)) {
						$value = array_replace_recursive_recurse($array[$key], $value);
					}
					$array[$key] = $value;
				}
				return $array;
			}
    }
 
    // handle the arguments, merge one by one
    $args = func_get_args();
    $array = $args[0];
    if (!is_array($array))
    {
      return $array;
    }
    for ($i = 1; $i < count($args); $i++)
    {
      if (is_array($args[$i]))
      {
        $array = array_replace_recursive_recurse($array, $args[$i]);
      }
    }
    return $array;
  }
}