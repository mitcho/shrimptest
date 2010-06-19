<?php

add_action( 'shrimptest_init', 'shrimptest_metric_conversion_init', 10, 1 );
add_action( 'shrimptest_add_metric_extra', 'shrimptest_metric_conversion_extra', 10, 1 );
add_action( 'shrimptest_admin_header', 'shrimptest_metric_conversion_script_and_style' );

function shrimptest_metric_conversion_init( $shrimp ) {
	$shrimp->register_metric_type( 'conversion', 'Conversion' );
}

function shrimptest_metric_conversion_extra( $metric ) {
	if ( isset( $metric->data->conversion_url ) ) {
		$class = '';
		$value = $metric->data->conversion_url;
	} else {
		$class = 'default';
		$value = site_url();
	}
// all <tr>'s here must have the class 'metric_extra metric_extra_conversion'
?>
<tr class="metric_extra metric_extra_conversion"><th><?php _e('Conversion goal URL:','shrimptest');?></th><td><input class="<?php echo $class; ?>" name="metric[conversion][conversion_url]" id="metric_extra_conversion_conversion_url" type="text" value="<?php echo $value; ?>" size="40" maxlength="255"></input></td></tr>
<?php
}

function shrimptest_metric_conversion_script_and_style( ) {
?>
<script type="text/javascript">
jQuery(document).ready(function($) {
	$('#metric_extra_conversion_conversion_url.default').live('focus click',function() {
		$(this).val('').removeClass('default');
	});
	$('#metric_extra_conversion_conversion_url').blur(function() {
		if ($(this).val() == '')
			$(this).val($(this)[0].defaultValue).addClass('default');
	});
});
</script>
<style type="text/css">
input.default {color: #ccc;}
</style>
<?php
}