<?php 
/**
 * @filter shrimptest_save_metric_*
 */

global $wpdb;

$experiment_id = (int) $_REQUEST['id'];

// only the metric data for the metric_type that we've chosen matters.
$metric_type = $_POST['metric_type'];

$experiment_data = $_POST;
$experiment_data['variants'] = $_POST['variant'];
unset( $experiment_data['variant'], $experiment_data['_wpnonce'], $experiment_data['_wp_http_referer'] );

$metric_data = $_POST['metric'][$metric_type];
unset( $experiment_data['metric'] );
$metric_data['type'] = $metric_type;
// if the checkbox is not checked, there will be no value.
if ( $metric_type == 'manual' ) {
	if ( !isset( $metric_data['ifnull'] ) ) {
		$metric_data['ifnull'] = 0;
		$metric_data['nullvalue'] = 0;
	}
}
$metric_data = apply_filters( 'shrimptest_save_metric_' . $metric_type, $metric_data );

if ( empty( $experiment_data['variants'] ) )
	wp_die( "Please enter some variants." );

$experiment_data = array_merge( $experiment_data, $metric_data );

$this->model->update_experiment( $experiment_id, $experiment_data );

wp_redirect( admin_url("admin.php?page={$this->slug}&message=" . $this->message_save) );
exit;
