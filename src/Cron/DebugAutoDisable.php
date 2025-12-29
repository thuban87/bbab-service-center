<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Cron;

use BBAB\ServiceCenter\Utils\Settings;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Debug Mode Auto-Disable Handler.
 *
 * Automatically disables debug mode after a configured duration
 * to prevent accidentally leaving it on in production.
 */
class DebugAutoDisable {

    /**
     * Cron hook name.
     */
    public const HOOK = 'bbab_sc_debug_auto_disable';

    /**
     * Register the cron job.
     */
    public static function register(): void {
        add_action(self::HOOK, [self::class, 'checkAndDisable']);
    }

    /**
     * Schedule the cron job (called on plugin activation or when debug is enabled).
     */
    public static function schedule(): void {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'hourly', self::HOOK);
        }
    }

    /**
     * Unschedule the cron job (called on plugin deactivation).
     */
    public static function unschedule(): void {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    /**
     * Check if debug mode should be disabled and disable it.
     */
    public static function checkAndDisable(): void {
        // Only check if debug mode is currently enabled
        if (!Settings::isDebugMode()) {
            return;
        }

        $enabled_at = (int) Settings::get('debug_enabled_at', 0);
        $duration = Settings::get('debug_auto_disable', 'never');

        // If no timestamp or set to never, do nothing
        if ($enabled_at === 0 || $duration === 'never') {
            return;
        }

        // Calculate expiry based on duration
        $duration_seconds = self::getDurationSeconds($duration);
        if ($duration_seconds === 0) {
            return;
        }

        $expiry_time = $enabled_at + $duration_seconds;

        // Check if expired
        if (time() >= $expiry_time) {
            self::disableDebugMode();
        }
    }

    /**
     * Get duration in seconds from setting value.
     *
     * @param string $duration Duration setting value.
     * @return int Seconds.
     */
    private static function getDurationSeconds(string $duration): int {
        $durations = [
            '1hour' => HOUR_IN_SECONDS,
            '4hours' => 4 * HOUR_IN_SECONDS,
            '24hours' => DAY_IN_SECONDS,
            'never' => 0,
        ];

        return $durations[$duration] ?? 0;
    }

    /**
     * Disable debug mode and log the action.
     */
    private static function disableDebugMode(): void {
        Settings::set('debug_mode', false);
        Settings::set('debug_enabled_at', 0);

        Logger::info('DebugAutoDisable', 'Debug mode automatically disabled after timeout');

        // Send notification email to admin
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');

        wp_mail(
            $admin_email,
            "[{$site_name}] Debug Mode Auto-Disabled",
            "Debug mode was automatically disabled on your site after the configured timeout period.\n\n" .
            "If you need to continue debugging, you can re-enable it in:\n" .
            "Brad's Workbench → Settings → General\n\n" .
            "This is an automated safety feature to prevent debug mode from being left on accidentally."
        );
    }

    /**
     * Get the expiry time for display.
     *
     * @return string|null Human-readable expiry time or null if not set.
     */
    public static function getExpiryDisplay(): ?string {
        if (!Settings::isDebugMode()) {
            return null;
        }

        $enabled_at = (int) Settings::get('debug_enabled_at', 0);
        $duration = Settings::get('debug_auto_disable', 'never');

        if ($enabled_at === 0 || $duration === 'never') {
            return null;
        }

        $duration_seconds = self::getDurationSeconds($duration);
        if ($duration_seconds === 0) {
            return null;
        }

        $expiry_time = $enabled_at + $duration_seconds;

        // Convert to WordPress timezone for display
        $expiry_datetime = new \DateTime('@' . $expiry_time);
        $expiry_datetime->setTimezone(new \DateTimeZone(wp_timezone_string()));

        return $expiry_datetime->format('M j, Y g:i A T');
    }

    /**
     * Get remaining time until auto-disable.
     *
     * @return string|null Human-readable remaining time or null.
     */
    public static function getRemainingTime(): ?string {
        if (!Settings::isDebugMode()) {
            return null;
        }

        $enabled_at = (int) Settings::get('debug_enabled_at', 0);
        $duration = Settings::get('debug_auto_disable', 'never');

        if ($enabled_at === 0 || $duration === 'never') {
            return null;
        }

        $duration_seconds = self::getDurationSeconds($duration);
        if ($duration_seconds === 0) {
            return null;
        }

        $expiry_time = $enabled_at + $duration_seconds;
        $remaining = $expiry_time - time();

        if ($remaining <= 0) {
            return 'Expiring soon...';
        }

        if ($remaining < HOUR_IN_SECONDS) {
            $minutes = ceil($remaining / MINUTE_IN_SECONDS);
            return $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
        }

        $hours = round($remaining / HOUR_IN_SECONDS, 1);
        return $hours . ' hour' . ($hours != 1 ? 's' : '');
    }
}
