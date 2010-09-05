<?php
/**
 * ShrimpTest Prefetch-block plugin
 *
 * This plugin blocks "Prefetch" requests from counting towards 
 *
 * @link https://developer.mozilla.org/en/link_prefetching_faq
 * 
 * @author mitcho (Michael Yoshitaka Erlewine) <mitcho@mitcho.com>, Automattic
 * @package ShrimpTest
 * @subpackage ShrimpTest_Plugin_Prefetch_Block
 */

add_filter( 'shrimptest_exempt_visitor', 'shrimptest_plugin_exempt_prefetch' );
/**
 * If the request is a "prefetch" request, return true for exempt
 *
 * This is bound against the shrimptest_exempt_visitor filter.
 *
 * @param bool
 * @return bool
 */
function shrimptest_plugin_exempt_prefetch( $exempt ) {
	if ( $exempt )
		return $exempt;
	if ( isset( $_SERVER['HTTP_X_MOZ'] ) && $_SERVER['HTTP_X_MOZ'] == 'prefetch' )
		return true;
	if ( isset( $_SERVER['HTTP_X_PURPOSE'] ) && $_SERVER['HTTP_X_PURPOSE'] == 'preview' )
		return true;
	return $exempt;
}