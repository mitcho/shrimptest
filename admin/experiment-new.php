<form method="post">
<?php
/**
 * @action shrimptest_add_variant_extra
 * @action shrimptest_add_metric_extra
 * @action shrimptest_add_duration_extra
 */
global $experiment_id, $metric_id, $experiment;

if ( isset( $_GET['id'] ) ) {
	$experiment_id = (int) $_GET['id'];
} else {
	wp_die('You must come in with an ID.');
}

$experiments = $this->model->get_experiments( array( 'experiment_id'=>$experiment_id, 'status'=>array('inactive','reserved') ) );

if ( !count($experiments) )
	wp_die( __( 'This experiment was not found.', 'shrimptest' ) );

$experiment = $experiments[0];

?>

<div class="wrap">
<?php screen_icon(); ?>
<h2><?php _e( 'ShrimpTest Experiment', 'shrimptest' ); ?>: <?php if ($experiment->status == 'reserved') _e('Add New'); else echo esc_html($experiment->experiment_name); ?></h2>
<?php



function shrimptest_details_metabox( ) {
	global $experiment;
	$status_strings = array( 'active'=>__('Active','shrimptest'), 'finished'=>__('Finished','shrimptest'), 'inactive'=>__('Not yet started','shrimptest'),
	'reserved' => __('New', 'shrimptest'));
	$date_format = get_option('date_format');
?>
<table class='shrimptest'>
<tr><th><?php _e('Name:','shrimptest');?></th><td><input name="name" type="text" maxlength="255" size="50" value="<?php echo esc_attr($experiment->experiment_name);?>" required></input></td></tr>
<tr><th><?php _e('Status:','shrimptest');?></th><td><?php echo $status_strings[$experiment->status];?></td></tr>
</table>
<?php
}

function shrimptest_variants_metabox( ) {
	global $experiment, $shrimp;
	
	$types = $shrimp->model->get_variant_types_to_edit( $experiment->variants_type );
	
	if ( array_search( $experiment->variants_type, array_keys( $types ) ) === false )
		wp_die( sprintf("The variant type code <code>%s</code> is not currently registered. This experiment cannot be edited nor activated.", $experiment->variants_type ) );
	
?>
<div class="samplecodediv variants_extra variants_extra_manual">
<h4>Sample code:</h4>
<pre id="variants_code" class="samplecode"></pre>
</div>
<table class='shrimptest' id='shrimptest_variants'>
<tr><th><?php _e('Type','shrimptest');?>:</th><td colspan="2"><select id="variants_type" name="variants_type" required>
<?php
foreach ( $types as $code => $type ) {
  echo "<option value=\"{$code}\"".(isset($type->selected) && $type->selected ?' selected="selected"':'').(isset($type->disabled) && $type->disabled ?' disabled="disabled"':'').">" . __($type->label, 'shrimptest') . "</option>";
}
?>
</select></td></tr>
<?php
do_action( 'shrimptest_add_variant_extra', $experiment );

	$variants = $shrimp->model->get_experiment_variants( $experiment->experiment_id );
	if ( empty( $variants ) )
		$variants = array( (object) array( 'variant_id'=>0, 'variant_name'=>'' ), (object) array( 'variant_id'=>1, 'variant_name'=>'' ) );
?>
<tr><th></th><th><?php _e('Name','shrimptest');?>:</th><th><?php _e('Assignment weight','shrimptest');?>:</th></tr>
<tr><th><label for="variant[0][name]"><?php _e('Control','shrimptest');?>:</label> <input type="button" id="addvariant" value="+"/></th><td><input type="text" name="variant[0][name]" id="variant[0][name]" value="<?php echo esc_attr($variants[0]->variant_name);?>"></input></td><td><input type="text" name="variant[0][assignment_weight]" id="variant[0][assignment_weight]" size="3" value="<?php echo (isset($variants[0]->assignment_weight) ? esc_attr($variants[0]->assignment_weight) : 1);?>"></input></td></tr>
<?php
	foreach ( $variants as $variant ) {
		if ( $variant->variant_id == 0 )
			continue;
		$name = __("Variant",'shrimptest') . " " . $variant->variant_id;
		if ( $variant->variant_id > 1 )
			$removebutton = "<input type=\"button\" class=\"removevariant\" value=\"-\"/>";
		else
			$removebutton = '';
		echo "<tr><th><label for=\"variant[{$variant->variant_id}][name]\">" . esc_html($name) . ":</label> {$removebutton}</th><td><input data-variant=\"{$variant->variant_id}\" type=\"text\" name=\"variant[{$variant->variant_id}][name]\" id=\"variant[{$variant->variant_id}][name]\" value=\"" . esc_attr($variant->variant_name) . "\"></input></td><td><input type=\"text\" name=\"variant[{$variant->variant_id}][assignment_weight]\" id=\"variant[{$variant->variant_id}][assignment_weight]\" value=\"".(isset($variant->assignment_weight) ? esc_attr($variant->assignment_weight) : 1)."\" size=\"3\"></input></td></tr>";
	}
	echo "<script type=\"text/javascript\">newVariantId = {$variant->variant_id} + 1;</script>";
?>
</table>
<?php
}

