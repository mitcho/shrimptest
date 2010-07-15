<?php
/*
 * ShrimpTest plugin for WP Super Cache
 *
 * To enable ShrimpTest support for WP Super Cache, place this plugin file in 
 *   wp-content/plugins/wp-super-cache/plugins .
 * Once installed, you must switch your WP Super Cache settings to "half on" mode. Then make sure
 * that it says "ShrimpTest support is enabled" at the bottom of the WP Super Cache Manager page.
 */


function wp_supercache_shrimptest_cache_key_filter( $key ) {
	global $wp_super_cache_late_init, $wp_super_cache_debug;
	if ( !$wp_super_cache_late_init )
		return;
		
	$use_shrimptest = ( defined( 'SHRIMPTEST_VERSION' ) && version_compare( SHRIMPTEST_VERSION, 0.1, '>=' ) );
	if ( !$use_shrimptest )
		return $key;

	global $shrimp;
	$variants_string = shrimptest_cache_support_get_cache_visitor_variants_string( );
	if ( $variants_string == 'metric'
			|| $variants_string == 'calculating experiments list' ) {
		if ( !defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
			if ( isset( $wp_super_cache_debug ) && $wp_super_cache_debug ) wp_cache_debug( "ShrimpTest: DONOTCACHEPAGE-ing and prohibiting cached file serving", 5 );
		}
		// return nonsense, so we force a regeneration of the page.
		$key = $key . '|' . md5( rand( ) );
	} else if ( $variants_string == 'no visitor id' ) {
		if ( !defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
			if ( isset( $wp_super_cache_debug ) && $wp_super_cache_debug ) wp_cache_debug( "ShrimpTest: DONOTCACHEPAGE-ing, but allowing cached file serving", 5 );
		}
	} else if ( $variants_string == 'no experiments on this page' ) {
		// return unchanged
	} else {
		$key .= "|" . $variants_string;
	}
	if ( isset( $wp_super_cache_debug ) && $wp_super_cache_debug ) wp_cache_debug( "key:{$key}  variants_string:{$variants_string}  visitor_id:{$shrimp->visitor_id}", 5 );

	return $key;
}
add_cacheaction( 'wp_cache_key', 'wp_supercache_shrimptest_cache_key_filter' );

function wp_supercache_shrimptest_admin() {
	global $shrimp, $wp_cache_config_file, $wp_super_cache_late_init;
	
	$use_shrimptest = ( defined( 'SHRIMPTEST_VERSION' ) && version_compare( SHRIMPTEST_VERSION, 0.1, '>=' ) );
	
	if ( $use_shrimptest && !$wp_super_cache_late_init ) {
		wp_cache_replace_line('^ *\$wp_super_cache_late_init', "\$wp_super_cache_late_init = 1;", $wp_cache_config_file);
	}

	if ( $use_shrimptest ) {
		echo '<strong>';
		printf( __( '<a href="%s">ShrimpTest</a> support is enabled.', 'wp-super-cache' ), 'http://shrimptest.com');
		echo '</strong> ';
		_e( '(Only half-on caching supported.)', 'wp-super-cache' );
	} else {
		printf( __( 'ShrimpTest support is disabled as <a href="%s">ShrimpTest</a> is not installed.', 'wp-super-cache' ), 'http://shrimptest.com');
	}

}
add_cacheaction( 'cache_admin_page', 'wp_supercache_shrimptest_admin' );

?>
