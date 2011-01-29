<?php
/**
 * ShrimpTest Notification plugin
 *
 *  This plugin emails you when an experiment's pre-set duration has been reached.
 *
 * @author mitcho (Michael Yoshitaka Erlewine) <mitcho@mitcho.com>, Automattic
 * @package ShrimpTest
 * @subpackage ShrimpTest_Plugin_Notification
 */

/**
 * The email address from which notifications are sent.
 *
 * By default, will be 'shrimptest-noreply@DOMAIN.COM'.
 */
if ( !defined( 'SHRIMPTEST_NOTIFICATION_FROM' ) )
	define( 'SHRIMPTEST_NOTIFICATION_FROM', 'shrimptest-noreply@' . $_SERVER['HTTP_HOST'] );

add_action( 'shrimptest_add_duration_extra', 'shrimptest_plugin_notification_form', 10, 1 );
/**
 * Print the notification form element in the duration extra portion of the
 * 'Add new experiment' screen
 *
 * @param object
 */
function shrimptest_plugin_notification_form( $experiment ) {
	$value = isset( $experiment->data['notification_emails'] ) ? 
	  esc_attr( $experiment->data['notification_emails'] ) : '';
	echo '<tr><th><label for="notification_emails">' . __('Notification emails','shrimptest') . ':</th><td><input type="text" name="notification_emails" id="notification_emails" size="60" value="' . esc_attr($value) . '"></input><br/>
<small>' . __("Enter email addresses here to be notified when the experiment duration has been reached. Separate multiple email addresses with commas.", 'shrimptest' ) . '</small></td></tr>';
}

add_action( 'shrimptest_experiment_duration_reached', 'shrimptest_plugin_notification', 10, 2 );
/**
 * Send out an email when experiment duration is reached
 *
 * @param array
 * @param object
 * @return array
 */
function shrimptest_plugin_notification( $stats, $experiment ) {
	global $shrimp;
	// if notifications were requested, and we haven't notified yet.
	if ( isset( $experiment->data['notification_emails'] )
	   && !( isset( $experiment->data['notified'] ) && $experiment->data['notified'] ) ) {
		$to = $experiment->data['notification_emails'];
		$subject = '[' . get_bloginfo('name') . '] ' . __('Experiment results available', 'shrimptest');
		$name = ( isset($experiment->name) && strlen($experiment->name) ) ?
			' (' . $experiment->name . ')' : '';
		$message = sprintf(__('The experiment duration for experiment %d%s has been reached. Confident experiment results may be available.', 'shrimptest'), $experiment->experiment_id, $name );
		$message .= "\n\n" . admin_url("admin.php?page={$shrimp->interface->slug}");
//		$message .= "\n\n" . var_export( $stats, true ); // TESTING!
		mail( $to, $subject, $message, 'From: ' . SHRIMPTEST_NOTIFICATION_FROM );
	}
	return $stats;
}
