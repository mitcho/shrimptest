<?php

add_filter( 'shrimptest_exempt_visitor', 'shrimptest_plugin_exempt_prefetch' );
function shrimptest_plugin_exempt_prefetch( $exempt ) {
	if ( $exempt )
		return $exempt;
	if ( isset( $_SERVER['HTTP_X_MOZ'] ) && $_SERVER['HTTP_X_MOZ'] == 'prefetch' )
		return true;
	if ( isset( $_SERVER['HTTP_X_PURPOSE'] ) && $_SERVER['HTTP_X_PURPOSE'] == 'preview' )
		return true;
	return $exempt;
}