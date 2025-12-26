<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Hosting;

use BBAB\ServiceCenter\Utils\Cache;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * UptimeRobot Service.
 *
 * Fetches uptime monitoring data from UptimeRobot API.
 * All data is cached for 36 hours (safety buffer for daily cron).
 *
 * Migrated from: WPCode Snippet #2232
 *
 * Required constant:
 *   BBAB_UPTIMEROBOT_API_KEY - UptimeRobot API key
 *
 * Required org meta:
 *   uptimerobot_monitor_id - The UptimeRobot monitor ID for this org
 */
class UptimeService {

    private const CACHE_SECONDS = 36 * HOUR_IN_SECONDS;
    private const API_TIMEOUT = 15;
    private const API_URL = 'https://api.uptimerobot.com/v2/getMonitors';

    // =========================================================================
    // Cache-Only Getter (for shortcodes - never trigger API calls)
    // =========================================================================

    /**
     * Get cached uptime data. Returns null if not cached.
     * Use this in shortcodes - never triggers API calls.
     *
     * @param int $org_id Organization post ID
     * @return array|null Cached data or null
     */
    public static function getData(int $org_id): ?array {
        $monitor_id = get_post_meta($org_id, 'uptimerobot_monitor_id', true);

        if (empty($monitor_id)) {
            return null;
        }

        return Cache::get('uptime_' . $org_id);
    }

    // =========================================================================
    // Fetch Method (for cron - triggers API calls and caches results)
    // =========================================================================

    /**
     * Fetch and cache uptime data from UptimeRobot.
     * USE ONLY IN CRON - triggers live API calls.
     *
     * @param int $org_id Organization post ID
     * @return array|null Uptime data or null on failure/not configured
     */
    public static function fetchData(int $org_id): ?array {
        $monitor_id = get_post_meta($org_id, 'uptimerobot_monitor_id', true);

        if (empty($monitor_id)) {
            Logger::debug('UptimeService', 'No monitor ID for org ' . $org_id);
            return null;
        }

        $data = self::fetchFromApi($monitor_id);

        if ($data) {
            Cache::set('uptime_' . $org_id, $data, self::CACHE_SECONDS);
        }

        return $data;
    }

    /**
     * Clear cached uptime data for an organization.
     *
     * @param int $org_id Organization post ID
     */
    public static function clearCache(int $org_id): void {
        Cache::delete('uptime_' . $org_id);
        Logger::debug('UptimeService', 'Cache cleared for org ' . $org_id);
    }

    // =========================================================================
    // Private Implementation Methods
    // =========================================================================

    /**
     * Fetch uptime data from UptimeRobot API.
     *
     * @param string $monitor_id The UptimeRobot monitor ID
     * @return array|null Array with uptime data or null on failure
     */
    private static function fetchFromApi(string $monitor_id): ?array {
        $api_key = defined('BBAB_UPTIMEROBOT_API_KEY') ? BBAB_UPTIMEROBOT_API_KEY : '';

        if (empty($api_key)) {
            Logger::error('UptimeService', 'API key not configured');
            return ['error' => 'API key not configured'];
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => self::API_TIMEOUT,
            'body' => [
                'api_key' => $api_key,
                'monitors' => $monitor_id,
                'custom_uptime_ratios' => '30', // Last 30 days
                'format' => 'json'
            ]
        ]);

        if (is_wp_error($response)) {
            Logger::error('UptimeService', 'Connection failed', [
                'monitor_id' => $monitor_id,
                'error' => $response->get_error_message()
            ]);
            return ['error' => 'Connection failed: ' . $response->get_error_message()];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            Logger::error('UptimeService', 'API returned error status', [
                'monitor_id' => $monitor_id,
                'status_code' => $status_code
            ]);
            return ['error' => 'API returned status ' . $status_code];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['stat']) || $body['stat'] !== 'ok') {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown API error';
            Logger::error('UptimeService', 'API error', [
                'monitor_id' => $monitor_id,
                'error' => $error_msg
            ]);
            return ['error' => 'UptimeRobot: ' . $error_msg];
        }

        if (empty($body['monitors'][0])) {
            Logger::error('UptimeService', 'Monitor not found', [
                'monitor_id' => $monitor_id
            ]);
            return ['error' => 'Monitor not found'];
        }

        $monitor = $body['monitors'][0];

        Logger::debug('UptimeService', 'Successfully fetched uptime data', [
            'monitor_id' => $monitor_id,
            'uptime' => $monitor['custom_uptime_ratio']
        ]);

        return [
            'uptime_percentage' => floatval($monitor['custom_uptime_ratio']),
            'status' => intval($monitor['status']), // 2 = up, 8 = seems down, 9 = down
            'friendly_name' => $monitor['friendly_name'],
            'fetched_at' => current_time('mysql')
        ];
    }
}
