<?php
/*
 * ShrimpTest template tags
 */

/*
 * LOW LEVEL SELECTION + VARIATION CODE:
 * 
 * Basic variation pattern:
 *
 *   $variant = shrimptest_get_variant( <<EXPERIMENT ID>> );
 *   switch ( $variant ) {
 *     case ...:
 *       // variant B
 *       break;
 *     case ...:
 *       // variant C
 *       break;
 *     default:
 *       // control (= variant A, aka 0)
 *   }
 */

function shrimptest_get_variant( $experiment_id ) {
	global $shrimp;
	return $shrimp->get_visitor_variant( $experiment_id );
}

/*
 * LOW LEVEL METRIC SETTING CODE:
 */

// NOTE: right now $metric_id = $experiment_id. TODO: fix.
function shrimptest_update_metric( $metric_id, $value ) {
	global $shrimp;
	$shrimp->update_visitor_metric( $metric_id, $value );
}


/*
 * HIGH LEVEL FUNCTIONS for CONVERSION-STYLE METRICS
 * See examples/php-conversion.php for sample code
 */

function shrimptest_conversion_success( $metric_id ) {
	global $shrimp;
	$shrimp->update_visitor_metric( $metric_id, 1, true );
}

