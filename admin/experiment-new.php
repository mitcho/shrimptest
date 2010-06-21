<form method="post">
<?php

global $shrimp, $experiment_id, $metric_id, $experiment;

if ( isset( $_GET['id'] ) ) {
	$experiment_id = (int) $_GET['id'];
} else {
	wp_die('You must come in with an ID.');
}

$metric_id = $shrimp->get_metric_id( $experiment_id );

$experiments = $shrimp->get_experiments( array( 'experiment_id'=>$experiment_id, 'status'=>array('inactive','reserved') ) );

if ( !count($experiments) )
	wp_die( __( 'This experiment was not found.', 'shrimptest' ) );

$experiment = $experiments[0];

?>

<div class="wrap">
<?php screen_icon(); ?>
<h2><?php _e( 'ShrimpTest Experiment', 'shrimptest' ); ?>: <?php _e('Add New') ?></h2>
<?php



function shrimptest_details_metabox( ) {
	global $shrimp;
?>
<table class='shrimptest'>
<tr><th><?php _e('Name:','shrimptest');?></th><td><input name="name" type="text" maxlength="255" size="50"></input></td></tr>
</table>
<?php
}

function shrimptest_variants_metabox( ) {
	global $shrimp, $experiment;
	
	$types = $shrimp->get_variant_types_strings( );
	
	if ( array_search( $experiment->variants_type, array_keys( $types ) ) === false )
		wp_die( sprintf("The variant type code <code>%s</code> is not currently registered. This experiment cannot be edited nor activated.", $experiment->variants_type ) );
	
?>
<div class="samplecodediv variant_extra variant_extra_manual">
<h4>Sample code:</h4>
<pre id="variants_code" class="samplecode"></pre>
</div>
<table class='shrimptest' id='shrimptest_variants'>
<tr><th><?php _e('Type','shrimptest');?>:</th><td colspan="2"><select id="variants_type" name="variants_type">
<?php
foreach ( $types as $code => $name ) {
  echo "<option value=\"{$code}\"".($code == $experiment->variants_type?' selected="selected"':'').">" . __($name, 'shrimptest') . "</option>";
}
?>
</select></td></tr>
<?php
	$variants = $shrimp->get_experiment_variants( $experiment->experiment_id );
	if ( empty( $variants ) )
		$variants = array( (object) array( 'variant_id'=>0 ), (object) array( 'variant_id'=>1 ) );
?>
<tr><th></th><th><?php _e('Name','shrimptest');?>:</th><th><?php _e('Assignment weight','shrimptest');?>:</th></tr>
<tr><th><label for="variant[0][name]"><?php _e('Control','shrimptest');?>:</label> <input type="button" id="addvariant" value="+"/></th><td><input type="text" name="variant[0][name]" id="variant[0][name]" value="<?php echo $variants[0]->variant_name;?>"></input></td><td><input type="text" name="variant[0][assignment_weight]" id="variant[0][assignment_weight]" size="3" value="<?php echo ($variants[0]->assignment_weight || 1);?>"></input></td></tr>
<?php
	foreach ( $variants as $variant ) {
		if ( $variant->variant_id == 0 )
			continue;
		$name = __("Variant",'shrimptest') . " " . $variant->variant_id;
		if ( $variant->variant_id > 1 )
			$removebutton = "<input type=\"button\" class=\"removevariant\" value=\"-\"/>";
		else
			$removebutton = '';
		echo "<tr><th><label for=\"variant[{$variant->variant_id}][name]\">{$name}:</label> {$removebutton}</th><td><input type=\"text\" name=\"variant[{$variant->variant_id}][name]\" id=\"variant[{$variant->variant_id}][name]\" value=\"{$variant->variant_name}\"></input></td><td><input type=\"text\" name=\"variant[{$variant->variant_id}][assignment_weight]\" id=\"variant[{$variant->variant_id}][assignment_weight]\" value=\"".($variant->assignment_weight || 1)."\" size=\"3\"></input></td></tr>";
	}
	echo "<script type=\"text/javascript\">newVariantId = {$variant->variant_id} + 1;</script>";
?>
</table>
<?php
}

