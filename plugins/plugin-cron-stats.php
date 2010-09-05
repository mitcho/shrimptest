<?php
/**
 * ShrimpTest Cron stats plugin
 *
 * This plugin schedules the automatic, periodic computation of experiment stats.
 *
 * @author mitcho (Michael Yoshitaka Erlewine) <mitcho@mitcho.com>, Automattic
 * @package ShrimpTest
 * @subpackage ShrimpTest_Plugin_Cron_Stats
 */

/**
 * string option key for the cron interval preference
 */
define('SHRIMPTEST_CRON_INTERVALS','shrimptest_plugin_cron_intervals');

add_action( 'shrimptest_update_experiment_status', 'shrimptest_plugin_cron_setup', 10, 2 );
/**
 * Set up a cron process for periodically computing stats
 *
 * Requests the action shrimptest_calculate_stats to be triggered periodically.
 *
 * @global ShrimpTest
 * @param int
 * @param string
 * @action shrimptest_calculate_stats
 * @uses ShrimpTest_Model::get_experiment()
 * @uses ShrimpTest_Model::$stats_timeout
 * @uses ShrimpTest_Model::get_experiment_stats()
 */
function shrimptest_plugin_cron_setup( $experiment_id, $status ) {
	global $shrimp;
	if ( $status == 'active' ) { // turn it on!
		$experiment = $shrimp->model->get_experiment( $experiment_id );

		// calculate the cache timeout for this experiment
		$cache_timeout = $shrimp->model->stats_timeout;
		if ( isset( $experiment->data['cache_timeout'] ) )
			$cache_timeout = $experiment->data['cache_timeout'];
		
		// register this cache timeout with the global option, used to filter the cron schedules.
		$experiment_intervals = get_option( SHRIMPTEST_CRON_INTERVALS );
		if ( !$experiment_intervals )
			$experiment_intervals = array();
		$experiment_intervals["experiment_{$experiment_id}_interval"] 
			= array( 'interval' => $cache_timeout,
			         'display' => "Once every {$cache_timeout} seconds");
		update_option( SHRIMPTEST_CRON_INTERVALS, $experiment_intervals );
		
		// schedule the recurring event
		wp_schedule_event( time(), "experiment_{$experiment_id}_interval", 
		                   'shrimptest_calculate_stats',
		                   array( 'experiment_id'=>$experiment_id ) );
	} else { // make sure to calculate it one last time and then turn it off!
		$shrimp->model->get_experiment_stats( $experiment_id, true );
		wp_clear_scheduled_hook( 'shrimptest_calculate_stats',
		                         array( 'experiment_id'=>$experiment_id ) );
	}
}

add_filter( 'cron_schedules', 'shrimptest_plugin_cron_filter_schedules' );
/**
 * Filter the cron intervals, using the option to implement our custom
 * time interval
 *
 * @param array
 * @return array
 */
function shrimptest_plugin_cron_filter_schedules( $schedules ) {
	$experiment_intervals = get_option( SHRIMPTEST_CRON_INTERVALS );
	if ( !$experiment_intervals )
		$experiment_intervals = array();
	return array_merge( $schedules, $experiment_intervals );
}

add_action( 'shrimptest_calculate_stats', 'shrimptest_plugin_cron_do_stats', 10, 1 );
/**
 * Compute the stats when triggered.
 *
 * Registered against the shrimptest_calculate_stats action.
 *
 * @global ShrimpTest
 * @param array
 * @uses ShrimpTest_Model::get_experiment_stats()
 */
function shrimptest_plugin_cron_do_stats( $args ) {
	global $shrimp;
	$experiment_id = $args['experiment_id'];
	if ( !$experiment_id )
		return;
	$shrimp->model->get_experiment_stats( $experiment_id, true );
}