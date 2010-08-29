<?php

/*
 * class ShrimpTest_Variant_Shortcode
 */

class ShrimpTest_Variant_Shortcode {
	
	var $label = 'Shortcode';
	
	// can only be set programmatically:
	var $_programmatic = true;
	
	var $shortcode = 'ab';
	
	var $experiment_ids_meta_key = '_shrimptest_shortcode_experiments';
	var $detected_experiment_ids = array( );
	
	function ShrimpTest_Variant_Shortcode( $shrimptest_instance ) {

		$this->shrimp =& $shrimptest_instance;
		$this->model =& $shrimptest_instance->model;
		$this->interface =& $shrimptest_instance->interface;

		add_shortcode( $this->shortcode, array( &$this, 'shortcode_handler') );
		
		add_filter( 'content_save_pre', array( &$this, 'detection_filter' ), 15 );

		add_action( 'shrimptest_add_variant_extra', array( &$this, 'admin_add_variant_extra' ) );
		add_action( 'shrimptest_admin_header', array( &$this, 'admin_script_and_style' ) );
		
		add_action( 'update_post_meta', array( &$this, 'cleanup_experiments' ), 10, 4 );

		add_action( 'edit_page_form', array( &$this, 'edit_helper' ) );
		add_action( 'edit_form_advanced', array( &$this, 'edit_helper' ) );

		add_action( 'init', array( &$this, 'add_buttons' ) );
		add_action( 'edit_page_form', array( &$this, 'setup_buttons' ) );
		add_action( 'edit_form_advanced', array( &$this, 'setup_buttons' ) );

	}

	function detection_filter( $content ) {
		global $shortcode_tags, $post_id;
		
		// backup shortcodes
		$real_shortcodes = $shortcode_tags;
		// load the detection shortcode
		remove_all_shortcodes();
		add_shortcode( $this->shortcode, array( &$this, 'process_detected_shortcode' ) );

		// do_shortcode, but we don't actually care about this output, so don't collect it.
		do_shortcode( stripslashes( $content ) );

		// reinstate the actual shortcodes.
		$shortcode_tags = $real_shortcodes;
		
		update_post_meta( $post_id, $this->experiment_ids_meta_key, array_unique( $this->detected_experiment_ids ) );

		$this->shortcode_replacement_count = 0;
		$content = preg_replace_callback( '/(\['.$this->shortcode.'\s*(id=(\d+))?\s*)/', array( &$this, 'add_id_to_shortcode' ), $content );

		return $content;		
	}
	
	function add_id_to_shortcode( $matches ) {
		$count = $this->shortcode_replacement_count;
		$this->shortcode_replacement_count++;
		// $matches[3] is the given ID value.
		if ( !empty( $matches[3] ) ) {
			if ( $this->detected_experiment_ids[$count] == $matches[3] )
				return $matches[0];
			else // ID value is different
				return "[{$this->shortcode} id=" . $this->detected_experiment_ids[$count] . ' ';
		}		
		return $matches[0] . 'id=' . $this->detected_experiment_ids[$count] . ' ';
	}

	function shortcode_handler( $args, $content=null ) {
	
		if ( !isset( $args['id'] ) )
			wp_die( sprintf( __('The <code>[%s]</code> shortcode must be used with the attribute <code>id</code>, for example <code>[%s id=4 a=\'blah\']</code>','shrimptest'), $this->shortcode, $this->shortcode ) );
		$experiment_id = $args['id'];

		$variant_id = $this->shrimp->get_visitor_variant( $experiment_id );
		$variant = $this->model->get_experiment_variant( $experiment_id, $variant_id );
		return $variant->data['value'];
		
	}

