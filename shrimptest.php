<?php
/*
Plugin Name: ShrimpTest
Description: A/B testing the WordPress way
Version: 1.0b1
Author: mitcho (Michael 芳貴 Erlewine), Automattic
Author URI: http://mitcho.com
License: GPLv2
*/

define( 'SHRIMPTEST_DIR', dirname( $plugin ) );
define( 'SHRIMPTEST_URL', plugin_dir_url( $plugin ) );

define( 'SHRIMPTEST_VERSION', '1.0b1' );

require_once SHRIMPTEST_DIR . '/classes/core.php'; // holds the ShrimpTest class
if ( !defined( 'SHRIMPTEST_CLASS' ) )
	define( 'SHRIMPTEST_CLASS', 'ShrimpTest' );
$shrimptest_class = SHRIMPTEST_CLASS;

global $shrimp;
$shrimp = new $shrimptest_class( );
$shrimp->init();

require_once SHRIMPTEST_DIR . '/template-tags.php';
