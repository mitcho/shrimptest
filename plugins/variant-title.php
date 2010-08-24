<?php

/*
 * class ShrimpTest_Variant_Title
 */

class ShrimpTest_Variant_Title {
	
	var $label = 'Title';
	// can only be set programmatically:
	var $_programmatic = true;
	
	var $experiment_id_meta_key = '_shrimptest_title_experiment';
	var $titles_meta_key = '_shrimptest_title_titles';
	
	function ShrimpTest_Variant_Title( $shrimptest_instance ) {

		$this->shrimp =& $shrimptest_instance;
		$this->model =& $shrimptest_instance->model;
		$this->interface =& $shrimptest_instance->interface;
		
		add_action( 'admin_menu', array( &$this, 'add_title_metabox' ) );
		add_action( 'edit_page_form', array( &$this, 'edit_style_and_script' ) );
		add_action( 'edit_form_advanced', array( &$this, 'edit_style_and_script' ) );
		add_action( 'save_post', array( &$this, 'save_postdata' ) );

		add_action( 'shrimptest_add_variant_extra', array( &$this, 'admin_add_variant_extra' ) );
		add_action( 'edit_page_form', array( &$this, 'edit_helper' ) );
		add_action( 'edit_form_advanced', array( &$this, 'edit_helper' ) );

		add_filter( 'the_title', array( &$this, 'swap_title' ) , 10, 2 );

	}
	
	function add_title_metabox( ) {
		add_meta_box( 'shrimptest_title', __('Alternative titles', 'shrimptest'), 
                array( &$this, 'title_metabox' ), 'post', 'advanced' );
		add_meta_box( 'shrimptest_title', __('Alternative titles', 'shrimptest'), 
                array( &$this, 'title_metabox' ), 'page', 'advanced' );
	}
	
	function title_metabox( ) {
		global $post_ID;
		// check to see if the experiment is already running. In that case, don't let us change.
		$already_running = false;
		$experiment_id = get_post_meta( $post_ID, $this->experiment_id_meta_key, true );
		if ($experiment_id) {
			$status = $this->model->get_experiment_status( $experiment_id );
			$already_running = $status == 'active' || $status == 'finished';
		}

		if ($already_running)
			echo '<p>' . __('This experiment has already been started so you cannot edit the titles.', 'shrimptest') . '</p>';


		// Use nonce for verification
		echo '<input type="hidden" name="shrimptest_title_nonce" id="shrimptest_title_nonce" value="' . 
    wp_create_nonce( 'shrimptest_title' ) . '" />';

		echo '<table id="shrimptest_titles"' . ($already_running ? ' class="already_running"' : '') . '><tbody>';
		// The actual fields for data entry

		// Print the control
		$control = __('Control','shrimptest');
		$hover = __("Please edit the control title above.","shrimptest");
		echo "<tr><th>{$control}</th><td><span title=\"{$hover}\" id=\"shrimptest_title_control\"></span>";
		if (!$already_running)
			echo "<input type=\"button\" id=\"shrimptest_title_addvariant\" value=\"+\"/>";
		echo "</td></tr>";

		$titles = $this->get_titles();
		
		foreach ( $titles as $id => $title ) {
			if ($id == 0) // we already display the control another way.
				continue;
			$label = __('Variant','shrimptest') . ' ' . $id;
			echo "<tr class='shrimptest_title_row' data-variant='{$id}'><th>{$label}:</th><td>";
			if ($already_running)
				echo esc_html( $title );
			else
				echo "<input type='text' maxlength='255' name='shrimptest_title[{$id}]' id='shrimptest_title_{$id}' class='shrimptest_title' value='" . esc_attr($title) . "'/><input type=\"button\" class=\"shrimptest_title_removevariant\" value=\"-\"/>";
			echo "</td></tr>";
		}

		echo '</tbody></table>';
	}
	
	function save_postdata( $post_ID ) {

		// verify nonce
		if ( !wp_verify_nonce( $_POST['shrimptest_title_nonce'], 'shrimptest_title' )) {
			return $post_ID;
		}

		// don't save on autosave		
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) 
			return $post_ID;

		// check permissions
		if ( 'page' == $_POST['post_type'] ) {
			if ( !current_user_can( 'edit_page', $post_ID ) )
				return $post_ID;
		} else {
			if ( !current_user_can( 'edit_post', $post_ID ) )
				return $post_ID;
		}

		$titles = $_POST['shrimptest_title'];

		// check for a preexisting experiment_id
		$experiment_id = get_post_meta( $post_ID, $this->experiment_id_meta_key, true );
		
		if ( !$experiment_id )
			$experiment_id = get_post_meta( $_POST['ID'], $this->experiment_id_meta_key, true );
		
		// if there is none...
		if ( !$experiment_id ) {
			if ( !count( $titles ) ) // if there were no titles anyway...
				return; // just leave
			$experiment_id = (int) $this->model->get_reserved_experiment_id( );
			update_post_meta( $post_ID, $this->experiment_id_meta_key, $experiment_id );
			$this->model->update_variants_type( $experiment_id, 'title' );
		}

		// check the status and get the variants
		$status = $this->model->get_experiment_status( $experiment_id );
		if ( $status == 'reserved' && count($titles) )
			$this->model->update_experiment_status( $experiment_id, 'inactive' );
		// nothing to do if active or finished
		if ( $status == 'active' || $status == 'finished' )
			return;