	function process_detected_shortcode( $args, $content ) {

		if ( isset( $args['id'] ) ) {
			$experiment_id = $args['id'];
			unset( $args['id'] );
		} else { // create a new experiment
			$experiment_id = $this->model->get_reserved_experiment_id( );
			$this->model->update_variants_type( $experiment_id, 'shortcode' );
		}
		$this->detected_experiment_ids[] = $experiment_id;
		// make sure that this experiment is a shortcode-variant experiment.
			
		$status = $this->model->get_experiment_status( $experiment_id );
		if ( $status == 'reserved' && count( $args ) )
			$this->model->update_experiment_status( $experiment_id, 'inactive' );
		$variants = $this->model->get_experiment_variants( $experiment_id );

		$variant_names = array();
		$next_variant_id = 0;
		foreach ( $variants as $id => $variant ) {
			$variant_names[ $id ] = $variant->variant_name;
			if ( $variant->variant_id > $next_variant_id )
				$next_variant_id = $variant->variant_id;
		}
		$next_variant_id++;
		
		// if the experiment is inactive or reserved, we can store these values.
		if ( $status == 'inactive' || $status == 'reserved' ) {
			
			// look at the variants
			$variant_ids_in_args = array();
			foreach( $args as $name => $variant ) {
				if ($name == 'control') { // the "control" label is special
					$variant_id = 0;
					$variant_data = array( 'name' => $name, 'assignment_weight' => 1, 'value' => $variant );
				} else {
					$index = array_search( $name, $variant_names );
					if ( $index !== false ) {
						$variant_id = $variants[$index]->variant_id;
						$variant_data = $variants[$variant_id];
	
						if ( $variant_data->data['value'] == $variant )
							continue; // no need to update
	
						$variant_data = array( 'name' => $variant_data->variant_name,
															'assignment_weight' => $variant_data->assignment_weight,
															'value' => $variant );
					} else {
						$variant_id = $next_variant_id ++;
						$variant_data = array( 'name' => $name, 'assignment_weight' => 1, 'value' => $variant );
					}
				}

				// keep track of the variants which were in the args
				$variant_ids_in_args[] = $variant_id;
				// update
				$this->model->update_experiment_variant( (int) $experiment_id, $variant_id, $variant_data );
			}
			
			// if some variant is no longer in the shortcode, it must have been removed. remove it.
			foreach ( $variants as $id => $variant ) {
				if ( array_search( $id, $variant_ids_in_args ) === false )
					$this->model->delete_experiment_variant( $experiment_id, $id );
			}
			$next_variant_id++;
			
		} else { // if this experiment is already active, this is bad news!
			// TODO: add a way to then go back and mark this variant as "defective" or something
		}			

	}

	function cleanup_experiments( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( $meta_key != $this->experiment_ids_meta_key )
			return;
		$old_value = get_metadata( 'post', $object_id, $meta_key );
		if ( count( $old_value ) ) {
			foreach( $old_value[0] as $old_experiment_id ) {
				// if we're no longer associating this experiment with this post.
				if ( array_search($old_experiment_id, $meta_value) === false ) {
					$status = $this->model->get_experiment_status( $old_experiment_id );
					if ( $status == 'active' ) {
						wp_die( __("You cannot remove the reference to experiment %d as it is currently active. Your post update has been cancelled.",'shrimptest') );
					} else if ( $status == 'reserved' || $status == 'inactive' ) {
						// TODO: check if this experiment is used elsewhere?
						$this->model->delete_experiment( $old_experiment_id );
					}
				}
			}
		}
	}

	function admin_add_variant_extra( ) {
		// TODO: add a real link to this message.
		?>
		<tr class="variants_extra variants_extra_shortcode"><td colspan="3"><p><?php _e( "You can edit the variants by visiting the original post/page.", 'shrimptest' );?></p></td></tr>
		<?php
	}
	
