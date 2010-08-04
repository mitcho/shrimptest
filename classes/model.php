<?php

/*
 * class ShrimpTest_Model
 * Implements the default ShrimpTest "Model"
 * "Model" is model in the MVC sense, so it essentially is used to access the experiment store.
 */
class ShrimpTest_Model {

	var $shrimp;
	var $db_prefix;

	var $variant_types = array();
	var $metric_types = array();
	
	var $stats_timeout;
	var $stats_transient; // a prefix for the transient name
	
	function ShrimpTest_Model( ) {
		// Hint: run init( )
	}

	function init( &$shrimptest_instance ) {
		global $wpdb;
		$this->shrimp = &$shrimptest_instance;
		$this->db_prefix = apply_filters( 'shrimptest_db_prefix', "{$wpdb->prefix}shrimptest_" );

		// stats cache timeout: also the timeout used to setup the stats-generating WP Cron process
		// default: 1 hour
		$this->stats_timeout = apply_filters( 'shrimptest_stats_timeout', 60 * 60 );
		$this->stats_transient = apply_filters( 'shrimptest_stats_transient', 'shrimptest_stats_' );
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

	/*
	 * INDIVIDUAL EXPERIMENT FUNCTIONS
	 */

	function get_reserved_experiment_id( ) {
		global $wpdb;
		$wpdb->query( "insert into `{$this->db_prefix}experiments` (`status`) values ('reserved')" );
		return $wpdb->insert_id;
	}

	function get_experiment( $experiment_id ) {
		global $wpdb;
		$experiment = $wpdb->get_row( "select * from {$this->db_prefix}experiments where experiment_id = {$experiment_id}" );
		if ( isset( $experiment->data ) )
			$experiment->data = unserialize( $experiment->data );
		return $experiment;
	}
	
	function update_experiment( $experiment_id, $experiment_data ) {
		global $wpdb;

		// data validation
		if ( !isset( $experiment_data['ifnull'] ) || !isset( $experiment_data['nullvalue'] )
		                                          || !isset( $experiment_data['direction'] ) )
			wp_die( 'Metric data must include <code>ifnull</code>, <code>nullvalue</code>, and <code>direction</code> values.' );
			
		extract( $experiment_data );
		// put everything else into a serialized string.
		unset( $experiment_data['metric_name'], $experiment_data['metric_type'], $experiment_data['name'], $experiment_data['variants_type'], $experiment_data['experiment_id'] );
		$data = '';
		if ( !empty( $experiment_data ) )
			$data = serialize( $experiment_data );

		// update shrimptest_experimensts
		$wpdb->query( $wpdb->prepare( "update {$this->db_prefix}experiments "
																	. "set name = %s, variants_type = %s, metric_name = %s, metric_type = %s, data = %s"
																	. "where experiment_id = %d",
																	$name, $variants_type, $metric_name, $metric_type, $data, $experiment_id ) );

		// update shrimptest_experiments_variants		
		$this->update_experiment_variants( $experiment_id, $variants );
		
		if ( true ) // TODO: if enough information
			$this->update_experiment_status( $experiment_id, 'inactive' );
	}
	
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
	
	function get_experiment_status( $experiment_id ) {
		global $wpdb;
		return $wpdb->get_var( "select status from {$this->db_prefix}experiments where experiment_id = {$experiment_id}" );
	}

	function update_experiment_status( $experiment_id, $status ) {
		global $wpdb;
		$data = compact( 'status' );
		$where = compact( 'experiment_id' );
		$wpdb->update( "{$this->db_prefix}experiments", $data, $where, '%s', '%d' );		
		do_action( 'shrimptest_update_experiment_status', $experiment_id, $status );
	}

	function update_variants_type( $experiment_id, $variants_type ) {
		global $wpdb;
		$data = compact( 'variants_type' );
		$where = compact( 'experiment_id' );
		$wpdb->update( "{$this->db_prefix}experiments", $data, $where, '%s', '%d' );
		do_action( 'shrimptest_update_variants_type', $experiment_id, $variants_type );
	}
		
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
		// note: having variant_count = 1 ensures that we throw out 
		$total_sql = "select count(unique_visitor_id) as N, avg(value) as avg, sum(value) as sum, stddev(value) as sd from ({$uvsql}) as uv";

		$stats = array();
		$stats['total'] = $wpdb->get_row( $total_sql );
		
		$stats['total']->assignment_weight = $wpdb->get_var( $wpdb->prepare( "select sum(assignment_weight) from {$this->db_prefix}experiments_variants where experiment_id = %d", $experiment_id ) );
		
		$variant_sql = "select ev.variant_id, variant_name, assignment_weight, count(unique_visitor_id) as N, avg(value) as avg, sum(value) as sum, stddev(value) as sd from {$this->db_prefix}experiments_variants as ev left join ({$uvsql}) as uv on (ev.experiment_id = uv.experiment_id and ev.variant_id = uv.variant_id) where ev.experiment_id = {$experiment_id} group by variant_id order by variant_id asc";
		$variant_stats = $wpdb->get_results( $variant_sql );
		foreach ( $variant_stats as $variant ) {
			$variant->zscore = $this->zscore( $stats['total'], $variant );
			if ( $variant->zscore !== null ) {
				$variant->type = 'better'; // TODO: "better", "different", "worse"
				$variant->p = $this->normal_cdf($variant->zscore,$variant->type == 'better');
			}
			$stats[$variant->variant_id] = $variant;
		}
		
		if ( function_exists('date_default_timezone_set') )
			date_default_timezone_set('UTC');
		$stats['stats'] = array( 'unix' => time(),
														 'human' => date('F j, Y, g:i:s a'),
														 'time' => timer_stop() );
		
		$stats = apply_filters( 'shrimptest_experiment_stats', $stats, $experiment_id );
		
		$cache_timeout = $this->stats_timeout;
		if ( isset( $experiment->data['cache_timeout'] ) )
			$cache_timeout = $experiment->data['cache_timeout'];
		set_transient($this->stats_transient . $experiment_id, $stats, $cache_timeout);
		
		return $stats;
	}
	
