<?php

if ( !class_exists( 'WP' ) )
	die( "WordPress hasn't been loaded! :(" );

if ( !current_user_can('manage_options') )
	wp_die( __('You do not have sufficient permissions to access this page.') );

if ( isset( $_GET['action'] ) && $_GET['action'] == 'new' ) {
	include 'experiment-new.php';
	exit;
}

if ( isset( $_GET['id'] ) ) {
	include 'experiment-detail.php';
	exit;
}

$current_screen = 'shrimptest_experiments';
register_column_headers($current_screen, array('experiment_id'=>'ID','name'=>'Experiment name','status'=>'Status','start_date'=>'Start date','metric'=>'Metric','metric_N'=>'N','metric_avg'=>'Average','zscore'=>'Z-score'));

?>

<div class="wrap">
<?php screen_icon(); ?>
<h2><?php _e( 'ShrimpTest Experiments', 'shrimptest' ); ?> <a class="button add-new-h2" href="<?php echo admin_url("admin.php?page=shrimptest_experiments&action=new") ?>">Add New</a></h2>

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

$experiments = $shrimp->get_experiments( array( 'status' => array( 'inactive', 'active', 'finished' ) ) );
foreach( $experiments as $experiment ) {
	$stats = $shrimp->get_experiment_stats( $experiment->experiment_id );
	$total = $stats["total"];

	$status = $status_strings[$experiment->status];
	$start_date = ($experiment->start_time ? date( $date_format, $experiment->start_time ) : '');
	
	echo "<tr><td>{$experiment->experiment_id}</td><td>{$experiment->experiment_name}</td><td>{$status}</td><td>{$start_date}</td><td>{$experiment->metric_name}</td><td>{$total->N}</td><td>{$total->avg}</td><td>&nbsp;</td></tr>";
	
	unset( $control );
	foreach ( $stats as $key => $stat ) {
		$assignment_percentage = round( $stat->assignment_weight / $total->assignment_weight * 1000 ) / 10;
		if ( $key === 'total' )
			continue;
		$name = __("Variant",'shrimptest') . " " . $stat->variant_id;
		if ($key === 0) {
			$control = $stat;
			$name .= " (" . __("control", 'shrimptest') . ")";
			$zscore = __( 'N/A', 'shrimptest' );
		} else {
			if ( isset( $control ) && $stat->N && $control->N && ( $stat->sd || $control->sd ) )
				$zscore = ( $stat->avg - $control->avg ) / sqrt( (pow($stat->sd, 2) / ($stat->N)) + (pow($control->sd, 2) / ($control->N)) );
			else
				$zscore = __( 'N/A', 'shrimptest' );
		}
		echo "<tr class=\"variant\"><td>{$name}</td><td>{$stat->variant_name} ($assignment_percentage%)</td><td colspan='3'></td><td>{$stat->N}</td><td>{$stat->avg}</td><td>$zscore</td></tr>";
	}
	
}

?>
	</tbody>
</table>

</div>
</div>