<?php
/**
 * SHRIMPTEST EXAMPLE CODE:
 * Implementing a (lower-level, PHP) conversion-style metric
 * 
 * To use this lower-level code, you must have an experiment pre-registered.
 * Here I will simply assume that you have an experiment
 * registered with a certain number of variants.
 *
 * @package ShrimpTest
 * @subpackage Example
 */
 
/**
 * This is the experiment id that we will use.
 *
 * This id should match an experiment that has been created with the "manual"
 * metric and variant types.
 *
 * @var int
 */
$my_experiment_id = 1;

/**
 * VARIANT CODE:
 *
 * Get the variant id for the current user, for this experiment #{@link $my_experiment_id}
 * using {@link shrimptest_get_variant}, which returns an integer.
 *
 * Switch on this value. Variant 0 is the control.
 *
 * This switch code can be put anywhere in your custom plugin or theme.
 */
$variant = shrimptest_get_variant( $my_experiment_id );
switch ( $variant ) {
  case true: // if $variant > 0
    echo "This is variant #{$variant}.";
    break;
  default: // if $variant is 0, or if ShrimpTest is somehow down and returned an error.
		echo "This is the control.";
}

/**
 * GOAL CODE:
 *
 * The user has converted! Use {@link shrimptest_conversion_success()}
 */
shrimptest_conversion_success( $my_experiment_id );