		// if no titles were POSTed...
		foreach ( $titles as $key => $title ) {
			if ( !strlen($title) )
				unset( $titles[$key] );
		}
		if ( !count($titles) ) {
			// there is no longer a ShrimpTest title experiment. If there was data, get rid of it.
			delete_post_meta( $post_ID, $this->experiment_id_meta_key );
			$this->model->delete_experiment( $experiment_id );
			return;
		}
		
		if ( $variants == $titles )
			return;

		$variants = array();		

		// make sure there's a control
		$variants[0] = array( 'name' => $_POST['post_title'], 'assignment_weight' => 1 );
		
		foreach ( $titles as $id => $title ) {
			$variants[$id] = array( 'name' => $title, 'assignment_weight' => 1 );
		}

		$this->model->update_experiment_variants( (int) $experiment_id, $variants );
	}
	
	function edit_style_and_script( ) {
?>

<style type="text/css">
#shrimptest_titles td, #shrimptest_titles th {
	padding: 2px;
}
#shrimptest_title_control {
	padding-right: 10px;
	line-height: 2em;
}
#shrimptest_titles.already_running #shrimptest_title_control {
	line-height: 1em;
}
#shrimptest_title_addvariant {
	float: right;
}
</style>
<script type="text/javascript">
jQuery(function($){

	var newVariantId = parseInt($('.shrimptest_title_row').eq(-1).attr('data-variant')) + 1 || 1;
	
	var checkTitle = function(){
		var title = $('#title').val();
		if (title)
			$('#shrimptest_title_control').text(title);
		else
			$('#shrimptest_title_control').html('<em><?php _e("Please edit the control title above.","shrimptest") ?></em>');
	}
	
	$('#title').change(checkTitle);
	checkTitle();
	
	var enforceTitleButtons = function () {
		$('.shrimptest_title_removevariant').hide();
		if (newVariantId > 2)
			$('.shrimptest_title_removevariant').last().show();
	}

	var addVariant = function(){
		$('.shrimptest_title_removevariant').hide();
		
		var newRow = $('<tr class="shrimptest_title_row"><th></th><td><input type="text" class="shrimptest_title" maxlength="255"/><input type="button" class="shrimptest_title_removevariant" value="-"/></td></tr>');

		newRow
			.attr('data-variant',newVariantId)
			.find('th')
				.text("<?php _e('Variant','shrimptest')?> "+newVariantId+':')
				.attr('for','variant['+newVariantId+'][name]')
			.end()
			.find('input.shrimptest_title')
				.attr({id:'shrimptest_title_'+newVariantId, name:'shrimptest_title['+newVariantId+']'})
			.end();

		$('#shrimptest_titles tbody').append(newRow);

		newVariantId++;

		enforceTitleButtons();
	};
	if (newVariantId == 1)
		addVariant();

	$('#shrimptest_title_addvariant').click(addVariant);
	
	$('.shrimptest_title_removevariant').live('click',function(){
		if ( $(this).closest('tr').attr('data-variant') == newVariantId - 1 )
			newVariantId --;
		$(this).closest('tr').remove();
		enforceTitleButtons();
	});
	
	enforceTitleButtons();
});
</script>

<?php

	}
		
	function get_titles( $id = null ) {
		if ( $id == null ) {
			global $post, $post_ID;
			$id = isset($post_ID) ? $post_ID : $post->id;
		}
		
		$experiment_id = get_post_meta( $id, $this->experiment_id_meta_key, true );
		$variants = $this->model->get_experiment_variants( $experiment_id );
		
		return array_map( array( &$this, 'return_name' ), $variants );
	}
	
	function return_name( $arr ) {
		return $arr->variant_name;
	}
	
	function edit_helper( ) {
		global $post_ID;

		$experiment_id = get_post_meta( $post_ID, $this->experiment_id_meta_key, true );
		if ( !$experiment_id )
			return;
		$status = $this->model->get_experiment_status( $experiment_id );
		if ( $status == 'inactive' || $status == 'reserved' ) {
			$edit_url = "admin.php?page=" . $this->shrimp->get_interface_slug() . "&action=new&id={$experiment_id}";
			echo "<div class='updated'><p>" . sprintf(__("This entry includes an inactive experiment. You must <a href='%s'>edit</a> and activate the experiment."),$edit_url) . "</p></div>";
		}
	}

	function admin_add_variant_extra( ) {
		// TODO: add a real link to this message.
		?>
		<tr class="variants_extra variants_extra_title"><td colspan="3"><p><?php _e( "You can edit the variants by visiting the original post/page.", 'shrimptest' );?></p></td></tr>
		<?php
	}
	
	function swap_title( $title, $id ) {
		$experiment_id = get_post_meta( $id, $this->experiment_id_meta_key, true );
		if ( !$experiment_id )
			return $title;

		$variant_id = $this->shrimp->get_visitor_variant( $experiment_id );
		if ( $variant_id == 0 )
			return $title;

		$variant = $this->model->get_experiment_variant( $experiment_id, $variant_id );
		return $variant->variant_name;
	}
	
}

register_shrimptest_variant_type( 'title', 'ShrimpTest_Variant_Title' );
