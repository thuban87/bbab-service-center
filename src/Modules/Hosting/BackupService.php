<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Hosting;

use BBAB\ServiceCenter\Modules\Analytics\GoogleAuthService;
use BBAB\ServiceCenter\Utils\Cache;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Google Drive Backup Service.
 *
 * Checks Google Drive for most recent backup file.
 * Uses the same Google service account credentials as Analytics.
 * All data is cached for 36 hours (safety buffer for daily cron).
 *
 * Migrated from: WPCode Snippet #2234
 *
 * Required org meta:
 *   backup_folder_id - Google Drive folder ID where backups are stored
 *   backup_filename_match - String to match in backup filename (e.g., site name)
 */
class BackupService {

    private const CACHE_SECONDS = 36 * HOUR_IN_SECONDS;
    private const API_TIMEOUT = 15;
    private const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

    // =========================================================================
    // Cache-Only Getter (for shortcodes - never trigger API calls)
    // =========================================================================

    /**
     * Get cached backup data. Returns null if not cached.
     * Use this in shortcodes - never triggers API calls.
     *
     * @param int $org_id Organization post ID
     * @return array|null Cached data or null
     */
    public static function getData(int $org_id): ?array {
        $folder_id = get_post_meta($org_id, 'backup_folder_id', true);
        $filename_match = get_post_meta($org_id, 'backup_filename_match', true);

        if (empty($folder_id) || empty($filename_match)) {
            return null;
        }

        return Cache::get('backup_' . $org_id);
    }

    // =========================================================================
    // Fetch Method (for cron - triggers API calls and caches results)
    // =========================================================================

    /**
     * Fetch and cache backup data from Google Drive.
     * USE ONLY IN CRON - triggers live API calls.
     *
     * @param int $org_id Organization post ID
     * @return array|null Backup data or null on failure/not configured
     */
    public static function fetchData(int $org_id): ?array {
        $folder_id = get_post_meta($org_id, 'backup_folder_id', true);
        $filename_match = get_post_meta($org_id, 'backup_filename_match', true);

        if (empty($folder_id) || empty($filename_match)) {
            Logger::debug('BackupService', 'Backup not configured for org ' . $org_id);
            return null;
        }

        $data = self::checkDriveBackup($folder_id, $filename_match);

        if ($data) {
            Cache::set('backup_' . $org_id, $data, self::CACHE_SECONDS);
        }

        return $data;
    }

    /**
     * Clear cached backup data for an organization.
     *
     * @param int $org_id Organization post ID
     */
    public static function clearCache(int $org_id): void {
        Cache::delete('backup_' . $org_id);
        Logger::debug('BackupService', 'Cache cleared for org ' . $org_id);
    }

    // =========================================================================
    // Private Implementation Methods
    // =========================================================================

    /**
     * Check Google Drive for most recent backup file.
     *
     * @param string $folder_id      Google Drive folder ID
     * @param string $filename_match String to match in backup filename
     * @return array|null Array with backup data or null on failure
     */
    private static function checkDriveBackup(string $folder_id, string $filename_match): ?array {
        if (empty($folder_id) || empty($filename_match)) {
            return null;
        }

        // Get access token using GoogleAuthService
        $access_token = GoogleAuthService::getAccessToken([self::DRIVE_SCOPE]);

        if (empty($access_token)) {
            Logger::error('BackupService', 'Failed to authenticate with Google');
            return ['error' => 'Failed to authenticate with Google'];
        }

        // Search for database backup files matching this client
        // We look for files containing the match string AND ending in -db.gz
        $query = sprintf(
            "'%s' in parents and name contains '%s' and name contains '-db' and trashed = false",
            $folder_id,
            $filename_match
        );

        $api_url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => $query,
            'orderBy' => 'createdTime desc',
            'pageSize' => 1,
            'fields' => 'files(id,name,createdTime,size)'
        ]);

        $response = wp_remote_get($api_url, [
            'timeout' => self::API_TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token
            ]
        ]);

        if (is_wp_error($response)) {
            Logger::error('BackupService', 'Connection failed', [
                'folder_id' => $folder_id,
                'error' => $response->get_error_message()
            ]);
            return ['error' => 'Connection failed: ' . $response->get_error_message()];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error';
            Logger::error('BackupService', 'Drive API error', [
                'folder_id' => $folder_id,
                'status_code' => $status_code,
                'error' => $error_msg
            ]);
            return ['error' => 'Drive API: ' . $error_msg];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['files'])) {
            Logger::debug('BackupService', 'No matching backup files found', [
                'folder_id' => $folder_id,
                'filename_match' => $filename_match
            ]);
            return ['error' => 'No matching backup files found'];
        }

        $file = $body['files'][0];

        // Calculate age of backup
        $created_time = strtotime($file['createdTime']);
        $now = time();
        $age_hours = ($now - $created_time) / HOUR_IN_SECONDS;

        Logger::debug('BackupService', 'Successfully checked backup', [
            'filename' => $file['name'],
            'age_hours' => round($age_hours, 1)
        ]);

        return [
            'filename' => $file['name'],
            'created_at' => gmdate('Y-m-d H:i:s', $created_time),
            'age_hours' => round($age_hours, 1),
            'size_bytes' => intval($file['size']),
            'fetched_at' => current_time('mysql')
        ];
    }
}
