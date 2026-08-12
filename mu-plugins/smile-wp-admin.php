<?php
/**
 * Plugin Name: Smile Creative — admin mail policy
 * Description: Pins the WordPress administration email to one central mailbox and silences the routine "everything worked" notifications, while keeping the ones that mean something has gone wrong.
 * Version:     1.0.0
 * Author:      Smile Creative
 *
 * Drop this file in wp-content/mu-plugins/ on any site. Must-use plugins load
 * before everything else and cannot be deactivated from the dashboard, so the
 * policy holds even if someone changes a setting or a plugin tries to.
 *
 * To use a different address on a particular site, define SMILE_WP_ADMIN_EMAIL
 * in wp-config.php before this loads.
 *
 * @package smile-creative
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SMILE_WP_ADMIN_EMAIL' ) ) {
	define( 'SMILE_WP_ADMIN_EMAIL', 'wpadmin@smilecreative.agency' );
}

/* -------------------------------------------------------------------------
 * 1. The administration email.
 *
 * Filtering the option rather than only writing it to the database means the
 * address cannot drift: a plugin, an import, or a well-meaning client changing
 * Settings > General will not move it. WordPress normally makes an address
 * change wait for a confirmation click on the new mailbox; pinning it here
 * skips that dance entirely, which matters when you are doing thirty sites.
 * ---------------------------------------------------------------------- */

add_filter(
	'pre_option_admin_email',
	function () {
		return SMILE_WP_ADMIN_EMAIL;
	}
);

// Discard any half-finished change left pending from the old flow.
add_filter( 'pre_option_new_admin_email', '__return_empty_string' );

/**
 * Write it to the database once as well, so anything reading the option table
 * directly (a backup, a migration tool, a report) sees the right address.
 */
add_action(
	'admin_init',
	function () {
		if ( get_option( 'admin_email_raw_check' ) === SMILE_WP_ADMIN_EMAIL ) {
			return;
		}
		remove_all_filters( 'pre_option_admin_email' );
		$stored = get_option( 'admin_email' );
		add_filter(
			'pre_option_admin_email',
			function () {
				return SMILE_WP_ADMIN_EMAIL;
			}
		);
		if ( $stored !== SMILE_WP_ADMIN_EMAIL ) {
			update_option( 'admin_email', SMILE_WP_ADMIN_EMAIL );
		}
		update_option( 'admin_email_raw_check', SMILE_WP_ADMIN_EMAIL, false );
	}
);

/* -------------------------------------------------------------------------
 * 2. The noise.
 *
 * Redirecting a hundred pointless emails to a different inbox is still a
 * hundred pointless emails. These are the ones nobody has ever read: the
 * "your site updated successfully" notices. Failures are deliberately left
 * switched on — those are the whole point of having an address at all.
 * ---------------------------------------------------------------------- */

// Core auto-update: keep failures and "manual update needed", drop successes.
add_filter(
	'auto_core_update_send_email',
	function ( $send, $type ) {
		return ( 'success' === $type ) ? false : $send;
	},
	10,
	2
);

// Plugin and theme auto-update result emails: always noise, weekly.
add_filter( 'auto_plugin_update_send_email', '__return_false' );
add_filter( 'auto_theme_update_send_email', '__return_false' );

// The developer debug email that goes out after every update run.
add_filter( 'automatic_updates_send_debug_email', '__return_false' );

/* Deliberately NOT disabled, because these are the ones worth having:
 *  - recovery mode / fatal error notices ("your site is broken")
 *  - core update FAILURE notices
 *  - password change and email change confirmations
 *  - new user registration notices
 */
