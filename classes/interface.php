<?php

/*
 * class ShrimpTest_Interface
 * Implements the default ShrimpTest UI
 */
class ShrimpTest_Interface {

	var $shrimp;

	function ShrimpTest_Interface( ) {
		// Hint: run init( )
	}

	function init( &$shrimptest_instance ) {
		$this->shrimp = &$shrimptest_instance;
		
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );

		add_action( 'wp_footer', array( &$this, 'do_adminbar' ) );
		add_filter( 'wpabar_menuitems', array( &$this, 'filter_adminbar' ) );
				
	}
	
	function admin_menu( ) {
		// $icon = plugins_url( null, __FILE__ ) . '/shrimp.png';
		// TODO: fix this:
		$icon = WP_PLUGIN_URL . '/shrimptest/shrimp.png';
		$slug = 'shrimptest';
		$dashboard = add_menu_page( 'ShrimpTest Dashboard', 'ShrimpTest', 'manage_options', $slug, array( &$this, 'admin_dashboard' ), $icon );
		
		$settings = add_submenu_page( $slug, 'ShrimpTest Settings', 'Settings', 'manage_options', "{$slug}_settings", array( &$this, 'admin_settings' ) );
		$experiments = add_submenu_page( $slug, 'ShrimpTest Experiments', 'Experiments', 'manage_options', "{$slug}_experiments", array( &$this, 'admin_experiments' ) );
		
		add_action( 'admin_head-'. $dashboard, array( &$this, 'admin_header' ) );
		add_action( 'admin_head-'. $settings, array( &$this, 'admin_header' ) );
		add_action( 'admin_head-'. $experiments, array( &$this, 'admin_header' ) );
		
	}
	
	function admin_header( ) {
		// TODO: fix URL path
		$icon = WP_PLUGIN_URL . '/shrimptest/shrimp-large.png';
		echo "<style type=\"text/css\">
		#icon-shrimptest {background: url($icon) no-repeat center center}
		tr.variant td {padding-left: 15px;}
		</style>
		<script>
			jQuery(document).ready(function($) {
				$('.postbox').children('h3, .handlediv').click(function(){
					$(this).siblings('.inside').toggle();
				});
			});
		</script>";
		
	}
	
	function admin_dashboard( ) {
		include SHRIMPTEST_DIR . '/admin/dashboard.php';
	}

	function admin_settings( ) {
		include SHRIMPTEST_DIR . '/admin/settings.php';
	}

	function admin_experiments( ) {
		include SHRIMPTEST_DIR . '/admin/experiments.php';
	}

	function do_adminbar( ) {
		global $wpdb, $WPAdminBar;
		if ( is_user_logged_in( ) && $this->shrimp->has_been_touched( ) ) {
			if ( empty( $WPAdminBar ) )
				$this->print_shrimptest_widget( );
		}

	}
	
	function print_shrimptest_widget( ) {

		$icon = WP_PLUGIN_URL . '/shrimptest/shrimp.png';
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
	min-width: 180px;
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
<li class="brand"><a href="<?php echo admin_url('admin.php?page=shrimptest');?>">ShrimpTest</a></li><?php
	foreach ( $menus as $key => $menu ) {

		echo "<li>";
		if ( empty( $key ) )
			$str = "<span>{$menu[0][title]}</span>";
		else
			$str = "<a href=\"" . admin_url($key) . "\">{$menu[0][title]}</a>";
		echo "{$str}";
		
		if ( count($menu) > 1 ) {
			echo "<ul>";
			foreach ( $menu as $key => $menuitem ) {
				if ( $menuitem == $menu[0] )
					continue;
				if ( empty( $key ) )
					$str = "<span>{$menuitem[title]}<span>";
				else
					$str = "<a href=\"" . admin_url($key) . "\">{$menuitem[title]}</a>";
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
		
	function get_menus( ) {
		$menus = array();
		$touched_experiments = $this->shrimp->get_touched_experiments( );
		if ( !empty( $touched_experiments ) ) {
			$experiments = array( array( 'id'=>'20', 'title'=>'<sup>A</sup>/<sub>B</sub>', 'custom'=>false ) );

			foreach( $touched_experiments as $experiment_id => $data ) {
				$status = $this->shrimp->get_experiment_status( $experiment_id );
				// TODO: display experiment name
				$experiments["admin.php?page=shrimptest_experiments&id={$experiment_id}"] = array( 'id'=>$experiment_id, 'title'=>"Experiment {$experiment_id} <small>(status: {$status})</small>", 'custom'=>false );
				
				// display each of the variants
				foreach ( $this->shrimp->get_experiment_variants( $experiment_id ) as $variant ) {
					if ( $variant->variant_id == 0 )
						$title = "Control";
					else
						$title = "Variant {$variant->variant_id}";

					// add variant name
					if ( !empty( $variant->variant_name ) )
						$title .= ": {$variant->variant_name}";

					if ( $data['variant'] == $variant->variant_id )
						$title = "&#x2714; {$title}";
					else
						$title = "&#x3000; {$title}";
						
					$experiments["admin-ajax.php?action=shrimptest_override_variant&experiment_id={$experiment_id}&variant_id={$variant->variant_id}"] = array( 'id'=>$variant->variant_id, 'title'=>$title, 'custom'=>false );					
				}
			}
			$menus[] = $experiments;
		}

		$touched_metrics = $this->shrimp->get_touched_metrics( );
		if ( !empty( $touched_metrics ) ) {
			$metrics = array( array( 'id'=>'21', 'title'=>'&#x2605;', 'custom'=>false ) );

			foreach( $touched_metrics as $metric_id => $data ) {
				// TODO: display metric name
				if ( isset( $data->value ) )
					$value = " <small>(value: $data->value)</small>";
				else
					$value = "";
				$metrics[] = array( 'id' => $metric_id, 'title'=>"Metric {$metric_id}{$value}", 'custom'=>false );
			}
			$menus[] = $metrics;
		}

		return $menus;
	}
	
	function filter_adminbar( $menus ) {

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

} // class ShrimpTest_Interface

