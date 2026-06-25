<?php
/**
 * Runs when the plugin is deleted via the WordPress admin.
 *
 * Does NOT delete artifact posts — that would be surprising data loss.
 * Only plugin-owned options are removed. See PLAN.md §6 for policy rationale.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin-owned options here as they are added in future phases.
// delete_option( 'wmac_some_option' );

flush_rewrite_rules();
