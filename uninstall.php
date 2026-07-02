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

// Dedicated artifact capabilities + grant marker
delete_option( 'wmac_caps_version' );
if ( class_exists( 'WP_Roles' ) ) {
	$wmac_caps = array(
		'edit_wm_artifact',
		'read_wm_artifact',
		'delete_wm_artifact',
		'edit_wm_artifacts',
		'edit_others_wm_artifacts',
		'delete_wm_artifacts',
		'delete_others_wm_artifacts',
		'delete_published_wm_artifacts',
		'delete_private_wm_artifacts',
		'publish_wm_artifacts',
		'read_private_wm_artifacts',
		'edit_published_wm_artifacts',
		'edit_private_wm_artifacts',
	);
	foreach ( array_keys( wp_roles()->roles ) as $wmac_role_name ) {
		$wmac_role = get_role( $wmac_role_name );
		if ( ! $wmac_role ) {
			continue;
		}
		foreach ( $wmac_caps as $wmac_cap ) {
			$wmac_role->remove_cap( $wmac_cap );
		}
	}
	unset( $wmac_caps, $wmac_role, $wmac_role_name, $wmac_cap );
}

flush_rewrite_rules();
