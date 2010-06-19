<?php
/*
Plugin Name: ShrimpTest
Description: A/B testing the WordPress way
Version: 0.1
Author: mitcho (Michael 芳貴 Erlewine), Automattic
Author URI: http://mitcho.com
License: GPLv2
*/

define( 'SHRIMPTEST_DIR', dirname( __FILE__ ) );

define( 'SHRIMPTEST_VERSION', '0.1' );

/*
 * Load plugins
 */
// TODO: load everything in plugins dynamically
require_once SHRIMPTEST_DIR . '/plugins/metric-conversion.php';

require_once SHRIMPTEST_DIR . '/classes/core.php'; // holds the ShrimpTest class
if ( !defined( 'SHRIMPTEST_CLASS' ) )
	define( 'SHRIMPTEST_CLASS', 'ShrimpTest' );
$shrimptest_class = SHRIMPTEST_CLASS;
$shrimp = new $shrimptest_class( );
$shrimp->init();

require_once SHRIMPTEST_DIR . '/blocklist.php';
$shrimp->load_blocklist( $blocklist );
$shrimp->load_blockterms( $blockterms );
unset( $blocklist, $blockterms );

require_once SHRIMPTEST_DIR . '/classes/interface.php'; // holds the ShrimpTest_Interface class
if ( !defined( 'SHRIMPTEST_INTERFACE_CLASS' ) )
	define( 'SHRIMPTEST_INTERFACE_CLASS', 'ShrimpTest_Interface' );
$shrimptest_interface_class = SHRIMPTEST_INTERFACE_CLASS;
$shrimp_UI = new $shrimptest_interface_class( );
$shrimp_UI->init($shrimp);

require_once SHRIMPTEST_DIR . '/template-tags.php';
