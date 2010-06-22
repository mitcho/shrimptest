<?php

define( 'SHRIMPTEST_QUERY_VARS_HEADER', 'X-ShrimpTest-Query-Vars' );
define( 'SHRIMPTEST_QUERY_VARS_PARAMETER', 'shrimptest_query_vars' );

add_action( 'shrimptest_init', 'shrimptest_metric_conversion_init', 10, 1 );
add_action( 'shrimptest_add_metric_extra', 'shrimptest_metric_conversion_extra', 10, 1 );
add_action( 'shrimptest_admin_header', 'shrimptest_metric_conversion_script_and_style' );

// Filter for adjusting the aggregate value SQL expression ( max(ifnull(value,0)) )
// add_filter( 'shrimptest_get_stats_value_conversion' )
add_filter( 'shrimptest_save_metric_conversion', 'shrimptest_metric_conversion_save_filter' );

// Utility function
add_filter( 'wp_headers', 'shrimptest_metric_conversion_print_query_headers', 10, 2 );

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

function shrimptest_metric_conversion_save_filter( $metric_data ) {

	// first things first, ensure that the $metric_data includes the appropriate parameters for a 
	// conversion-style metric.
	$metric_data['ifnull'] = true;
	$metric_data['nullvalue'] = 0;
	$metric_data['direction'] = 'greater'; // is better

	$url = $metric_data['conversion_url'];
	if ( empty( $url ) )
		wp_die( __( 'No conversion URL was specified.', 'shrimptest' ) );
	if ( stripos( $url, site_url() ) !== 0 )
		wp_die( __( 'The specified conversion URL is not part of your WordPress site. Please go back and enter another.', 'shrimptest' ) );

	// first try the post ID method. will work if this URL is_single.
	$post_ID = url_to_postid( $url );
	if ( $post_ID ) {
		$title = get_the_title($post_ID);
		$metric_data['name'] = sprintf( __("Conversion: %s (%s)",'shrimptest'), $title, $url );
		$metric_data['conversion_post_ID'] = $post_ID;
		return $metric_data;
	}
	
	// next let's try to store the URL's query vars array.
	$query_vars = shrimptest_metric_conversion_retrieve_query_vars( $url );
	if ( $query_vars ) {
		// reconstruct the appropriate title, right here, right now.
		// NOTE: this method may be relatively fragile.
		query_posts( $query_vars );
		$title = wp_title( '|', false, 'right' );
		$title = preg_replace( '/^\s*\|?\s*(.*?)\s*\|?\s*$/', '$1', $title );
		$metric_data['name'] = sprintf( __("Conversion: %s (%s)",'shrimptest'), $title, $url );
		$metric_data['conversion_query_vars'] = $query_vars;
		return $metric_data;
	}

	// else, we have to kick it back to the user saying we couldn't resolve that URL.
	wp_die( __( 'The specified conversion URL is not part of your WordPress site. Please go back and enter another.', 'shrimptest' ) );

}

function shrimptest_metric_conversion_print_query_headers( $headers, $this_query ) {
	if ( isset( $_GET[ SHRIMPTEST_QUERY_VARS_PARAMETER ] ) ) {
		$headers[ SHRIMPTEST_QUERY_VARS_HEADER ] = serialize( $this_query->query_vars );
	}
	return $headers;
}

/*
 * shrimptest_metric_conversion_retrieve_query_vars
 */
// Use this function to get serialized query vars for any WordPress 
// shrimptest_metric_conversion_retrieve_query_vars( 'http://shrimptest.local/2010/06/' )
function shrimptest_metric_conversion_retrieve_query_vars( $url ) {
	if ( strpos( $url, '?' ) !== false )
		$url .= "&".SHRIMPTEST_QUERY_VARS_PARAMETER."=1";
	else
		$url .= "?".SHRIMPTEST_QUERY_VARS_PARAMETER."=1";
	$headers = wp_get_http_headers( $url );
	if ( !isset( $headers[ strtolower( SHRIMPTEST_QUERY_VARS_HEADER ) ] ) )
		return false;
	else
		return unserialize( $headers[ strtolower( SHRIMPTEST_QUERY_VARS_HEADER ) ] );
}

