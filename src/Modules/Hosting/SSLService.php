<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Hosting;

use BBAB\ServiceCenter\Utils\Cache;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * SSL Certificate Service.
 *
 * Checks SSL certificate expiry for client sites via socket connection.
 * All data is cached for 36 hours (safety buffer for daily cron).
 *
 * Migrated from: WPCode Snippet #2233
 *
 * Required org meta:
 *   site_url - The client's site URL to check
 */
class SSLService {

    private const CACHE_SECONDS = 36 * HOUR_IN_SECONDS;
    private const SOCKET_TIMEOUT = 10;

    // =========================================================================
    // Cache-Only Getter (for shortcodes - never trigger API calls)
    // =========================================================================

    /**
     * Get cached SSL data. Returns null if not cached.
     * Use this in shortcodes - never triggers socket connections.
     *
     * @param int $org_id Organization post ID
     * @return array|null Cached data or null
     */
    public static function getData(int $org_id): ?array {
        $site_url = get_post_meta($org_id, 'site_url', true);

        if (empty($site_url)) {
            return null;
        }

        return Cache::get('ssl_' . $org_id);
    }

    // =========================================================================
    // Fetch Method (for cron - triggers socket connections and caches results)
    // =========================================================================

    /**
     * Fetch and cache SSL certificate data.
     * USE ONLY IN CRON - triggers live socket connections.
     *
     * @param int $org_id Organization post ID
     * @return array|null SSL data or null on failure/not configured
     */
    public static function fetchData(int $org_id): ?array {
        $site_url = get_post_meta($org_id, 'site_url', true);

        if (empty($site_url)) {
            Logger::debug('SSLService', 'No site URL for org ' . $org_id);
            return null;
        }

        $data = self::checkCertificate($site_url);

        if ($data) {
            Cache::set('ssl_' . $org_id, $data, self::CACHE_SECONDS);
        }

        return $data;
    }

    /**
     * Clear cached SSL data for an organization.
     *
     * @param int $org_id Organization post ID
     */
    public static function clearCache(int $org_id): void {
        Cache::delete('ssl_' . $org_id);
        Logger::debug('SSLService', 'Cache cleared for org ' . $org_id);
    }

    // =========================================================================
    // Private Implementation Methods
    // =========================================================================

    /**
     * Check SSL certificate expiry for a given URL.
     *
     * @param string $url The site URL to check
     * @return array|null Array with SSL data or null on failure
     */
    private static function checkCertificate(string $url): ?array {
        if (empty($url)) {
            return null;
        }

        // Ensure we're checking HTTPS
        $url = preg_replace('/^http:/i', 'https:', $url);
        $parsed = wp_parse_url($url);

        if (empty($parsed['host'])) {
            Logger::error('SSLService', 'Invalid URL', ['url' => $url]);
            return ['error' => 'Invalid URL'];
        }

        $host = $parsed['host'];
        $port = 443;

        // Create stream context for SSL connection
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false, // We just want the cert info, not validation
                'verify_peer_name' => false,
            ]
        ]);

        // Suppress warnings, we'll handle errors ourselves
        $socket = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            self::SOCKET_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            Logger::error('SSLService', 'Connection failed', [
                'host' => $host,
                'errno' => $errno,
                'error' => $errstr
            ]);
            return ['error' => "Connection failed: {$errstr}"];
        }

        // Get certificate info
        $params = stream_context_get_params($socket);
        fclose($socket);

        if (empty($params['options']['ssl']['peer_certificate'])) {
            Logger::error('SSLService', 'Could not retrieve certificate', [
                'host' => $host
            ]);
            return ['error' => 'Could not retrieve certificate'];
        }

        $cert_info = openssl_x509_parse($params['options']['ssl']['peer_certificate']);

        if (!$cert_info || empty($cert_info['validTo_time_t'])) {
            Logger::error('SSLService', 'Could not parse certificate', [
                'host' => $host
            ]);
            return ['error' => 'Could not parse certificate'];
        }

        $expiry_timestamp = $cert_info['validTo_time_t'];
        $now = time();
        $days_remaining = floor(($expiry_timestamp - $now) / DAY_IN_SECONDS);

        Logger::debug('SSLService', 'Successfully checked SSL certificate', [
            'host' => $host,
            'days_remaining' => $days_remaining
        ]);

        return [
            'days_remaining' => (int) $days_remaining,
            'expiry_date' => gmdate('Y-m-d', $expiry_timestamp),
            'issuer' => isset($cert_info['issuer']['O']) ? $cert_info['issuer']['O'] : 'Unknown',
            'subject' => isset($cert_info['subject']['CN']) ? $cert_info['subject']['CN'] : $host,
            'fetched_at' => current_time('mysql')
        ];
    }
}
