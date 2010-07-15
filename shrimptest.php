<?php
/*
Plugin Name: ShrimpTest
Description: A/B testing the WordPress way
Version: 0.2
Author: mitcho (Michael 芳貴 Erlewine), Automattic
Author URI: http://mitcho.com
License: GPLv2
*/

define( 'SHRIMPTEST_DIR', dirname( __FILE__ ) );

define( 'SHRIMPTEST_VERSION', '0.2' );

require_once SHRIMPTEST_DIR . '/classes/core.php'; // holds the ShrimpTest class
if ( !defined( 'SHRIMPTEST_CLASS' ) )
	define( 'SHRIMPTEST_CLASS', 'ShrimpTest' );
$shrimptest_class = SHRIMPTEST_CLASS;
$shrimp = new $shrimptest_class( );
$shrimp->init();

require_once SHRIMPTEST_DIR . '/template-tags.php';
