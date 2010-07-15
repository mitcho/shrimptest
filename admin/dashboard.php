<?php

if ( !class_exists( 'WP' ) )
	die( "WordPress hasn't been loaded! :(" );

if ( !current_user_can('manage_options') )
	wp_die( __('You do not have sufficient permissions to access this page.') );

?>

<div class="wrap">
<?php screen_icon(); ?>
<h2><?php _e( 'ShrimpTest Dashboard', 'shrimptest' ); ?></h2>

<?php

function shrimptest_active_experiments_metabox() {
	global $shrimp;
	$experiments = $shrimp->model->get_active_experiments();
	if ( !count( $experiments ) ) {
		_e( 'There are no active experiments at this time.', 'shrimptest' );
		return;
	}
	echo "<table>
	<thead><tr><th>ID</th><th>Name</th><th>Status</th><th>Start date</th></tr></thead><tbody>";
	foreach ( $experiments as $experiment ) {
		$date_format = get_option('date_format');
		$start_date = date( $date_format, $experiment->start_time );
		echo "<tr><td>{$experiment->experiment_id}</td><td>{$experiment->experiment_name}</td><td>{$experiment->status}</td><td>{$start_date}</td></tr>";
	}
	echo "</tbody></table>";
}
add_meta_box( 'experiments', 'Active experiments', 'shrimptest_active_experiments_metabox', 'shrimptest_dashboard', 'advanced' );

?>
<div id="poststuff" class="metabox-holder">
<?php do_meta_boxes('shrimptest_dashboard','advanced',null); ?>
</div>
</div>