<?php

if ( !class_exists( 'WP' ) )
	die( "WordPress hasn't been loaded! :(" );

if ( !current_user_can('manage_options') )
	wp_die( __('You do not have sufficient permissions to access this page.') );

?>

<div class="wrap">
<?php screen_icon(); ?>
<h2><?php _e( 'ShrimpTest Settings', 'shrimptest' ); ?></h2>

<form method="post">

<?php

global $shrimp;

$shrimp->print_options();

?>

<input type="submit" value="<?php _e('Save settings','shrimptest');?>" id="submit" class="button-primary" name="submit"/>

<?php wp_nonce_field( 'shrimptest_settings' ); ?> 
</form>

</div>
</div>