<?php
/**
 * ShrimpTest Interface class file
 *
 * @author mitcho (Michael Yoshitaka Erlewine) <mitcho@mitcho.com>, Automattic
 * @package ShrimpTest
 */

/**
 * ShrimpTest Interface class
 *
 * Implements the default ShrimpTest UI
 *
 * @package ShrimpTest
 */
class ShrimpTest_Interface {

	/**
	 * Reference to the local {@link ShrimpTest} Core instance
	 * @var ShrimpTest
	 */
	var $shrimp; // Core
	/**
	 * Reference to the local {@link ShrimpTest_Model} instance
	 * @var ShrimpTest_Model
	 */
	var $model;  // Model

	/**
	 * The interface slug
	 *
	 * This is the prefix on all ShrimpTest page names in wp-admin
	 *
	 * @var string
	 */
	var $slug;

	/**#@+
	 * message ID's
	 * @var int
	 */
	var $message_save = 1;
	var $message_fail = 2;
	var $message_activated = 3;
	var $message_concluded = 4;
	var $message_deleted = 5;
	/**#@-*/

	/**
	 * Dummy constructor.
	 *
	 * Hint: run {@link init()}
	 */
	function ShrimpTest_Interface( ) {
	}

	/**
	 * Initialization
	 *
	 * Sets {@link $slug} to be, by default, 'shrimptest' and
	 * registers some actions.
	 *
	 * @param ShrimpTest
	 * @todo include the delimiter _ in the slug:
	 * @filter shrimptest_interface_slug
	 */
	function init( &$shrimptest_instance ) {
		$this->shrimp = &$shrimptest_instance;
		
		$this->slug = apply_filters( 'shrimptest_interface_slug', 'shrimptest' );
		
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );

