<?php
/*
 * Cache support plugin
 * This plugin implements various functionality to support the caching of ShrimpTest-generated
 * content.
 */

add_filter('shrimptest_dbdelta_sql', 'shrimptest_cache_support_add_sql');
function shrimptest_cache_support_add_sql( $sql ) {
	global $shrimp;
	array_push($sql, "CREATE TABLE `{$shrimp->db_prefix}request_touches` (
											`request` varchar(1000) NOT NULL DEFAULT '',
											`experiment_id` int(11) unsigned DEFAULT NULL,
											`metric_id` int(11) unsigned DEFAULT NULL,
											KEY `request` (`request`),
											KEY `experiment_id` (`experiment_id`)
										) ENGINE=MyISAM" );
	return $sql;
}

add_action( 'wp_footer', 'shrimptest_cache_support_record_touched' );
function shrimptest_cache_support_record_touched( $force = false ) {
	global $wpdb, $wp_super_cache_debug, $shrimp;
	
	// if it's an admin, ajax, or feed call that doesn't need to be 
	if ( $shrimp->blocked_visit( ) || apply_filters( 'shrimptest_record_touched_is_404', is_404( ) ) )
		return;
	
	// if this isn't a real visitor
	if ( is_null( $shrimp->visitor_id ) )
		return;
	
	$request = shrimptest_cache_support_request_uri( );
	
	if ( isset( $wp_super_cache_debug ) && $wp_super_cache_debug )
		wp_cache_debug( "ShrimpTest: record_touched: $request", 5 );
	
	$cache = $wpdb->get_row( $wpdb->prepare("select group_concat(distinct experiment_id order by experiment_id asc) as experiments, group_concat(distinct metric_id order by metric_id asc) as metrics, count(request) as entries from {$shrimp->db_prefix}request_touches where request = %s", $request ) );

	// if we want to force a recording, don't worry about this.
	// alternatively, if there are no rows, also don't worry about it.
	if ( !$force && $cache->entries ) {
		$experiments = $shrimp->get_touched_experiments( );
		if ( $experiments ) {
			$experiments = array_keys( $experiments );
			sort( $experiments );
		} else {
			$experiments = array();
		}
		
		$metrics = $shrimp->get_touched_metrics( );
		if ( $metrics ) {
			$metrics = array_keys( $metrics );
			sort( $metrics );
		} else {
			$metrics = array();
		}

		// if the ids are the same as in the cache, return
		if ( join( ',', $experiments ) == $cache->experiments
			&& join( ',', $metrics ) == $cache->metrics ) {
			if ( isset( $wp_super_cache_debug ) && $wp_super_cache_debug ) wp_cache_debug( "ShrimpTest: not record_touch-ing because the cache is already good.", 5 );
			return;
		}
	}

	if ( isset( $wp_super_cache_debug ) && $wp_super_cache_debug ) wp_cache_debug( "ShrimpTest: record_touched: recording", 5 );
	
	// if we're still here, let's reset the request_touches cache and insert new entries.
	if ( $cache->entries ) {
		$wpdb->query( $wpdb->prepare( "delete from {$shrimp->db_prefix}request_touches where request = %s", $request ) );
		if ( isset( $wp_super_cache_debug ) && $wp_super_cache_debug ) wp_cache_debug( "ShrimpTest: record_touched SQL: $wpdb->last_query", 5 );
	}
	
	if ( !$shrimp->has_been_touched( ) ) {
		$table = "{$shrimp->db_prefix}request_touches";
		$data = array( 'request' => $request );
		$wpdb->insert( $table, $data, '%s' );
		if ( isset( $wp_super_cache_debug ) && $wp_super_cache_debug ) wp_cache_debug( "ShrimpTest: record_touched SQL: $wpdb->last_query; $wpdb->rows_affected", 5 );
		return;
	} else {
		$values = array();
		$escaped_request = $wpdb->escape( $request );
		$experiments = $shrimp->get_touched_experiments( );
		if ( !empty( $experiments ) ) {
			foreach( array_keys( $experiments ) as $experiment_id )
				$values[] = "( '$escaped_request', '{$experiment_id}', null )";
		}
		$metrics = $shrimp->get_touched_metrics( );
		if ( !empty( $metrics ) ) {
			foreach( array_keys( $metrics ) as $metric_id )
				$values[] = "( '$escaped_request', null, '{$metric_id}' )";
		}
		$wpdb->query( "insert into {$shrimp->db_prefix}request_touches ( request, experiment_id, metric_id ) values " . join( ',', $values ) );
		if ( isset( $wp_super_cache_debug ) && $wp_super_cache_debug ) wp_cache_debug( "ShrimpTest: record_touched SQL: $wpdb->last_query; $wpdb->rows_affected", 5 );
	}
}

function shrimptest_cache_support_request_uri( ) {
	$uri = $_SERVER['SERVER_NAME'] . $_SERVER['SERVER_PORT'] . $_SERVER['REQUEST_URI'];
	return apply_filters( 'shrimptest_cache_support_request_uri', $uri );
}

function shrimptest_cache_support_get_cache_visitor_variants_string( ) {
	global $wpdb, $wp_super_cache_debug, $shrimp;

	if ( is_null( $shrimp->visitor_id ) )
		$shrimp->check_cookie( );
	$visitor_id = $shrimp->visitor_id;
	if ( is_null( $visitor_id ) )
		return 'no visitor id';

	$variants = $wpdb->get_results( $wpdb->prepare(
		"select ifnull(rt.experiment_id,if(rt.metric_id is not null,'metric',null)) as experiment_id, variant_id from {$shrimp->db_prefix}request_touches as rt "
		."left join {$shrimp->db_prefix}experiments as e using (experiment_id) "
		."left join {$shrimp->db_prefix}visitors_variants as vv on (rt.experiment_id = vv.experiment_id and vv.visitor_id = %s) "
		."where request = %s order by experiment_id asc", $visitor_id, shrimptest_cache_support_request_uri( ) ) );

	if ( isset( $wp_super_cache_debug ) && $wp_super_cache_debug ) wp_cache_debug( "ShrimpTest: variants data: $wpdb->last_query\n".var_export($variants, true), 5 );

	if ( !count( $variants ) )
		return 'calculating experiments list';

	$variant_strings = array();
	foreach ($variants as $variant) {

		// if an experiment_id is null, that means there were no experiments on this page.
		if ( $variant->experiment_id == null )
			return 'no experiments on this page';
	
		// if there's a metric recorded on this page, we want to not cache it.
		if ( $variant->experiment_id == 'metric' )
			return 'metric';
	
		if ( $variant->variant_id !== null ) {
			$variant_id = $variant->variant_id;
		} else {
			$variant_id = $shrimp->get_visitor_variant( $variant->experiment_id );
			if ( $variant_id !== null )
				return 'calculating experiments list';
		}
		// only add the string if it's non-null
		if ( $variant_id !== null )
			$variant_strings[] = $variant->experiment_id . ':' . $variant_id;
	}

	if ( count( $variant_strings ) )
		return join(';', $variant_strings);
	else
		return 'calculating experiments list';
}