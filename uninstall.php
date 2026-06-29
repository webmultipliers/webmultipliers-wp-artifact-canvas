<?php
/**
 * Runs when the plugin is deleted via the WordPress admin.
 *
 * Policy: artifact posts and their meta are intentionally NOT deleted — that
 * would be surprising, destructive data loss. Only plugin-owned options and
 * transients are removed.
 *
 * Physical files in uploads/wmac-artifacts/ are also left in place so that
 * a re-install can recover them. Admins who want a clean slate can delete
 * that directory manually.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Transients
delete_transient( 'wmac_alias_map' );

// Global settings
delete_option( 'wmac_settings' );

// Git webhook secret
delete_option( 'wmac_git_webhook_secret' );

flush_rewrite_rules();
