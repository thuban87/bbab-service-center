<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Analytics;

use BBAB\ServiceCenter\Utils\Cache;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Google PageSpeed Insights Service.
 *
 * Fetches Core Web Vitals (LCP, CLS) and performance scores.
 * Data is cached for 24 hours.
 *
 * Migrated from: WPCode Snippets #2027, #2078
 *
 * Required org meta:
 *   site_url - The client's website URL
 *
 * Optional constant:
 *   BBAB_GOOGLE_API_KEY - API key for higher rate limits
 */
class PageSpeedService {

    private const CACHE_SECONDS = DAY_IN_SECONDS;
    private const API_TIMEOUT = 60; // PageSpeed can be slow

    // =========================================================================
    // Cache-Only Getters (for shortcodes - never trigger API calls)
    // =========================================================================

    /**
     * Get cached PageSpeed data (desktop only). Returns null if not cached.
     * Use this in shortcodes - never triggers API calls.
     *
     * @param int $org_id Organization post ID
     * @return array|null Cached data or null
     */
    public static function getData(int $org_id): ?array {
        $site_url = get_post_meta($org_id, 'site_url', true);

        if (empty($site_url)) {
            return null;
        }

        $data = Cache::get('cwv_' . md5($site_url));

        if ($data !== null) {
            $data['from_cache'] = true;
        }

        return $data;
    }

    /**
     * Get cached PageSpeed data (desktop + mobile). Returns null if not cached.
     * Use this in shortcodes - never triggers API calls.
     *
     * @param int $org_id Organization post ID
     * @return array|null Cached data or null
     */
    public static function getDataFull(int $org_id): ?array {
        $site_url = get_post_meta($org_id, 'site_url', true);

        if (empty($site_url)) {
            return null;
        }

        $data = Cache::get('cwv_full_' . md5($site_url));

        if ($data !== null) {
            $data['from_cache'] = true;
        }

        return $data;
    }

    // =========================================================================
    // Fetch Methods (for cron - trigger API calls and cache results)
    // =========================================================================

    /**
     * Fetch and cache PageSpeed data for desktop only.
     * USE ONLY IN CRON - triggers live API calls.
     *
     * @param int $org_id Organization post ID
     * @return array|null PageSpeed data or null on failure
     */
    public static function fetchData(int $org_id): ?array {
        $site_url = get_post_meta($org_id, 'site_url', true);

        if (empty($site_url)) {
            Logger::debug('PageSpeedService', 'No site_url for org ' . $org_id);
            return null;
        }

        $data = self::fetchForStrategies($site_url, ['desktop']);

        if ($data) {
            Cache::set('cwv_' . md5($site_url), $data, self::CACHE_SECONDS);
        }

        return $data;
    }

    /**
     * Fetch and cache PageSpeed data for both desktop and mobile.
     * USE ONLY IN CRON - triggers live API calls.
     *
     * @param int $org_id Organization post ID
     * @return array|null PageSpeed data or null on failure
     */
    public static function fetchDataFull(int $org_id): ?array {
        $site_url = get_post_meta($org_id, 'site_url', true);

        if (empty($site_url)) {
            Logger::debug('PageSpeedService', 'No site_url for org ' . $org_id);
            return null;
        }

        $data = self::fetchForStrategies($site_url, ['desktop', 'mobile']);

        if ($data) {
            Cache::set('cwv_full_' . md5($site_url), $data, self::CACHE_SECONDS);
        }

        return $data;
    }

    /**
     * Clear PageSpeed cache for an organization.
     *
     * @param int $org_id Organization post ID
     */
    public static function clearCache(int $org_id): void {
        $site_url = get_post_meta($org_id, 'site_url', true);

        if ($site_url) {
            $hash = md5($site_url);
            Cache::delete('cwv_' . $hash);
            Cache::delete('cwv_full_' . $hash);
            Logger::debug('PageSpeedService', 'Cache cleared for ' . $site_url);
        }
    }

    // =========================================================================
    // Private Implementation Methods
    // =========================================================================

