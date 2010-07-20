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
	
	function ShrimpTest_Model( ) {
		// Hint: run init( )
	}

	function init( &$shrimptest_instance ) {
		global $wpdb;
		$this->shrimp = &$shrimptest_instance;
		$this->db_prefix = apply_filters( 'shrimptest_db_prefix', "{$wpdb->prefix}shrimptest_" );
		$this->metric_types = array( (object) array( 'code' => 'manual', 'name' => 'Manual (PHP required)' ) );
		$this->variant_types = array( (object) array( 'code' => 'manual', 'name' => 'Manual (PHP required)' ) );
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
		
		$sql .= " order by {$r['orderby']} {$r['order']}";

		return $wpdb->get_results( $sql );
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

		$metric = $wpdb->get_var( 
			$wpdb->prepare( "select metric_id from {$this->db_prefix}experiments where `experiment_id` = %d", 
			$experiment_id ) );

		$deleted = $wpdb->query( $wpdb->prepare( 
			"delete from {$this->db_prefix}experiments where `experiment_id` = %d", 
			$experiment_id ) );

		if ( !is_null( $metric ) )
			$this->delete_metric( $metric );
			
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
	}

	function update_variants_type( $experiment_id, $variants_type ) {
		global $wpdb;
		$data = compact( 'variants_type' );
		$where = compact( 'experiment_id' );
		$wpdb->update( "{$this->db_prefix}experiments", $data, $where, '%s', '%d' );		
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
	
	function get_experiment_stats( $experiment_id ) {
		global $wpdb;

		$metric_id = $this->get_metric_id( $experiment_id );
		$metric = $this->get_metric( $metric_id );
		
		$value = "value";

		if ( $metric->data['ifnull'] )
			$value = "ifnull(value,{$metric->data['nullvalue']})";

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
	
	function zscore( $control, $variant ) {
		if ( isset( $control ) && $variant->N && $control->N && ( $variant->sd || $control->sd ) )
			return ( $variant->avg - $control->avg ) / sqrt( (pow($variant->sd, 2) / ($variant->N)) + (pow($control->sd, 2) / ($control->N)) );
		else
			return null;
	}
	
	// CDF = culmulative distribution function, the integral of the probability density function (PDF)
	function normal_cdf( $z, $type = 'middle' ) {

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
			case 'right':
				return $right_tail;
			case 'left':
				return 1 - $right_tail;
			case 'middle':
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
	
	function delete_metric( $metric_id, $force = false ) {
		global $wpdb;

		// metric_id 0 is just a placeholder
		if ( $metric_id == 0 )
			return;

		if ( !$force ) {
			$experiments = $wpdb->get_var( $wpdb->prepare( 
				"select group_concat(experiment_id) from {$this->db_prefix}experiments where metric_id = %d",
				$metric_id) );
			if ( $experiments )
				wp_die( sprintf( "Metric %d cannot be deleted as it is currently in use. (Experiments: %s)",
				$metric_id, $experiments ) );
		}

		$wpdb->query( $wpdb->prepare( 
			"delete from {$this->db_prefix}visitors_metrics where `metric_id` = %d", 
			$metric_id ) );

		return $wpdb->query( $wpdb->prepare( 
			"delete from {$this->db_prefix}metrics where `metric_id` = %d", 
			$metric_id ) );
		
	}
	
	function create_metric() {
/*		$wpdb->query( "insert into `{$this->db_prefix}metrics`
							(`visitor_id`,`experiment_id`,`variant_id`)
							values ({$visitor_id},{$experiment_id},{$variant})" );*/
	}

	/*
	 * update_visitor_metric
	 * @param int     $metric_id
	 * @param float   $value
	 * @param boolean $monotonic - if true, will only update if the value is greater (optional)
	 * @param int     $visitor_id
	 */ 
	function update_visitor_metric( $metric_id, $value, $monotonic, $visitor_id ) {
		global $wpdb;

		// TODO: validate metric id and/or $value
		// but note, we've already touched in ShrimpTest->update_visitor_metric
		
		$sql = "insert into `{$this->db_prefix}visitors_metrics`
						  (`visitor_id`, `metric_id`, `value`)
						  values ({$visitor_id}, {$metric_id}, {$value})
						on duplicate key update `value` = "
						. ( $monotonic ? "greatest({$value},value)" : $value );

		return $wpdb->query( $sql );
	}

	// NOTE: getting the value of a metric for an individual visitor...
	// I wrote it, but does this really have a use case?
	function get_visitor_metric( $metric_id, $visitor_id = false ) {
		global $wpdb;

		if ( !$visitor_id )
			$visitor_id = $this->shrimp->visitor_id;
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
		foreach ( $this->variant_types as $variant ) {
			$types[ $variant->code ] = (object) array( 'name' => $variant->name );
			if ( $current_type == $variant->code )
				$types[ $variant->code ]->selected = true;
		}
		apply_filters( 'shrimptest_get_variant_types_to_edit', $types, $current_type );
		return $types;
	}

	function display_metric_value( $metric_code, $value ) {
		foreach ( $this->metric_types as $metric ) {
			if ( $metric->code != $metric_code )
				continue;
			if ( $metric->display_value )
				return $metric->display_value( $value );
		}
		if ( is_numeric( $value ) && !is_int( $value ) )
			return round( $value, 4 );
		return $value;
	}
	
	function get_metric_types_to_edit( $current_type = null ) {
		$types = array();
		foreach ( $this->metric_types as $metric ) {
			$types[ $metric->code ] = (object) array( 'name' => $metric->name );
			if ( $current_type == $metric->code )
				$types[ $metric->code ]->selected = true;
		}
		uasort( $types, array( $this, 'sort_by_defaultness' ) );
		apply_filters( 'shrimptest_get_metric_types_to_edit', $types, $current_type );
		return $types;
	}

	function sort_by_defaultness( $a, $b ) {
		if ( $a->_default && !$b->_default )
			return -1;
		if ( $b->_default && !$a->_default )
			return 1;
		return strcmp( $a->code, $b->code );
	}
	
} // class ShrimpTest_Model
