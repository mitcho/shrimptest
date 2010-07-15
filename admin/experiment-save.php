<?php 
global $wpdb;

$experiment_id = (int) $_REQUEST['id'];
$metric_id = $_POST['metric_id'];

$experiment_data = array( name => $_POST['name'],
													variants_type => $_POST['variants_type'],
													variants => $_POST['variant'],
													metric_id => $metric_id,
													);

$this->model->update_experiment( $experiment_id, $experiment_data );

// only the metric data for the metric_type that we've chosen matters.
$metric_type = $_POST['metric_type'];
$metric_data = $_POST['metric'][$metric_type];
$metric_data['type'] = $metric_type;
$metric_data['name'] = $_POST['metric_name'];

// if the checkbox is not checked, there will be no value.
if ( $metric_type == 'manual' ) {
	if ( !isset( $metric_data['ifnull'] ) ) {
		$metric_data['ifnull'] = 0;
		$metric_data['nullvalue'] = 0;
	}
}

$metric_data = apply_filters( 'shrimptest_save_metric_' . $metric_type, $metric_data );
$this->model->update_metric( $metric_id, $metric_data );

wp_redirect( admin_url("admin.php?page={$this->slug}_experiments&message=" . $this->message_success) );
exit;
