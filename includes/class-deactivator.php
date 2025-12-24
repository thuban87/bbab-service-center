<?php
/**
 * Fired during plugin deactivation.
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
 * Class Deactivator
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since 1.0.0
 */
class Deactivator {

    /**
     * Plugin deactivation handler.
     *
     * Cleans up transients and flushes rewrite rules.
     * Does NOT delete options or data - that's handled by uninstall.php.
     *
     * @since 1.0.0
     * @return void
     */
    public static function deactivate() {
        // Clear all plugin transients.
        self::clear_transients();

        // Flush rewrite rules.
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
