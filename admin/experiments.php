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
register_column_headers($current_screen, array('id_name'=>'Experiment name','status'=>'Status','start_date'=>'Start date','metric'=>'Metric','metric_N'=>'N','metric_avg'=>'Average','$pmessage'=>'Result'));

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

$experiments = $this->model->get_experiments( array( 'status' => array( 'inactive', 'active', 'finished' ) ) );
foreach( $experiments as $experiment ) {
	$stats = $this->model->get_experiment_stats( $experiment->experiment_id );
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
		$activate_url = wp_nonce_url('admin.php?page=shrimptest_experiments&amp;action=activate&amp;id=' . $experiment->experiment_id, 'activate-experiment_' . $experiment->experiment_id);
		$actions['activate'] = '<a href="'.$activate_url.'">' . __('Activate', 'shrimptest') . '</a>';

	}

	if ( $experiment->status == 'active' ) {
		$conclude_url = wp_nonce_url('admin.php?page=shrimptest_experiments&amp;action=conclude&amp;id=' . $experiment->experiment_id, 'conclude-experiment_' . $experiment->experiment_id);
		$actions['end'] = '<a class="submitdelete" href="'.$conclude_url.'">' . __('Stop', 'shrimptest') . '</a>';
	}
	
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

		$zscore = __( 'N/A', 'shrimptest' );
		$pvalue = __( 'N/A', 'shrimptest' );
		$pmessage = __( 'N/A', 'shrimptest' );

		if ($key === 0) {
			$control = $stat;
			$name = __("Control", 'shrimptest');
		} else {
			$name = __("Variant",'shrimptest') . " " . $stat->variant_id;
			$zscore = $this->model->zscore( $variant, $stat );
			if ( $zscore ) {
				$type = 'better'; // "better", "different"
				// TODO: add "worse"
				$p = $this->model->normal_cdf($zscore,($type == 'better'?'left':'middle'));

				$null_p = 1 - $p;
				$null_p = ( floor( $null_p * 1000) / 1000 );
				$null_p = "p &lt; {$null_p}";
				$zscore = "z = " . ( floor($zscore * 1000) / 1000 );

				if ( $p >= 0.95 ) {
					if ( $p >= 0.99 )
						$desc = "very confident";
					else if ( $p >= 0.95 )
						$desc = "confident";
					$pmessage = sprintf( "We are <strong>%s</strong> that variant %d is %s than the control. (%s, %s)", $desc, $stat->variant_id, $type, $null_p, $zscore );
				} else {
					$pmessage = sprintf( "We cannot confidently say whether or not variant %d is %s than the control. Perhaps there is no effect or there is not enough data. (%s)", $stat->variant_id, $type, $zscore );
				}
			}
		}

		echo "<tr class=\"variant\" data-experiment=\"{$experiment->experiment_id}\"><td><strong>{$name}:</strong> {$stat->variant_name} ($assignment_percentage%)</td><td colspan='3'></td><td>{$stat->N}</td><td>{$stat->avg}</td><td>$pmessage</td></tr>";

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