	function zscore( $control, $variant ) {
		if ( isset( $control ) && $variant->N && $control->N && ( $variant->sd || $control->sd ) )
			return ( $variant->avg - $control->avg ) / sqrt( (pow($variant->sd, 2) / ($variant->N)) + (pow($control->sd, 2) / ($control->N)) );
		else
			return null;
	}
	
	// CDF = culmulative distribution function, the integral of the probability density function (PDF)
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
		$wpdb->query( $wpdb->prepare( "delete from {$this->db_prefix}experiments_variants where where `experiment_id` = %d and `variant_id` = %d", $experiment_id, $variant_id ) );
	}
	
	function update_experiment_variant( $experiment_id, $variant_id, $variant_data ) {
		global $wpdb;

		extract( $variant_data );
		if ( !isset( $name ) || !isset( $assignment_weight ) || empty( $assignment_weight ) )
			wp_die( 'The variant must have a <code>name</code> and a non-zero <code>assignment_weight</code>' );
		
		unset( $variant_data['name'], $variant_data['assignment_weight'] );

		if ( empty( $variant_data ) ) {
			// if the data is empty, don't overwrite it!
			$wpdb->query( $wpdb->prepare( "insert into {$this->db_prefix}experiments_variants "
																		. "(experiment_id, variant_id, variant_name, assignment_weight) "
																		. "values (%d, %d, %s, %d) "
																		. "on duplicate key update variant_name = %s, assignment_weight = %d",
																		$experiment_id, $variant_id, $name, (int) $assignment_weight,
																		$name, (int) $assignment_weight ) );
		} else {
			$data = serialize( $variant_data );
			$wpdb->query( $wpdb->prepare( "insert into {$this->db_prefix}experiments_variants "
																		. "(experiment_id, variant_id, variant_name, assignment_weight, data) "
																		. "values (%d, %d, %s, %d, %s) "
																		. "on duplicate key update variant_name = %s, assignment_weight = %d, data = %s",
																		$experiment_id, $variant_id, $name, (int) $assignment_weight, $data,
																		$name, (int) $assignment_weight, $data ) );
		}
	}
	
	function update_experiment_variants( $experiment_id, $variants ) {
		global $wpdb;
		// let's first order the variants and make sure there are no skipped ID's.
		ksort( $variants );
		$variants = array_values( $variants );

		// TODO: rewrite this to decrease the number of queries that are called.
		foreach ( $variants as $variant_id => $variant_data )
			$this->update_experiment_variant( $experiment_id, $variant_id, $variant_data );

		$variant_count = count( $variants );
		// delete extra (probably old) variants:
		$wpdb->query( $wpdb->prepare( "delete from {$this->db_prefix}experiments_variants "
																	. "where experiment_id = %d and variant_id >= %d",
																	$experiment_id, $variant_count ) );
	}
		
	/*
	 * update_visitor_metric
	 * @param int     $metric_id
	 * @param float   $value
	 * @param boolean $monotonic - if true, will only update if the value is greater (optional)
	 * @param int     $visitor_id
	 */ 
	function update_visitor_metric( $experiment_id, $value, $monotonic, $visitor_id ) {
		global $wpdb;

		// TODO: validate metric id and/or $value
		// but note, we've already touched in ShrimpTest->update_visitor_metric
		
		$sql = "insert into `{$this->db_prefix}visitors_metrics`
						  (`visitor_id`, `experiment_id`, `value`)
						  values ({$visitor_id}, {$experiment_id}, {$value})
						on duplicate key update `value` = "
						. ( $monotonic ? "greatest({$value},value)" : $value );

		return $wpdb->query( $sql );
	}

	/*
	 * get_visitor_variant: get the variant for the given experiment and visitor
	 *
	 * @uses w_rand
	 */
	function get_visitor_variant( $experiment_id, $visitor_id ) {
		global $wpdb;
			
		// if the experiment is not turned on, use the control.
		if ( $this->get_experiment_status( $experiment_id ) != 'active' ) {
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
			if ( $current_type == $variant->name )
				$types[ $variant->name ]->selected = true;
			else if ( $locked )
				$types[ $variant->name ]->disabled = true;
		}
		uasort( $types, array( $this, 'sort_by_defaultness' ) );		
		apply_filters( 'shrimptest_get_variant_types_to_edit', $types, $current_type );
		return $types;
	}

	function display_metric_value( $metric_name, $value, $raw = null ) {
		if ( is_numeric( $value ) && !is_int( $value ) )
			$value = round( $value, 4 );
		return apply_filters( 'shrimptest_display_metric_'.$metric_name.'_value', $value, $value, $raw );
	}
	
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
			if ( $current_type == $metric->name )
				$types[ $metric->name ]->selected = true;
			else if ( $locked )
				$types[ $metric->name ]->disabled = true;
		}
		uasort( $types, array( $this, 'sort_by_defaultness' ) );
		apply_filters( 'shrimptest_get_metric_types_to_edit', $types, $current_type );
		return $types;
	}

	function sort_by_defaultness( $a, $b ) {
		if ( isset($a->_default) && $a->_default && !$b->_default )
			return -1;
		if ( isset($b->_default) && $b->_default && !$a->_default )
			return 1;
		if ( isset($a->name) && isset($b->name) )
			return strcmp( $a->name, $b->name );
		return 1;
	}
	
} // class ShrimpTest_Model


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