	function admin_script_and_style( ) {
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			if ($('#variants_type').val() == '<?php echo $this->name; ?>') {
				$('#addvariant').hide();
				$('.removevariant').hide();
			}
		});
		</script>
		<style type="text/css">
		#shrimptest_variant_shortcode td, #shrimptest_variant_shortcode th {
			padding: 2px;
		}
		#shrimptest_variant_shortcode_addvariant {
			float: right;
		}
		</style>		
		<?php
	}
		
	function edit_helper( ) {
		global $post_ID;
		$experiment_ids = get_post_meta( $post_ID, $this->experiment_ids_meta_key );
		if ( !count( $experiment_ids ) )
			return;
		$experiment_ids = $experiment_ids[0];
		foreach ( $experiment_ids as $experiment_id ) {
			$status = $this->model->get_experiment_status( $experiment_id );
			if ( $status == 'inactive' || $status == 'reserved' ) {
				$edit_url = "admin.php?page=" . $this->shrimp->get_interface_slug() . "&action=new&id={$experiment_id}";
				echo "<div class='updated'><p>" . sprintf(__("This entry includes an inactive experiment. You must <a href='%s'>edit</a> and activate the experiment."),$edit_url) . "</p></div>";
			}
		}
	}
	
	/**
	 * A/B button in the editor: some code based on Ratings Shorttags by
	 * Joen Asmussen, GPL
	 */
	function add_buttons( ) {
		// Don't bother doing this stuff if the current user lacks permissions
		if ( ! current_user_can('edit_posts') && ! current_user_can('edit_pages') )
		 return;

		// load scripts
		if ( is_admin() ) {
			wp_enqueue_style('thickbox');
			wp_enqueue_script('thickbox');
			wp_register_script('shrimptest_variant_shortcode_admin_script', SHRIMPTEST_URL . 'plugins/variant-shortcode/admin.js');
			wp_enqueue_script('shrimptest_variant_shortcode_admin_script');
		}
		
		// In rich editor mode...
		if ( get_user_option('rich_editing') == 'true') {
			add_filter( 'mce_external_plugins', array( &$this, 'add_tinymce_script' ) );
			add_filter( 'mce_buttons', array( &$this, 'register_button' ) );
			add_filter( 'teeny_mce_buttons', array( &$this, 'register_button' ) );
		}
	}
	
	function add_tinymce_script( $plugins ) {
		$plugin_array['abtest'] = SHRIMPTEST_URL . 'plugins/variant-shortcode/tinymce.js';
		return $plugin_array;
	}
	
	function register_button( $buttons ) {
		array_push($buttons, '|', 'abtest');
		return $buttons;
	}
	
	function setup_buttons( ){
	?>
		<div id="shrimptest_variant_shortcode" class="hidden">
			<table><tbody>
	<?php
		// The actual fields for data entry

		$variants = array(0=>'');
		
		foreach ( $variants as $id => $variant ) {
			$label = __('Variant','shrimptest') . ' ' . $id;
			if ($id == 0)
				$label = __('Control','shrimptest');
			echo "<tr class='shrimptest_variant_shortcode_row' data-variant='{$id}'><th>{$label}:</th><td>";
			echo "<input type='text' maxlength='255' name='shrimptest_variant_shortcode[{$id}]' id='shrimptest_variant_shortcode_{$id}' class='shrimptest_variant_shortcode' value='" . esc_attr($variant) . "'/><input type=\"button\" class=\"shrimptest_variant_shortcode_removevariant\" value=\"-\"/>";
			if ($id == 0)
				echo '<input type="button" id="shrimptest_variant_shortcode_addvariant" value="+"/>';
			echo "</td></tr>";
		}
	?>
			</tbody></table>
			<input id="shrimptest_variant_shortcode_insert" type="submit" class="button-primary" value="<?php _e("Insert experiment", "shrimptest"); ?>"></input>
			<input id="shrimptest_variant_shortcode_cancel" type="submit" class="button" value="<?php _e("Cancel", "shrimptest"); ?>"></input>
		</div>
		<div class="hidden" id="shrimptest_variant_shortcode_strings">
			<span data-id="thickbox_title"><?php _e("Add ShrimpTest Experiment", "shrimptest") ?></span>
			<span data-id="button_title"><?php _e("Insert a new A/B test", "shrimptest") ?></span>
			<span data-id="button_label"><?php _e("A/B", "shrimptest") ?></span>
			<span data-id="variant"><?php _e("Variant", "shrimptest") ?></span>
			<span data-id="variant_label_prefix"><?php _e("variant", "shrimptest") ?></span>
			<span data-id="control_label"><?php _e("control", "shrimptest") ?></span>
		</div>
	<?php
	}
}

register_shrimptest_variant_type( 'shortcode', 'ShrimpTest_Variant_Shortcode' );