		// various types of admin bar hooks for the variant viewer
		// WordPress 3.1 Admin Bar:
		add_action( 'admin_bar_menu', array( &$this, 'admin_bar' ), 60 );
		// WP Admin Bar plugin:
		add_filter( 'wpabar_menuitems', array( &$this, 'filter_wpadminbar' ) );
		// fallback:
		add_action( 'wp_footer', array( &$this, 'default_widget' ) );
	}
	
	/**
	 * Register the ShrimpTest admin pages
	 */
	function admin_menu( ) {
		$icon = SHRIMPTEST_URL . '/shrimp.png';
		$experiments = add_menu_page( 'ShrimpTest Experiments', 'ShrimpTest', 'manage_options', $this->slug, array( &$this, 'admin_experiments' ), $icon );
		$settings = add_submenu_page( $this->slug, 'ShrimpTest Settings', 'Settings', 'manage_options', "{$this->slug}_settings", array( &$this, 'admin_settings' ) );
		
		add_action( 'admin_init', array( &$this, 'admin_new_experiment_redirect' ) );
		add_action( 'admin_head-'. $settings, array( &$this, 'admin_header' ) );
		add_action( 'admin_head-'. $experiments, array( &$this, 'admin_header' ) );
	}
	
	/**
	 * Print some styles and JavaScript for all ShrimpTest admin pages
	 *
	 * @action shrimptest_admin_header
	 */
	function admin_header( ) {
		$icon = SHRIMPTEST_URL . '/shrimp-large.png';
		echo "<style type=\"text/css\">
		#icon-shrimptest {background: url($icon) no-repeat center center}
		tr.variant td {padding-left: 15px;}
		table.shrimptest th {
			width: 100px;
			font-size: 11px;
			line-height: 16px;
		}
		.samplecodediv {
			min-width: 300px;
			float: right;
			border-left: #dfdfdf 1px solid;
			padding: 5px;
			padding-left: 10px;
		}
		.samplecodediv h4 {
			margin-top: 0px;
		}
		.samplecode {
			font-family: monospace;
			padding: 5px;
			background: #f6f6f6;
		}
		#poststuff .inside {
			overflow: auto;
		}
		</style>
		<script>
			jQuery(document).ready(function($) {
				$('.postbox').children('h3, .handlediv').click(function(){
					$(this).siblings('.inside').toggle();
				});
			});
		</script>";	
		
		do_action( 'shrimptest_admin_header' );
	}
	
	/**
	 * Redirect the user to, or include the PHP for, the appropriate ShrimpTest
	 * "experiment" page, depending on whether we're viewing multiple experiments,
	 * saving an experiment, creating a new experiment, etc.
	 *
	 * @uses $slug
	 * @uses ShrimpTest_Model::get_experiment_status()
	 * @uses ShrimpTest_Model::update_experiment_status()
	 * @uses ShrimpTest_Model::delete_experiment()
	 * @uses ShrimpTest_Model::get_reserved_experiment_id()
	 */
	function admin_new_experiment_redirect( ) {

		if ( !isset( $_GET['page'] ) || $_GET['page'] != "{$this->slug}" )
			return;

		if ( isset($_GET['action']) && $_GET['action'] == 'activate' ) {
			$experiment = $_REQUEST['id'];
			$nonce = $_REQUEST['_wpnonce'];
			if ( !wp_verify_nonce($nonce, 'activate-experiment_' . $experiment) )
				wp_die( "That's nonce-ence." );
			$status = $this->model->get_experiment_status( $experiment );
			if ( $status != 'inactive' )
				wp_die( "This experiment cannot be activated. Please edit it first." );

			$this->model->update_experiment_status( $experiment, 'active' );
			wp_redirect( admin_url("admin.php?page={$this->slug}&message=" . $this->message_activated) );
		}

		if ( isset($_GET['action']) && $_GET['action'] == 'delete' ) {
			$experiment = $_REQUEST['id'];
			$nonce = $_REQUEST['_wpnonce'];
			if ( !wp_verify_nonce($nonce, 'delete-experiment_' . $experiment) )
				wp_die( "That's nonce-ence." );
			$status = $this->model->get_experiment_status( $experiment );
			if ( $status != 'inactive' && $status != 'finished' )
				wp_die( "This experiment cannot be deleted. Please conclude it first." );

			$this->model->delete_experiment( $experiment );
			wp_redirect( admin_url("admin.php?page={$this->slug}&message=" . $this->message_deleted) );
		}


		if ( isset($_GET['action']) && $_GET['action'] == 'conclude' ) {
			$experiment = $_REQUEST['id'];
			$nonce = $_REQUEST['_wpnonce'];
			if ( !wp_verify_nonce($nonce, 'conclude-experiment_' . $experiment) )
				wp_die( "That's nonce-ence." );
			$status = $this->model->get_experiment_status( $experiment );
			if ( $status != 'active' )
				wp_die( "This experiment cannot be concluded." );

			$this->model->update_experiment_status( $experiment, 'finished' );
			wp_redirect( admin_url("admin.php?page={$this->slug}&message=" . $this->message_concluded) );
		}

		if ( isset( $_REQUEST['submit'] ) ) {
			$nonce = $_REQUEST['_wpnonce'];
			if ( !wp_verify_nonce($nonce, 'shrimptest_submit_new_experiment') )
				wp_die( "That's nonce-ence." );
			
			include SHRIMPTEST_DIR . '/admin/experiment-save.php';
			exit;
		}
		
		if ( isset($_GET['action']) && $_GET['action'] == 'new' && !isset($_GET['id']) ) {
			$experiment_id = $this->model->get_reserved_experiment_id( );
			wp_redirect( $_SERVER['REQUEST_URI']."&id={$experiment_id}" );
			exit;
		}
	}
	
	/**
	 * Include the settings page
	 */
	function admin_settings( ) {
		include SHRIMPTEST_DIR . '/admin/settings.php';
	}

	/**
	 * Include the experiments display page
	 */
	function admin_experiments( ) {
		if ( !current_user_can('manage_options') )
			wp_die( __('You do not have sufficient permissions to access this page.') );

		if ( isset( $_GET['action'] ) && $_GET['action'] == 'new' )
			include SHRIMPTEST_DIR . '/admin/experiment-new.php';
		else
			include SHRIMPTEST_DIR . '/admin/experiments.php';
	}

	/**
	 * Handler for adding the variant preview feature.
	 *
	 * Checks to see if the WP Admin Bar plugin is installed, or if the WordPress
	 * 3.1 Admin Bar functionality exists. If not, we run
	 * {@link print_shrimptest_widget()}.
	 *
	 * @link http://www.viper007bond.com/wordpress-plugins/wordpress-admin-bar/
	 * @global wpdb
	 * @global WPAdminBar
	 * @global wp_admin_bar
	 * @uses ShrimpTest::has_been_touched()
	 * @uses print_shrimptest_widget()
	 */
	function default_widget( ) {
		global $wpdb, $WPAdminBar, $wp_admin_bar;
		if ( is_user_logged_in( ) && $this->shrimp->has_been_touched( ) ) {
			if ( empty( $WPAdminBar ) && empty( $wp_admin_bar ) )
				$this->print_shrimptest_widget( );
		}
	}
	
	/**
	 * Adds the ShrimpTest variant preview widget
	 *
	 * Only still exists for WordPress 3.0.x support. Will be deprecated in a
	 * future release.
	 *
	 * @uses get_menus()
	 */
	function print_shrimptest_widget( ) {

		$icon = SHRIMPTEST_URL . '/shrimp.png';
		$menus = $this->get_menus( );
?>
<style type="text/css">
#shrimptest-menu {
position: fixed;
top: 0pt;
color: #fff;
left: 0pt;
text-align: left;
}
#shrimptest-menu li.brand {
/*cursor: default;*/
border-left: none;
}
#shrimptest-menu ul {
list-style: none outside none !important;
padding: 0;
margin: 0;
font: 12px/28px "Lucida Grande","Lucida Sans Unicode",Tahoma,Verdana;
}
#shrimptest-menu li {
	margin: 0;