    /**
     * Fetch PageSpeed data for specified strategies.
     *
     * @param string $site_url   The URL to test
     * @param array  $strategies Array of strategies ('desktop', 'mobile')
     * @return array|null
     */
    private static function fetchForStrategies(string $site_url, array $strategies): ?array {
        $api_key = defined('BBAB_GOOGLE_API_KEY') ? BBAB_GOOGLE_API_KEY : '';

        $data = [
            'fetched_at' => time(),
            'url' => $site_url,
            'desktop' => null,
            'mobile' => null,
            'from_cache' => false
        ];

        foreach ($strategies as $strategy) {
            $result = self::fetchSingleStrategy($site_url, $strategy, $api_key);

            if ($result) {
                $data[$strategy] = $result;
            }
        }

        // Return null only if we got nothing at all
        if ($data['desktop'] === null && $data['mobile'] === null) {
            return null;
        }

        return $data;
    }

    /**
     * Fetch PageSpeed data for a single strategy.
     *
     * @param string $site_url The URL to test
     * @param string $strategy 'desktop' or 'mobile'
     * @param string $api_key  Optional API key
     * @return array|null Metrics or null on failure
     */
    private static function fetchSingleStrategy(string $site_url, string $strategy, string $api_key): ?array {
        $api_url = add_query_arg([
            'url' => $site_url,
            'strategy' => $strategy,
            'category' => 'performance',
            'key' => $api_key
        ], 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed');

        $response = wp_remote_get($api_url, [
            'timeout' => self::API_TIMEOUT
        ]);

        if (is_wp_error($response)) {
            Logger::error('PageSpeedService', "Failed for {$strategy}", [
                'url' => $site_url,
                'error' => $response->get_error_message()
            ]);
            return null;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code !== 200) {
            Logger::error('PageSpeedService', "API error for {$strategy}", [
                'url' => $site_url,
                'http_code' => $http_code,
                'error' => $body['error']['message'] ?? 'Unknown error'
            ]);
            return null;
        }

        return self::parseResponse($body);
    }

    /**
     * Parse PageSpeed API response into our standard format.
     *
     * @param array $body API response body
     * @return array Parsed metrics
     */
    private static function parseResponse(array $body): array {
        $audits = $body['lighthouseResult']['audits'] ?? [];
        $categories = $body['lighthouseResult']['categories'] ?? [];

        // Extract LCP (Largest Contentful Paint)
        $lcp_seconds = null;
        if (isset($audits['largest-contentful-paint']['numericValue'])) {
            $lcp_seconds = round($audits['largest-contentful-paint']['numericValue'] / 1000, 1);
        }

        // Extract CLS (Cumulative Layout Shift)
        $cls = null;
        if (isset($audits['cumulative-layout-shift']['numericValue'])) {
            $cls = round($audits['cumulative-layout-shift']['numericValue'], 3);
        }

        // Extract overall performance score
        $perf_score = null;
        if (isset($categories['performance']['score'])) {
            $perf_score = (int) round($categories['performance']['score'] * 100);
        }

        // Determine ratings based on Core Web Vitals thresholds
        // LCP: Good < 2.5s, Needs Improvement < 4s, Poor >= 4s
        $lcp_rating = self::getRating($lcp_seconds, 2.5, 4);

        // CLS: Good < 0.1, Needs Improvement < 0.25, Poor >= 0.25
        $cls_rating = self::getRating($cls, 0.1, 0.25);

        return [
            'lcp' => $lcp_seconds,
            'lcp_rating' => $lcp_rating,
            'cls' => $cls,
            'cls_rating' => $cls_rating,
            'performance_score' => $perf_score
        ];
    }

    /**
     * Get a rating string based on value thresholds.
     *
     * @param float|null $value     The metric value
     * @param float      $good_max  Maximum value for "good"
     * @param float      $needs_max Maximum value for "needs-improvement"
     * @return string Rating: 'good', 'needs-improvement', 'poor', or 'unknown'
     */
    private static function getRating(?float $value, float $good_max, float $needs_max): string {
        if ($value === null) {
            return 'unknown';
        }

        if ($value < $good_max) {
            return 'good';
        }

        if ($value < $needs_max) {
            return 'needs-improvement';
        }

        return 'poor';
    }

    /**
     * Get performance score rating.
     *
     * @param int|null $score Performance score (0-100)
     * @return string Rating
     */
    public static function getScoreRating(?int $score): string {
        if ($score === null) {
            return 'unknown';
        }

        if ($score >= 90) {
            return 'good';
        }

        if ($score >= 50) {
            return 'needs-improvement';
        }

        return 'poor';
    }
}
