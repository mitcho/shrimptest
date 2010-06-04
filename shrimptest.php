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
require_once SHRIMPTEST_DIR . '/class-shrimptest.php';
require_once SHRIMPTEST_DIR . '/template-tags.php';

define( 'SHRIMPTEST_VERSION', '0.1' );

if ( !defined( 'SHRIMPTEST_CLASS' ) )
	define( 'SHRIMPTEST_CLASS', 'ShrimpTest' );

$shrimptest_class = SHRIMPTEST_CLASS;
$shrimp = new $shrimptest_class( );
$shrimp->init();

require_once SHRIMPTEST_DIR . '/blocklist.php';
$shrimp->load_blocklist( $blocklist );
$shrimp->load_blockterms( $blockterms );
unset( $blocklist, $blockterms );
