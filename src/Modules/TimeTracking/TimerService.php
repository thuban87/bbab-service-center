<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\TimeTracking;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Timer functionality for time entries.
 *
 * Handles:
 * - Timer start/stop operations
 * - Single timer enforcement (only one timer globally)
 * - Kill timer when entry is trashed
 * - AJAX handlers for timer UI
 *
 * Migrated from: WPCode Snippets #2331, #2363
 */
class TimerService {

    /**
     * Register all timer-related hooks.
     */
    public static function register(): void {
        // Kill timer when time entry is trashed
        add_action('wp_trash_post', [self::class, 'handleTrash']);

        // AJAX handlers for timer UI (using existing action names for backward compatibility)
        add_action('wp_ajax_bbab_start_timer', [self::class, 'handleStartAjax']);
        add_action('wp_ajax_bbab_stop_timer', [self::class, 'handleStopAjax']);

        Logger::debug('TimerService', 'Registered timer hooks');
    }

    /**
     * Start the timer for a time entry.
     *
     * @param int $post_id Time entry post ID.
     * @return array Result with 'success' boolean and 'message' or 'data'.
     */
    public static function start(int $post_id): array {
        // Verify it's a time entry
        if (get_post_type($post_id) !== 'time_entry') {
            return [
                'success' => false,
                'message' => 'Invalid post type',
            ];
        }

        // Check for existing running timer
        $existing = self::getRunningTimer();
        if ($existing && $existing['post_id'] !== $post_id) {
            return [
                'success' => false,
                'message' => 'Another timer is already running (TE #' . $existing['post_id'] . '). Stop it first.',
            ];
        }

        // Clear any existing times if starting fresh
        update_post_meta($post_id, 'time_start', '');
        update_post_meta($post_id, 'time_end', '');
        update_post_meta($post_id, 'hours', '');

        // Start the timer
        $now = time();
        update_post_meta($post_id, 'timer_status', 'running');
        update_post_meta($post_id, 'start_timestamp', $now);

        Logger::debug('TimerService', "Started timer for TE {$post_id}", [
            'timestamp' => $now,
        ]);

        return [
            'success' => true,
            'data' => [
                'start_timestamp' => $now,
                'message' => 'Timer started',
            ],
        ];
    }

    /**
     * Stop the timer for a time entry.
     *
     * @param int $post_id Time entry post ID.
     * @return array Result with 'success' boolean and 'message' or 'data'.
     */
    public static function stop(int $post_id): array {
        // Verify it's a time entry
        if (get_post_type($post_id) !== 'time_entry') {
            return [
                'success' => false,
                'message' => 'Invalid post type',
            ];
        }

        $start_timestamp = get_post_meta($post_id, 'start_timestamp', true);

        if (empty($start_timestamp)) {
            return [
                'success' => false,
                'message' => 'No start timestamp found',
            ];
        }

        $now = time();

        // Format times with timezone awareness (using WordPress's configured timezone)
        $time_start = wp_date('g:i A', intval($start_timestamp));
        $time_end = wp_date('g:i A', $now);

        // Calculate elapsed hours, rounded UP to nearest quarter hour (0.25)
        $elapsed_seconds = $now - intval($start_timestamp);
        $raw_hours = $elapsed_seconds / 3600;
        $hours = ceil($raw_hours * 4) / 4; // Round up to nearest 0.25

        // Update time fields
        update_post_meta($post_id, 'time_start', $time_start);
        update_post_meta($post_id, 'time_end', $time_end);
        update_post_meta($post_id, 'hours', $hours);

        // Stop the timer
        update_post_meta($post_id, 'timer_status', 'stopped');
        update_post_meta($post_id, 'start_timestamp', '');

        Logger::debug('TimerService', "Stopped timer for TE {$post_id}", [
            'time_start' => $time_start,
            'time_end' => $time_end,
            'hours' => $hours,
        ]);

        return [
            'success' => true,
            'data' => [
                'time_start' => $time_start,
                'time_end' => $time_end,
                'hours' => $hours,
                'message' => 'Timer stopped',
            ],
        ];
    }

    /**
     * Get the currently running timer, if any.
     *
     * @return array|null Array with 'post_id' and 'start_timestamp', or null.
     */
    public static function getRunningTimer(): ?array {
        global $wpdb;

        $result = $wpdb->get_row("
            SELECT p.ID as post_id, pm.meta_value as start_timestamp
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
            WHERE p.post_type = 'time_entry'
            AND p.post_status IN ('publish', 'draft')
            AND pm.meta_key = 'start_timestamp'
            AND pm.meta_value != ''
            AND pm2.meta_key = 'timer_status'
            AND pm2.meta_value = 'running'
            LIMIT 1
        ", ARRAY_A);

        if (!$result) {
            return null;
        }

        return [
            'post_id' => intval($result['post_id']),
            'start_timestamp' => intval($result['start_timestamp']),
        ];
    }

    /**
     * Kill (stop without saving times) a timer.
     *
     * Used when trashing a time entry with a running timer.
     *
     * @param int $post_id Time entry post ID.
     */
    public static function killTimer(int $post_id): void {
        $timer_status = get_post_meta($post_id, 'timer_status', true);

        if ($timer_status === 'running') {
            update_post_meta($post_id, 'timer_status', 'stopped');
            update_post_meta($post_id, 'start_timestamp', '');

            Logger::debug('TimerService', "Killed timer on TE {$post_id}");
        }
    }

    /**
     * Handle wp_trash_post hook - kill timer when entry is trashed.
     *
     * @param int $post_id Post ID being trashed.
     */
    public static function handleTrash(int $post_id): void {
        if (get_post_type($post_id) !== 'time_entry') {
            return;
        }

        self::killTimer($post_id);
    }

    /**
     * AJAX handler for starting timer.
     *
     * Uses existing action name (bbab_start_timer) for backward compatibility
     * with timer UI from snippet 2327/2332.
     */
    public static function handleStartAjax(): void {
        // Verify nonce (using existing nonce action for backward compatibility)
        if (!check_ajax_referer('bbab_timer_action', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
            return;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
            return;
        }

        $result = self::start($post_id);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX handler for stopping timer.
     *
     * Uses existing action name (bbab_stop_timer) for backward compatibility
     * with timer UI from snippet 2327/2332.
     */
    public static function handleStopAjax(): void {
        // Verify nonce (using existing nonce action for backward compatibility)
        if (!check_ajax_referer('bbab_timer_action', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
            return;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
            return;
        }

        $result = self::stop($post_id);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Get elapsed time string for a running timer.
     *
     * @param int $start_timestamp Unix timestamp when timer started.
     * @return string Formatted elapsed time (H:i:s).
     */
    public static function getElapsedTimeString(int $start_timestamp): string {
        $elapsed = time() - $start_timestamp;
        return gmdate('H:i:s', $elapsed);
    }

    /**
     * Get elapsed hours for a running timer (for estimation).
     *
     * @param int $start_timestamp Unix timestamp when timer started.
     * @return float Elapsed hours (not rounded).
     */
    public static function getElapsedHours(int $start_timestamp): float {
        $elapsed = time() - $start_timestamp;
        return $elapsed / 3600;
    }

    /**
     * Check if a time entry has a running timer.
     *
     * @param int $post_id Time entry post ID.
     * @return bool True if timer is running.
     */
    public static function isRunning(int $post_id): bool {
        $timer_status = get_post_meta($post_id, 'timer_status', true);
        return $timer_status === 'running';
    }
}
