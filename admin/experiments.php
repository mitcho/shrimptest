<?php

if ( !class_exists( 'WP' ) )
	die( "WordPress hasn't been loaded! :(" );

if ( !current_user_can('manage_options') )
	wp_die( __('You do not have sufficient permissions to access this page.') );

if ( isset( $_GET['id'] ) ) {
	include 'experiment-detail.php';
	exit;
}

$current_screen = 'shrimptest_experiments';
register_column_headers($current_screen, array('experiment_id'=>'ID','name'=>'Experiment name','status'=>'Status','start_date'=>'Start date','metric'=>'Metric','metric_N'=>'N','metric_avg'=>'Average','metric_sd'=>'S.D.'));

?>

<div class="wrap">
<?php screen_icon(); ?>
<h2><?php _e( 'ShrimpTest Experiments', 'shrimptest' ); ?></h2>

<table class="widefat fixed" cellspacing="0">
	<thead>
	<tr>
<?php print_column_headers($current_screen); ?>
	</tr>
	</thead>

	<tfoot>
	<tr>
<?php print_column_headers($current_screen, false); ?>
	</tr>
	</tfoot>

	<tbody>
<?php

$status_strings = array( 'active'=>__('Active','shrimptest'), 'finished'=>__('Finished','shrimptest'), 'inactive'=>__('Not yet started','shrimptest') );
$date_format = get_option('date_format');

global $shrimp;

$experiments = $shrimp->get_experiments();
foreach( $experiments as $experiment ) {
	$stats = $shrimp->get_experiment_stats( $experiment->experiment_id );
	$total = $stats["total"];

	$status = $status_strings[$experiment->status];
	$start_date = date( $date_format, $experiment->start_time );
	
	echo "<tr><td>{$experiment->experiment_id}</td><td>{$experiment->experiment_name}</td><td>{$status}</td><td>{$start_date}</td><td>{$experiment->metric_name}</td><td>{$total->N}</td><td>{$total->avg}</td><td>{$total->sd}</td></tr>";
}

?>
	</tbody>
</table>

</div>
</div>