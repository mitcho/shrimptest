<?php

/*
 * class ShrimpTest_Metric_Conversion
 */

class ShrimpTest_Metric_Conversion extends ShrimpTest_Metric {
	
	var $code = 'conversion';
	var $name = 'Conversion';
	// this setting means that we will try to offer this metric as the default for new experiments:
	var $_default = true;
	
	// variables used for the query_vars-retreiving code to get a more stable representation for
	// detecting conversion hits.
	var $query_vars_header = 'X-ShrimpTest-Query-Vars';
	var $query_vars_parameter = 'shrimptest_query_vars';

	var $prefix = 'shrimptest_metric_conversion_';
		
	function ShrimpTest_Metric_Conversion( $shrimptest_instance ) {

		add_action( 'shrimptest_add_metric_extra', array( &$this, 'admin_add_metric_extra' ), 10, 1 );
		add_action( 'shrimptest_admin_header', array( &$this, 'admin_script_and_style' ) );
		add_filter( 'shrimptest_save_metric_conversion', array( &$this, 'admin_save_filter' ) );

		// Filter for adjusting the aggregate value SQL expression ( max(ifnull(value,0)) )
		// add_filter( 'shrimptest_get_stats_value_conversion' )
		
		add_action( 'parse_request', array( &$this, 'check_conversion' ) );
		
		// Utility function for post query vars retreival
		add_filter( 'wp_headers', array( &$this, 'print_query_headers' ), 10, 2 );

	}

	function admin_add_metric_extra( $metric ) {
		if ( isset( $metric->data['conversion_url'] ) ) {
			$class = '';
			$value = $metric->data['conversion_url'];
		} else {
			$class = 'default';
			$value = get_bloginfo( 'url' );
		}
		// all <tr>'s here must have the class 'metric_extra metric_extra_conversion'
		?>
		<tr class="metric_extra metric_extra_conversion"><th><?php _e('Conversion goal URL:','shrimptest');?></th><td><input class="<?php echo $class; ?>" name="metric[conversion][conversion_url]" id="metric_extra_conversion_conversion_url" type="text" value="<?php echo $value; ?>" size="40" maxlength="255"></input></td></tr>
		<?php
	}
	
	function admin_script_and_style( ) {
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
	
	function admin_save_filter( $metric_data ) {
	
		// first things first, ensure that the $metric_data includes the appropriate parameters for a 
		// conversion-style metric.
		$metric_data['ifnull'] = true;
		$metric_data['nullvalue'] = 0;
		$metric_data['direction'] = 'greater'; // is better
	
		$url = $metric_data['conversion_url'];
		if ( empty( $url ) )
			wp_die( __( 'No conversion URL was specified.', 'shrimptest' ) );
		if ( stripos( $url, get_bloginfo( 'url' ) ) !== 0 )
			wp_die( __( 'The specified conversion URL is not part of your WordPress site. Please go back and enter another.', 'shrimptest' ) );
	
		$query_vars = $this->retrieve_query_vars( $url );
		if ( $query_vars ) {
			// reconstruct the appropriate title, right here, right now.
			// NOTE: this method may be relatively fragile.
			query_posts( $query_vars );
			$title = wp_title( '|', false, 'right' );
			$title = preg_replace( '/^\s*\|?\s*(.*?)\s*\|?\s*$/', '$1', $title );
			$metric_data['name'] = sprintf( __("Conversion: <a href=\"%s\">%s</a>",'shrimptest'), $url, $title );
			$metric_data['conversion_query_vars'] = $query_vars;
	
			// reset the conversion rules cache
			set_site_transient( "{$this->prefix}conversion_rules", null );
	
			return $metric_data;
		}
	
		// else, we have to kick it back to the user saying we couldn't resolve that URL.
		wp_die( __( 'The specified conversion URL is not part of your WordPress site. Please go back and enter another.', 'shrimptest' ) );
	
	}
	
	function check_conversion( $parsed_query ) {
		$rules = $this->get_conversion_rules( );
		foreach ( $rules as $metric_id => $query_vars ) {
			if ( $parsed_query->query_vars == $query_vars ) {
				shrimptest_conversion_success( $metric_id );
			}
		}
	}
	
	function get_conversion_rules( ) {
		if ( !isset($this->shrimp) )
			wp_die( '<code>shrimptest_init</code> has not occured yet.' );
		$rules = get_site_transient( "{$this->prefix}conversion_rules" );
		if ( !$rules || empty( $rules ) ) {
			$rules = array();
			foreach ( $this->shrimp->model->get_metrics( array( 'type' => 'conversion' ) ) as $metric ) {
				if ( isset( $metric->data['conversion_query_vars'] ) )
					$rules[ $metric->metric_id ] = $metric->data['conversion_query_vars'];
			}
			set_site_transient( "{$this->prefix}conversion_rules", $rules );
		}
		return $rules;
	}
	
	function print_query_headers( $headers, $this_query ) {
		if ( isset( $_GET[ $this->query_vars_parameter ] ) ) {
			$headers[ $this->query_vars_header ] = serialize( $this_query->query_vars );
		}
		return $headers;
	}
	
	/*
	 * retrieve_query_vars
	 */
	// Use this function to get serialized query vars for any WordPress 
	// e.g. retrieve_query_vars( 'http://shrimptest.local/2010/06/' )
	function retrieve_query_vars( $url ) {
		if ( strpos( $url, '?' ) !== false )
			$url .= "&{$this->query_vars_parameter}=1";
		else
			$url .= "?{$this->query_vars_parameter}=1";
		$headers = wp_get_http_headers( $url );
		if ( !isset( $headers[ strtolower( $this->query_vars_header ) ] ) )
			return false;
		else
			return unserialize( $headers[ strtolower( $this->query_vars_header ) ] );
	}
	
	function display_value( $value ) {
		return round( $value * 100, 2 ) . '%';
	}

}
