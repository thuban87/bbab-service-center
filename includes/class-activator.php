<?php
/**
 * Fired during plugin activation.
 *
 * @package BBAB\Core
 * @since   1.0.0
 */

namespace BBAB\Core;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Activator
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since 1.0.0
 */
class Activator {

    /**
     * Plugin activation handler.
     *
     * Sets up any necessary options, capabilities, or database tables.
     * Currently just stores the version number for future upgrade routines.
     *
     * @since 1.0.0
     * @return void
     */
    public static function activate() {
        // Store plugin version for future upgrade routines.
        update_option( 'bbab_core_version', BBAB_CORE_VERSION );

        // Clear any existing transients to start fresh.
        self::clear_transients();

        // Flush rewrite rules (in case we add custom endpoints later).
        flush_rewrite_rules();
    }

    /**
     * Clear all plugin transients.
     *
     * @since 1.0.0
     * @return void
     */
    private static function clear_transients() {
        global $wpdb;

        // Delete all transients with our prefix.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_bbab_%'
             OR option_name LIKE '_transient_timeout_bbab_%'"
        );
    }
}