function shrimptest_metric_metabox( ) {
	global $experiment_id, $experiment, $shrimp;
	
	$types = $shrimp->model->get_metric_types_to_edit( $experiment->metric_type );
	
	if ( array_search( $experiment->metric_type, array_keys( $types ) ) === false )
		wp_die( sprintf("The metric type code <code>%s</code> is not currently registered. This experiment cannot be edited nor activated.", $experiment->metric_type ) );

?>
<div class="samplecodediv metric_extra metric_extra_manual">
<h4>Sample code:</h4>
<p><?php _e("Execute the following code when the visitor's metric value is established:",'shrimptest');?></p>
<pre id="variants_code" class="samplecode">shrimptest_update_metric( <?php echo $experiment_id; ?>, <em>value</em> );</pre>
</div>
<table class='shrimptest' id='shrimptest_metrics'>
<!--<tr><th><?php _e('ID:','shrimptest');?></th><td><code><?php echo $experiment_id; ?></code></td></tr>-->
<tr><th><label for="metric_type"><?php _e('Metric type:','shrimptest');?></th><td><select id="metric_type" name="metric_type" required>
<?php
foreach ( $types as $code => $type ) {
  echo "<option value=\"{$code}\"".(isset($type->selected) && $type->selected ?' selected="selected"':'').(isset($type->disabled) && $type->disabled ?' disabled="disabled"':'').">" . __($type->label, 'shrimptest') . "</option>";
}
?>
</select></td></tr>
<?php
do_action( 'shrimptest_add_metric_extra', $experiment );
?>
<tr class="metric_extra metric_extra_manual"><th><?php _e('Direction','shrimptest');?>:</th><td><?php echo sprintf(__("%s are better.",'shrimptest'), '<select name="metric[manual][direction]" id="metric_extra_manual_direction"><option value="larger">'.__('Larger values','shrimptest').'</option><option value="smaller">'.__('Smaller values','shrimptest').'</option></select>');?></td></tr>
<tr class="metric_extra metric_extra_manual"><th><?php _e('Default value','shrimptest');?>:</th><td><input id="metric_extra_manual_ifnull" name="metric[manual][ifnull]" type="checkbox" checked="checked"/> <label for="metric_extra_manual_ifnull"><?php echo sprintf(__("Assume value of %s for visitors who have not triggered an explicit metric update.",'shrimptest'), '</label><input name="metric[manual][nullvalue]" id="metric_extra_manual_nullvalue" value="0" size="3" type="text"/><label for="metric_extra_manual_nullvalue">');?></label></td></tr>
<tr class="metric_extra metric_extra_manual"><th><?php _e('Variance (optional)','shrimptest');?>:</th><td><input id="metric_extra_manual_sd" name="metric[manual][sd]" type="text" value="" size="3"/> <label for="metric_extra_manual_sd"><?php _e('standard deviations','shrimptest');?></label></td></tr>
</table>
<?php	
}

function shrimptest_duration_metabox( ) {
	global $experiment_id, $experiment, $shrimp;
	
	$types = $shrimp->model->get_metric_types_to_edit( $experiment->metric_type );

?>
<table class='shrimptest' id='shrimptest_duration'>
<!--<tr><th><?php _e('ID:','shrimptest');?></th><td><code><?php echo $experiment_id; ?></code></td></tr>-->
<tr><th><label for="detection"><?php _e('Detection level','shrimptest');?>:</th><td><input type="text" name="detection" id="detection" size="7" value="<?php echo isset( $experiment->data['detection'] ) ? 
	  esc_attr( $experiment->data['detection'] ) : '';?>"></input><br/>
<small><?php _e( "The smallest detectable difference in your metric which you would like the experiment to be able to detect.", 'shrimptest' );?></small></td></tr>
<tr><th><label for="duration"><?php _e('Experiment duration','shrimptest');?>:</th><td><input type="text" name="duration" id="duration" size="7" value="<?php echo isset( $experiment->data['duration'] ) ? 
	  esc_attr( $experiment->data['duration'] ) : '';?>"></input> <?php _e( 'unique visitors', 'shrimptest' ); ?><br/>
<small><?php _e( "Confident results will not be available until this experiment duration has been reached.", 'shrimptest' );?></small></td></tr>
<?php
do_action( 'shrimptest_add_duration_extra', $experiment );
?>
</table>
<?php	
}


add_meta_box( 'details_box', __('Details', 'shrimptest'), 'shrimptest_details_metabox', 'shrimptest_experiment', 'advanced' );
add_meta_box( 'variants_box', __('Variants', 'shrimptest'), 'shrimptest_variants_metabox', 'shrimptest_experiment', 'advanced' );
add_meta_box( 'metric_box', __('Metric', 'shrimptest'), 'shrimptest_metric_metabox', 'shrimptest_experiment', 'advanced' );
add_meta_box( 'duration_box', __('Experiment duration', 'shrimptest') . ' <small>(' . __('optional', 'shrimptest') . ')</small>', 'shrimptest_duration_metabox', 'shrimptest_experiment', 'advanced' );

