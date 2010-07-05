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
register_column_headers($current_screen, array('id_name'=>'Experiment name','status'=>'Status','start_date'=>'Start date','metric'=>'Metric','metric_N'=>'N','metric_avg'=>'Average','zscore'=>'Z-score'));

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
	
	if ( $experiment->experiment_name )
		$experiment_name = "{$experiment->experiment_name} (#{$experiment->experiment_id})";
	else
		$experiment_name = "#{$experiment->experiment_id}";
		
	echo "<tr><td><strong>$experiment_name</strong>";

	// DO ROW ACTIONS
	$actions = array();
	$actions['showhide'] = "<a href=\"#\" class=\"showhide\" data-experiment=\"{$experiment->experiment_id}\" ></a>";
	if ( $experiment->status == 'inactive' ) {
		$edit_url = 'admin.php?page=shrimptest_experiments&action=new&id=' . $experiment->experiment_id;
		$actions['edit'] = '<a href="'.$edit_url.'">' . __('Edit', 'shrimptest') . '</a>';
		$start_url = wp_nonce_url('admin.php?page=shrimptest_experiments&amp;action=start&amp;id=' . $experiment->experiment_id, 'start-experiment_' . $experiment->experiment_id);
		$actions['start'] = '<a class="submitdelete" href="'.$start_url.'">' . __('Start', 'shrimptest') . '</a>';

	}
	/* TODO: implement "End"
	if ( $experiment->status == 'active' ) {
		$end_url = wp_nonce_url('admin.php?page=shrimptest_experiments&amp;action=end&amp;id=' . $experiment->experiment_id, 'end-experiment_' . $experiment->experiment_id);
		$actions['end'] = '<a class="submitdelete" href="'.$end_url.'">' . __('End', 'shrimptest') . '</a>';
	}*/
	
	$actions = apply_filters( 'shrimptest_admin_experiment_row_actions', $actions, $post );
	$action_count = count($actions);
	$i = 0;
	echo '<div class="row-actions">';
	foreach ( $actions as $action => $link ) {
		++$i;
		( $i == $action_count ) ? $sep = '' : $sep = ' | ';
		echo "<span class='$action'>$link$sep</span>";
	}
	echo '</div>';
	// END ROW ACTIONS
	
	echo "</td><td>{$status}</td><td>{$start_date}</td><td>{$experiment->metric_name}</td><td>{$total->N}</td><td>{$total->avg}</td><td>&nbsp;</td></tr>";
	
	unset( $control );
	foreach ( $stats as $key => $stat ) {
		$assignment_percentage = round( $stat->assignment_weight / $total->assignment_weight * 1000 ) / 10;
		if ( $key === 'total' )
			continue;
		if ($key === 0) {
			$control = $stat;
			$name = __("Control", 'shrimptest');
			$zscore = __( 'N/A', 'shrimptest' );
		} else {
			$name = __("Variant",'shrimptest') . " " . $stat->variant_id;
			if ( isset( $control ) && $stat->N && $control->N && ( $stat->sd || $control->sd ) )
				$zscore = ( $stat->avg - $control->avg ) / sqrt( (pow($stat->sd, 2) / ($stat->N)) + (pow($control->sd, 2) / ($control->N)) );
			else
				$zscore = __( 'N/A', 'shrimptest' );
		}

		echo "<tr class=\"variant\" data-experiment=\"{$experiment->experiment_id}\"><td><strong>{$name}:</strong> {$stat->variant_name} ($assignment_percentage%)</td><td colspan='3'></td><td>{$stat->N}</td><td>{$stat->avg}</td><td>$zscore</td></tr>";

	}
	
}

?>
	</tbody>
</table>

<script type="text/javascript">
function toggleVariants( id ) {
  if (jQuery('a[data-experiment='+id+']').text() == '<?php _e("Show variants", "shrimptest");?>') {
    jQuery('a[data-experiment='+id+']').text('<?php _e("Hide variants", "shrimptest");?>');
    jQuery('.variant[data-experiment='+id+']').show();
  } else {
    jQuery('a[data-experiment='+id+']').text('<?php _e("Show variants", "shrimptest");?>');
    jQuery('.variant[data-experiment='+id+']').hide();
  }
}

jQuery('a.showhide').each(function(i,item) {
  var jitem = jQuery(item);
  var id = jitem.attr('data-experiment');
  toggleVariants(id);
  jitem.click(function() {
    toggleVariants(id);
  });
})
</script>

</div>
</div>