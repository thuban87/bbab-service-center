<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Core;

/**
 * Fired during plugin deactivation.
 */
class Deactivator {

    /**
     * Deactivate the plugin.
     *
     * - Clear scheduled cron jobs
     * - Clear simulation cookie
     * - Optionally clear transient cache
     */
    public static function deactivate(): void {
        // Clear cron jobs
        self::clearCronJobs();

        // Clear simulation cookie if active
        SimulationBootstrap::clearSimulation();

        // Log deactivation
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[BBAB-SC] Plugin deactivated');
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Clear all scheduled cron jobs.
     */
    private static function clearCronJobs(): void {
        $cron_hooks = [
            'bbab_sc_analytics_cron',
            'bbab_sc_hosting_cron',
            'bbab_sc_cleanup_cron',
            'bbab_sc_forgotten_timer_check',
            'bbab_sc_billing_cron',
            'bbab_sc_debug_auto_disable',
        ];

        foreach ($cron_hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }
    }
}
