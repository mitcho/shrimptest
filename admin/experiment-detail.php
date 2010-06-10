<?php

$experiment_id = (int) $_GET['id'];

global $shrimp;
$experiments = $shrimp->get_experiments( array( 'experiment_id'=>$experiment_id ) );

if ( !count($experiments) )
	wp_die( __( 'This experiment was not found.', 'shrimptest' ) );

$experiment = $experiments[0];

?>

<div class="wrap">
<?php screen_icon(); ?>
<h2><?php _e( 'ShrimpTest Experiment', 'shrimptest' ); ?>: <?php echo $experiment->name; ?></h2>

<?php



function shrimptest_details_metabox() {
	global $shrimp;
	echo "<table>";
	echo "<tr><th>Name:</th><td></td></tr>";
	echo "</table>";

}
add_meta_box( 'experiments', 'Details', 'shrimptest_details_metabox', 'shrimptest_experiment', 'advanced' );

?>
<div id="poststuff" class="metabox-holder">
<?php do_meta_boxes('shrimptest_experiment','advanced',null); ?>
</div>
</div>