display: inline;
display: inline-block;
background-color: #333;
border-left: 2px solid rgb(170, 170, 170);
cursor: hand;
cursor: pointer;
font-weight: bold;
float: left;
}
#shrimptest-menu sup, #shrimptest-menu sub {
	font-size: 0.7em;
}
#shrimptest-menu li a, #shrimptest-menu li span {
text-decoration: none;
color: #fff;
padding: 0 0.75em;
display: inline-block;
height: 28px;
text-shadow: -1px -1px 2px rgba(0,0,0,0.2);
}
#shrimptest-menu li:hover {
	background-color: #666;
}
#shrimptest-menu li ul {
	border: 1px solid #999;
	display: none;
}
#shrimptest-menu li ul li {
	background-color: #eee;
	border-left: none;
}
#shrimptest-menu li ul li a, #shrimptest-menu li ul li span {
	min-width: 220px;
	display: block;
	color: #333;
	font-weight: normal;
	text-shadow: -1px -1px 1px rgba(0,0,0,0.1);
}
#shrimptest-menu li:hover ul {
	display: block;
	position: absolute;
}

#shrimptest-menu li ul li:hover {
	background-color: #ccc;
}

</style>
<div id="shrimptest-menu">
<ul>
<li class="brand"><a href="<?php echo admin_url('admin.php?page=' . $this->slug);?>"><?php _e('ShrimpTest','shrimptest');?></a></li><?php
	foreach ( $menus as $key => $menu ) {

		echo "<li>";
		if ( empty( $key ) )
			$str = "<span>{$menu[0]['title']}</span>";
		else
			$str = "<a href=\"" . admin_url($key) . "\">{$menu[0]['title']}</a>";
		echo "{$str}";
		
		if ( count($menu) > 1 ) {
			echo "<ul>";
			foreach ( $menu as $key => $menuitem ) {
				if ( $menuitem == $menu[0] )
					continue;
				if ( empty( $key ) )
					$str = "<span>{$menuitem['title']}<span>";
				else
					$str = "<a href=\"" . admin_url($key) . "\">{$menuitem['title']}</a>";
				echo "<li>{$str}</li>";
			}
			echo "</ul>";
		}
		echo "</li>";
	}
