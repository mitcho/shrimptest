<?php
/*
Plugin Name: ShrimpTest
Description: A/B testing the WordPress way
Version: 1.0b3
Author: mitcho (Michael 芳貴 Erlewine), Automattic
Author URI: http://mitcho.com
License: GPLv2
*/

/**
 * ShrimpTest bootstrapper
 *
 * This file sets up some constants and initializes the {@link $shrimp} global
 * instance of {@link ShrimpTest}.
 *
 * @author mitcho (Michael Yoshitaka Erlewine) <mitcho@mitcho.com>, Automattic
 * @package ShrimpTest
 * @version 1.0b3
 * @license GPLv2
 */

/**
 * the filesystem path to ShrimpTest
 */
define( 'SHRIMPTEST_DIR', WP_PLUGIN_DIR . '/' . basename(dirname( $plugin )) );
/**
 * the URL path to ShrimpTest
 */
define( 'SHRIMPTEST_URL', plugin_dir_url( $plugin ) );

/**
 * ShrimpTest version number
 */
define( 'SHRIMPTEST_VERSION', '1.0b2' );

/**
 * Load the {@link ShrimpTest} Core class file
 */
require_once SHRIMPTEST_DIR . '/classes/core.php'; // holds the ShrimpTest class

/**
 * If not defined already, set to be "ShrimpTest". This mechanism enables
 * low-level ShrimpTest modifications/repackaging to load before this file,
 * set this constant to be a different value, and get that loaded as {@link $shrimp}.
 */
if ( !defined( 'SHRIMPTEST_CLASS' ) )
	define( 'SHRIMPTEST_CLASS', 'ShrimpTest' );
$shrimptest_class = SHRIMPTEST_CLASS;

/**
 * ShrimpTest global instance variable
 * @global object $shrimp
 */
global $shrimp;
$shrimp = new $shrimptest_class( );
$shrimp->init();

/**
 * Load the low-level "template tags", for use by plugins and themes, together
 * with a "manual" variant type or metric type.
 */
require_once SHRIMPTEST_DIR . '/template-tags.php';
