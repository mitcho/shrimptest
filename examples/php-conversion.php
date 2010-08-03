/*
 * SHRIMPTEST EXAMPLE CODE:
 * Implementing a (lower-level, PHP) conversion-style metric
 * 
 * To use this lower-level code, you must have an experiment pre-registered.
 * Here I will simply assume that you have an experiment
 * with a particular number in your `wp_shrimptest_experiments` (or equivalent) table and
 * a certain number of variants in the `wp_shrimptest_experiments_variants` table.
 */
 
$my_experiment_id = 1;

/*
 * VARIANT CODE:
 */
 
$variant = shrimptest_get_variant( $my_experiment_id );
// $variant is going to be an integer.
switch ( $variant ) {
  case true: // if $variant > 0
    echo "This is variant #{$variant}.";
    break;
  default: // if $variant is 0, or if ShrimpTest is somehow down and returned an error.
		echo "This is the control.";
}

/*
 * GOAL CODE:
 */

// the user has converted!
shrimptest_conversion_success( $my_experiment_id );