function shrimptest_metric_metabox( ) {
	global $shrimp, $experiment_id, $experiment, $metric_id;
	$metric = $shrimp->get_metric( $metric_id );
	
	$types = $shrimp->get_metric_types_strings( );
	
	if ( array_search( $experiment->metric_type, array_keys( $types ) ) === false )
		wp_die( sprintf("The metric type code <code>%s</code> is not currently registered. This experiment cannot be edited nor activated.", $experiment->metric_type ) );

?>
<input type="hidden" name="metric_id" value="<?php echo $metric_id; ?>"></input>
<div class="samplecodediv metric_extra metric_extra_manual">
<h4>Sample code:</h4>
<p><?php _e("Execute the following code when the visitor's metric value is established:",'shrimptest');?></p>
<pre id="variants_code" class="samplecode">shrimptest_update_metric( <?php echo $metric_id; ?>, <em>value</em> );</pre>
</div>
<table class='shrimptest'>
<!--<tr><th><?php _e('ID:','shrimptest');?></th><td><code><?php echo $metric_id; ?></code></td></tr>-->
<tr><th><label for="metric_type"><?php _e('Metric type:','shrimptest');?></th><td><select id="metric_type" name="metric_type">
<?php
foreach ( $types as $code => $name ) {
  echo "<option value=\"{$code}\"".($code == $experiment->metrics_type?' selected="selected"':'').">" . __($name, 'shrimptest') . "</option>";
}
?>
</select></td></tr>
<tr class="metric_extra metric_extra_manual"><th><?php _e('Direction','shrimptest');?>:</th><td><?php echo sprintf(__("%s are better.",'shrimptest'), '<select name="metric[manual][direction]" id="metric_extra_manual_direction"><option value="larger">'.__('Larger values','shrimptest').'</option><option value="smaller">'.__('Smaller values','shrimptest').'</option></select>');?></td></tr>
<tr class="metric_extra metric_extra_manual"><th>Default value:</th><td><input id="metric_extra_manual_ifnull" name="metric[manual][ifnull]" type="checkbox" checked="checked"/> <label for="metric_extra_manual_ifnull"><?php echo sprintf(__("Assume value of %s for visitors who have not triggered an explicit metric update.",'shrimptest'), '</label><input name="metric[manual][nullvalue]" id="metric_extra_manual_nullvalue" value="0" size="3" type="text"/><label for="metric_extra_manual_nullvalue">');?></label></td></tr>
<?php
do_action( 'shrimptest_add_metric_extra', $metric, $experiment );
?>
</table>
<?php	
}



add_meta_box( 'details', 'Details', 'shrimptest_details_metabox', 'shrimptest_experiment', 'advanced' );
add_meta_box( 'variants', 'Variants', 'shrimptest_variants_metabox', 'shrimptest_experiment', 'advanced' );
add_meta_box( 'metric', 'Metric', 'shrimptest_metric_metabox', 'shrimptest_experiment', 'advanced' );

?>
<div id="poststuff" class="metabox-holder">
<?php do_meta_boxes('shrimptest_experiment','advanced',null); ?>
</div>
</div>
<input type="submit" value="<?php _e('Save new experiment','shrimptest');?>" id="submit" class="button-primary" name="submit"/>
<small><?php _e('Your new experiment will not be active until you activate it on the next page.','shrimptest');?></small>

<?php wp_nonce_field( 'shrimptest_submit_new_experiment' ); ?> 
</form>

<script type="text/javascript">
jQuery(document).ready(function($){
	
	// magical rowspan adjusting code.
	$ = jQuery;
	$('td.autospandown, th.autospandown').each(function(el,d) {
		var thisRow = $(this).closest('tr');
		var prevRowCount = thisRow.prev('tr').length;
		var totalRows = thisRow.find('tr').count;
		$(this).attr('rowspan',totalRows - prevRowCount);
	})
	
	var updateVariantsCode = function() {
		var code = "$variant = shrimptest_get_variant( $my_experiment_id );\nswitch ( $variant ) {\n";
		
		for (i=0;i<newVariantId;i++) {
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
			.data('variant',newVariantId)
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
		if ( $(this).closest('tr').data('variant') == newVariantId - 1 )
			newVariantId --;
		$(this).closest('tr').remove();
		$('.removevariant').last().show();
		updateVariantsCode();
	});

	// init
	ensureMetricConsistency();
	ensureVariantsConsistency();
	updateVariantsCode();
});
</script>