<?php
/**
 * ShrimpTest Conversion metric type
 *
 * Implements the ShrimpTest Conversion metric type.
 *
 * @author mitcho (Michael Yoshitaka Erlewine) <mitcho@mitcho.com>, Automattic
 * @package ShrimpTest
 * @subpackage ShrimpTest_Metric_Conversion
 */

/**
 * ShrimpTest Conversion metric type class
 *
 * An object-oriented metric type specification. This class name is handed to
 * {@link register_shrimptest_metric_type()} at the end of this file so it is registered.
 *
 * Many of the properties in the resulting object are there as
 * {@link register_shrimptest_metric_type()} expects them.
 *
 * @link http://shrimptest.com/docs/variant-and-metric-api/
 */
class ShrimpTest_Metric_Conversion {
	
	/**
	 * The internal metric designation
	 * @var string
	 */
	var $name = 'conversion';

	/**
	 * A user-facing metric type string
	 * @var string
	 */
	var $label = 'Conversion';
	/**
	 * This setting means that we will try to offer this metric as the default for new experiments:
	 * @var bool
	 */
	var $_default = true;

	/**#@+
	 * variables used for the query_vars-retreiving code to get a more stable representation for
	 * detecting conversion hits.
	 * @var string
	 */
	var $query_vars_header = 'X-ShrimpTest-Query-Vars';
	var $query_vars_parameter = 'shrimptest_query_vars';
	/**#@-*/

	/**
	 * A prefix used for constructing a transient id
	 * @var string
	 */
	var $prefix = 'shrimptest_metric_conversion_';

	/**
	 * Constructor
	 *
	 * Set up actions and filters. Gets called in {@link register_shrimptest_metric_type()}.
	 *
	 * @param ShrimpTest
	 */		
	function ShrimpTest_Metric_Conversion( $shrimptest_instance ) {

		$this->label = __('Conversion','shrimptest');

		$this->shrimp =& $shrimptest_instance;
		$this->model =& $shrimptest_instance->model;
		$this->interface =& $shrimptest_instance->interface;

		add_action( 'shrimptest_add_metric_extra', array( &$this, 'admin_add_metric_extra' ), 10, 1 );
		add_action( 'shrimptest_admin_header', array( &$this, 'admin_script_and_style' ) );
		add_filter( 'shrimptest_save_metric_conversion', array( &$this, 'admin_save_filter' ) );

		add_action( 'parse_request', array( &$this, 'check_conversion' ) );
		
		// Utility function for post query vars retreival
		add_filter( 'wp_headers', array( &$this, 'print_query_headers' ), 10, 2 );
		
		add_filter( 'shrimptest_display_metric_conversion_value', array( &$this, 'display_value' ), 10, 3 );

	}

	/**
	 * Print the "metric extra" rows in the "Add new experiment" screen
	 *
	 * @param object
	 * @link http://shrimptest.com/docs/variant-and-metric-api/
	 */
	function admin_add_metric_extra( $experiment ) {
		if ( isset( $experiment->data['conversion_url'] ) ) {
			$class = '';
			$value = $experiment->data['conversion_url'];
		} else {
			$class = 'default';
			$value = get_bloginfo( 'url' );
		}
		// all <tr>'s here must have the class 'metric_extra metric_extra_conversion'
		?>
		<tr class="metric_extra metric_extra_conversion"><th><?php _e('Conversion goal URL:','shrimptest');?></th><td><input class="<?php echo $class; ?>" name="metric[conversion][conversion_url]" id="metric_extra_conversion_conversion_url" type="text" value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr(get_bloginfo( 'url' ));?>" size="40" maxlength="255"></input></td></tr>
		<input id="metric_extra_conversion_sd" type="hidden" value="0.5"/>
		<?php
		// The maximum standard deviation possible with a bernoulli trial (0.5) is hidden
	}
	
	/**
	 * Add styling and JavaScript for the "Add new experiment" screen
	 */
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
	
	/**
	 * Prepare the given conversion metric specification for saving.
	 *
	 * @link http://shrimptest.com/docs/variant-and-metric-api/
	 * @uses retrieve_query_vars()
	 * @param array
	 * @return array
	 */
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
			@query_posts( $query_vars );
			$title = @wp_title( '|', false, 'right' );
			$title = preg_replace( '/^\s*\|?\s*(.*?)\s*\|?\s*$/', '$1', $title );
			$metric_data['metric_name'] = sprintf( __("Conversion: %s",'shrimptest'), $title );
			$metric_data['conversion_query_vars'] = $query_vars;
	
			// reset the conversion rules cache
			set_site_transient( "{$this->prefix}conversion_rules", null );
	
			return $metric_data;
		}
	
		// else, we have to kick it back to the user saying we couldn't resolve that URL.
		wp_die( __( 'The specified conversion URL is not part of your WordPress site. Please go back and enter another.', 'shrimptest' ) );
	}
	
	/**
	 * Check whether we have hit upon any conversion metrics' goal URL's.
	 *
	 * If we have, then record that conversion using {@link shrimptest_conversion_success}.
	 *
	 * @uses shrimptest_conversion_success()
	 * @uses get_conversion_rules()
	 * @param array
	 */
	function check_conversion( $parsed_query ) {
		$rules = $this->get_conversion_rules( );
		foreach ( $rules as $metric_id => $query_vars ) {
			if ( $parsed_query->query_vars == $query_vars ) {
				shrimptest_conversion_success( $metric_id );
			}
		}
	}

	/**
	 * Get the "conversion rules": the collection of query vars that we are looking
	 * out for, as they represent conversion metric conversions.
	 *
	 * @uses ShrimpTest_Model::get_experiments()
	 * @return array
	 */	
	function get_conversion_rules( ) {
		if ( !isset($this->shrimp) )
			wp_die( '<code>shrimptest_init</code> has not occured yet.' );
		$rules = get_site_transient( "{$this->prefix}conversion_rules" );
		if ( !$rules || empty( $rules ) ) {
			$rules = array();
			foreach ( $this->shrimp->model->get_experiments( array( 'metric_type' => 'conversion' ) ) as $experiment ) {
				if ( isset( $experiment->data['conversion_query_vars'] ) )
					$rules[ $experiment->experiment_id ] = $experiment->data['conversion_query_vars'];
			}
			set_site_transient( "{$this->prefix}conversion_rules", $rules );
		}
		return $rules;
	}
	
	/**
	 * Add special headers representing the WP query_vars if a HEAD request was made
	 * with the {@link $query_vars_parameter} parameter.
	 *
	 * @param array
	 * @param array
	 * @return array
	 */	
	function print_query_headers( $headers, $this_query ) {
		if ( isset( $_GET[ $this->query_vars_parameter ] ) ) {
			$headers[ $this->query_vars_header ] = serialize( $this_query->query_vars );
		}
		return $headers;
	}
	
	/**
	 * Use this function to get serialized query vars for any WordPress 
	 *
	 * Example:
	 * <code>retrieve_query_vars( 'http://shrimptest.local/2010/06/' )</code>
	 *
	 * @param string
	 * @return array
	 */
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
	
	/**
	 * Displays a display-friendly value for the given {@link $value}: in this case,
	 * a percentage representation.
	 *
	 * @link http://shrimptest.com/docs/variant-and-metric-api/
	 * @param mixed
	 * @param float
	 * @param float
	 * @return string
	 */
	function display_value( $value, $original_value, $raw ) {
		return round( $original_value * 100, 2 ) . '% <span class="rawvalue" alt="raw total">' . $raw . '</span>';
	}

}

register_shrimptest_metric_type( 'conversion', 'ShrimpTest_Metric_Conversion' );