?></ul>
</div>
<?php
	}
	
	/**
	 * Get the "menu items" array for use by WP Admin Bar.
	 *
	 * If WP Admin Bar is not loaded, {@link print_shrimptest_widget()} can also use
	 * this output, though.
	 *
	 * @todo display metric name in the metric menu
	 * @uses ShrimpTest::get_touched_experiments()
	 * @uses ShrimpTest_Model::get_experiment()
	 * @uses ShrimpTest_Model::get_experiment_variants()
	 * @uses ShrimpTest::get_touched_metrics()
	 * @return array
	 */
	function get_menus( ) {
		$menus = array();
		$touched_experiments = $this->shrimp->get_touched_experiments( );
		if ( !empty( $touched_experiments ) ) {
			$experiments = array( array( 'id'=>'20', 'title'=>__('<sup>A</sup>/<sub>B</sub>','shrimptest'), 'custom'=>false ) );

			foreach( $touched_experiments as $experiment_id => $data ) {
				$experiment = $this->model->get_experiment( $experiment_id );
				if (!$experiment)
					continue;
				$experiments["admin.php?page={$this->slug}&action=new&id={$experiment_id}"] = array(
					'id'=>$experiment_id,
					'title'=>__('Experiment','shrimptest') . " {$experiment_id}: {$experiment->name} <small>(" . __('status','shrimptest') . ": {$experiment->status})</small>",
					'custom'=>false );
				
				// display each of the variants
				foreach ( $this->model->get_experiment_variants( $experiment_id ) as $variant ) {
					if ( $variant->variant_id == 0 )
						$title = __('Control','shrimptest');
					else
						$title = __('Variant','shrimptest') . " {$variant->variant_id}";

					// add variant name
					if ( !empty( $variant->variant_name ) )
						$title .= ": {$variant->variant_name}";

					if ( $data['variant'] == $variant->variant_id )
						$title = __('&#x2714;','shrimptest') . " {$title}"; // checkmark
					else
						$title = __('&#x3000;','shrimptest') . " {$title}"; // full-width space
						
					$experiments["admin-ajax.php?action={$this->slug}_override_variant" . 
												"&experiment_id={$experiment_id}" . 
												"&variant_id={$variant->variant_id}" .
												"&referer={$_SERVER['REQUEST_URI']}"]
						= array(
						'id'=>$variant->variant_id,
						'title'=>$title,
						'custom'=>false );					
				}
			}
			$menus[] = $experiments;
		}

		$touched_metrics = $this->shrimp->get_touched_metrics( );
		if ( !empty( $touched_metrics ) ) {
			$metrics = array( array( 'id'=>'21',
			                         'title'=>__('&#x2605;','shrimptest'),
			                         'custom'=>false ) );

			foreach( $touched_metrics as $experiment_id => $data ) {
				$experiment = $this->model->get_experiment( $experiment_id );
				if ( !$experiment )
					continue;

				$title = __('Experiment','shrimptest') . ' ' . $experiment_id . ' metric';
				if ( isset( $data['value'] ) )
					$title .= "<br/><small>(" . __('value','shrimptest') . ": {$data['value']})</small>";

				$metrics["admin.php?page={$this->slug}&action=new&id={$experiment_id}"]
				  = array( 'id' => $experiment_id,
										'title' => $title,
										'custom' => false );
			}
			$menus[] = $metrics;
		}

		return $menus;
	}
	
	/**
	 * If WP Admin Bar is installed, filter the Admin Bar info, splicing our
	 * menu items in for the variant preview feature.
	 *
	 * @uses get_menus()
	 */
	function filter_wpadminbar( $menus ) {

		// we want to be on the left side of the menu, so find the magical point where we're on the
		// right edge of the left side.
		$i = 0;
		foreach ( $menus as $key => $menu ) {
			if ( $menu[0]['id'] > 39 )
				break;
			$i++;
		}
		// now $i has the index for where we want to splice in our new menus.

		// now splice in our dynamic ShrimpTest menus.
		array_splice( $menus, $i, 0, $this->get_menus( ) );
		return $menus;
	}
	
	/**
	 * If Admin Bar functionality (WordPress 3.1) is available, add our menus there.
	 *
	 * @uses get_menus()
	 * @uses WP_Admin_Bar::add_menu()
	 */
	function admin_bar( ) {
		global $wp_admin_bar;
				
		$menus = $this->get_menus();
		$id = 0;
		foreach ( $menus as $key => $menu ) {
			$id++;	
			$wp_admin_bar->add_menu( array( 'id' => 'shrimptest_' . $id,
			                                'title' => $menu[0]['title'],
			                                'href' => empty($key) ? null : admin_url($key) ) );
			
			if ( count($menu) > 1 ) {
				foreach ( $menu as $key => $menuitem ) {
					if ( $menuitem == $menu[0] )
						continue;

					$wp_admin_bar->add_menu( array( 'parent' => 'shrimptest_' . $id,
					                                'title' => $menuitem['title'],
					                                'href' => empty($key) ? null : admin_url($key) ) );
				}
			}
		}
	}

} // class ShrimpTest_Interface