?>
<div id="poststuff" class="metabox-holder">
<?php do_meta_boxes('shrimptest_experiment','advanced',null); ?>
</div>
</div>
<input type="submit" value="<?php if ($experiment->status == 'reserved') _e('Save new experiment','shrimptest'); else _e('Save experiment','shrimptest');?>" id="submit" class="button-primary" name="submit"/>
<small><?php _e('Your new experiment will not be active until you activate it on the next page.','shrimptest');?></small>

<?php wp_nonce_field( 'shrimptest_submit_new_experiment' ); ?> 
</form>

<script type="text/javascript">
jQuery(function($){
	
	// magical rowspan adjusting code.
	$ = jQuery;
	$('td.autospandown, th.autospandown').each(function(el,d) {
		var thisRow = $(this).closest('tr');
		var prevRowCount = thisRow.prev('tr').length;
		var totalRows = thisRow.find('tr').count;
		$(this).attr('rowspan',totalRows - prevRowCount);
	})
	
	var updateVariantsCode = function() {
		var code = "$variant = shrimptest_get_variant( <?php echo $experiment_id;?> );\nswitch ( $variant ) {\n";
		
		for (i=0; i<newVariantId; i++) {
			code += "  case "+i+":\n    // <?php _e('Variant');?> "+i+"\n    break;\n";
		}
		
		code += "  default:\n    // <?php _e('Control');?>\n";
		code += "}";
		
		$('#variants_code').text(code);
	};
	
	// metrics block
	var ensureMetricConsistency = function() {
		var metric_type = $('#metric_type').val();
		$('.metric_extra').hide();
		$('.metric_extra_'+metric_type).show();
		$(document).trigger('metric_extra_'+metric_type);
	}
	$('#metric_type, #metric_extra_ifnull').change(ensureMetricConsistency);
	
	// metric: manual
	$(document).bind('metric_extra_manual',function()		{
		$('.metric_extra').hide();
		$('.metric_extra_manual').show();
		if ($('#metric_extra_manual_ifnull').attr('checked'))
			$('#metric_extra_manual_nullvalue').attr('disabled',false);
		else
			$('#metric_extra_manual_nullvalue').attr('disabled',true);
	});
	
	// variants block
	var ensureVariantsConsistency = function() {
		var variants_type = $('#variants_type').val();
		$('.variants_extra').hide();
		$('.variants_extra_'+variants_type).show();
		$(document).trigger('variants_extra_'+variants_type);
	}
	$('#variants_type').change(ensureVariantsConsistency);

	// variants: manual
	$('#addvariant').click(function(){
		$('.removevariant').hide();
		
		var newRow = $("<tr><th><label></label> <input type=\"button\" class=\"removevariant\" value=\"-\"/></th><td><input type=\"text\"></input></td><td><input type=\"text\" size=\"3\" value=\"1\"></input></td></tr>");
		
		newRow
			.attr('data-variant',newVariantId)
			.find('label')
				.text("<?php _e('Variant','shrimptest')?> "+newVariantId+':')
				.attr('for','variant['+newVariantId+'][name]')
			.end()
			.find('input[type=text]').eq(0)
				.attr({id:'variant['+newVariantId+'][name]',name:'variant['+newVariantId+'][name]'})
			.end()
			.find('input[type=text]').eq(1)
				.attr({id:'variant['+newVariantId+'][assignment_weight]',name:'variant['+newVariantId+'][assignment_weight]'})
			.end();

		$('#shrimptest_variants').append(newRow);

		newVariantId++;
		
		updateVariantsCode();
	});
	
	$('.removevariant').live('click',function(){
		if ( $(this).closest('tr').attr('data-variant') == newVariantId - 1 )
			newVariantId --;
		$(this).closest('tr').remove();
		$('.removevariant').last().show();
		updateVariantsCode();
	});
	
	// duration block
	var updateDuration = function() {
		var defaultValue = parseInt($('#duration').attr('defaultValue'));
		var metric = $('#metric_type').val();
		var sd = $('#metric_extra_' + metric + '_sd').val();
		if (sd)
			sd = parseFloat(sd);
		var detection = $('#detection').val();
		if (detection)
			detection = parseFloat(detection);
		var duration = (sd && detection) ? Math.ceil(16 * sd * sd / (detection * detection)) : null;
		$('#duration').attr('defaultValue',duration);
		if ((defaultValue && parseInt($('#duration').val()) == defaultValue) || $('#duration').val() == '')
			$('#duration').val(duration);
	};
	$('#metric_type, #detection').change(updateDuration);

	// init
	ensureMetricConsistency();
	ensureVariantsConsistency();
	updateVariantsCode();
	updateDuration();
});
</script>