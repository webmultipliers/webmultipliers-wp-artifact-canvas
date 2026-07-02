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
delete_transient( 'wmac_file_protection_probe' );

// Global settings
delete_option( 'wmac_settings' );

// Git webhook secret
delete_option( 'wmac_git_webhook_secret' );

// Dedicated artifact capabilities + grant marker. The plugin is not loaded
// during uninstall, so pull in the Capabilities class explicitly and use its
// canonical list/cleanup instead of maintaining a duplicate copy here.
delete_option( 'wmac_caps_version' );
require_once __DIR__ . '/src/Capabilities.php';
\WebMultipliers\ArtifactCanvas\Capabilities::remove_from_all_roles();

flush_rewrite_rules();
