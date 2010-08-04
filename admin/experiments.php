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

$current_screen = $this->slug;
register_column_headers($current_screen, array('id_name'=>'Experiment Name','status'=>'Status','metric'=>'Metric','metric_N'=>'Total Visitors','metric_avg'=>'Metric Average','$pmessage'=>'Result'));

?>

<div class="wrap">
<?php screen_icon(); ?>
<h2><?php _e( 'ShrimpTest Experiments', 'shrimptest' ); ?> <a class="button add-new-h2" href="<?php echo admin_url("admin.php?page={$this->slug}&action=new") ?>">Add New</a></h2>

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
		
	echo "<tr data-experiment='{$experiment->experiment_id}'><td><strong>$experiment_name</strong>";

	// DO ROW ACTIONS
	$actions = array();
	$actions['showhide'] = "<a href=\"#\" class=\"showhide\" data-experiment=\"{$experiment->experiment_id}\" ></a>";
	if ( $experiment->status == 'inactive' ) {
		$edit_url = "admin.php?page={$this->slug}&action=new&id=" . $experiment->experiment_id;
		$actions['edit'] = '<a href="'.$edit_url.'">' . __('Edit', 'shrimptest') . '</a>';
		$activate_url = wp_nonce_url("admin.php?page={$this->slug}&amp;action=activate&amp;id=" . $experiment->experiment_id, 'activate-experiment_' . $experiment->experiment_id);
		$actions['activate'] = '<a href="'.$activate_url.'">' . __('Activate', 'shrimptest') . '</a>';

	}

	if ( $experiment->status == 'active' ) {
		$conclude_url = wp_nonce_url("admin.php?page={$this->slug}&amp;action=conclude&amp;id=" . $experiment->experiment_id, 'conclude-experiment_' . $experiment->experiment_id);
		$actions['end'] = '<a class="submitdelete" href="'.$conclude_url.'">' . __('Stop', 'shrimptest') . '</a>';
	}
	
	$actions = apply_filters( 'shrimptest_admin_experiment_row_actions', $actions );//, $post
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
	
	$status = "<strong>$status</strong>";
	if ( isset( $start_date ) && $start_date )
		$status .= "<br/><small>Started: {$start_date}</small>";
	if ( isset( $end_date ) && $end_date )
		$status .= "<br/><small>Finished: {$end_date}</small>";
	
	echo "</td><td>{$status}</td><td>{$experiment->metric_name}</td><td>{$total->N}</td><td>" . $this->model->display_metric_value($experiment->metric_type, $total->avg, $total->sum) . "</td><td>&nbsp;</td></tr>\n";
	
	unset( $control );
	
	if ( isset( $stats['stats'] ) ) { // DEBUG
		$stats_stats = $stats['stats'];
		unset( $stats['stats'] );
		echo '<!--';
		var_dump( $stats_stats );
		echo '-->';
	}
	
	foreach ( $stats as $key => $stat ) {
		if ($total->assignment_weight)
			$assignment_percentage = round( $stat->assignment_weight / $total->assignment_weight * 1000 ) / 10;
		if ( $key === 'total' )
			continue;

		$pvalue = '<span class="na">' . __( 'N/A', 'shrimptest' ) . '</span>';
		$pmessage = '<span class="na">' . __( 'N/A', 'shrimptest' ) . '</span>';

		$avg = $this->model->display_metric_value($experiment->metric_type, $stat->avg, $stat->sum);

		if ($key === 0) {
			$control = $stat;
			$name = __("Control", 'shrimptest');
		} else {
			$name = __("Variant",'shrimptest') . " " . $stat->variant_id;
			$p = $stat->p;

			if ( $p != null ) {
				$null_p = round( 1 - $p, 4 );
				$null_p = "p &lt; {$null_p}";

				if ( $p >= 0.95 ) {
					// TODO: allow custom confidence intervals
					if ( $p >= 0.99 )
						$desc = "very confident";
					else if ( $p >= 0.95 )
						$desc = "confident";
					$pmessage = sprintf( "We are <strong>%s</strong> that variant %d is %s than the control. (%s)", $desc, $stat->variant_id, $stat->type, $null_p );
				} else {
					$pmessage = sprintf( "We cannot confidently say whether or not variant %d is %s than the control. Perhaps there is no effect or there is not enough data. (%s)", $stat->variant_id, $stat->type, $null_p );
				}
			}
		}

		echo "<tr data-experiment='{$experiment->experiment_id}' class=\"variant\" data-experiment=\"{$experiment->experiment_id}\"><td><strong>{$name}:</strong> {$stat->variant_name} ($assignment_percentage%)</td><td colspan='2'></td><td>{$stat->N}</td><td>{$avg}</td><td>$pmessage</td></tr>";

	}
	
}

?>
	</tbody>
</table>

<style type="text/css">
.na {
	color: #ccc;
}
.rawvalue {
	display: none;
}
.rawvalue.visible {
	display: inline;
	color: #999;
}
</style>
<script type="text/javascript">
jQuery(function($) {
	function toggleVariants( id ) {
		if ($('a[data-experiment='+id+']').text() == '<?php _e("Show variants", "shrimptest");?>') {
			$('a[data-experiment='+id+']').text('<?php _e("Hide variants", "shrimptest");?>');
			$('.variant[data-experiment='+id+']').show();
		} else {
			$('a[data-experiment='+id+']').text('<?php _e("Show variants", "shrimptest");?>');
			$('.variant[data-experiment='+id+']').hide();
		}
	}
	
	$('a.showhide').each(function(i,item) {
		var jitem = $(item);
		var id = jitem.attr('data-experiment');
		toggleVariants(id);
		jitem.click(function() {
			toggleVariants(id);
		});
	});

	$('tr').each(function() {
		var experiment = $(this).attr('data-experiment');
		if (experiment) {
			$(this).mouseenter(function(){
				$('[data-experiment=' + experiment + '] .rawvalue').addClass('visible');
			})
			.mouseleave(function(){
				$('[data-experiment=' + experiment + '] .rawvalue').removeClass('visible');
			});
		}
	})

});
</script>

</div>
</div>