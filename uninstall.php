<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package BBAB\Core
 * @since   1.0.0
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Clean up plugin data on uninstall.
 *
 * This removes:
 * - Plugin options
 * - Transients
 *
 * Note: We do NOT delete CPT data (service_request, project, etc.)
 * as that data is managed by Pods and snippets, not this plugin.
 */

global $wpdb;

// Delete plugin options.
delete_option( 'bbab_core_version' );

// Delete all plugin transients.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_bbab_%'
     OR option_name LIKE '_transient_timeout_bbab_%'"
);

// Clear any cached data.
wp_cache_flush();
