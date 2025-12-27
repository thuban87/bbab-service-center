<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Cron;

use BBAB\ServiceCenter\Utils\Logger;
use BBAB\ServiceCenter\Utils\Settings;

/**
 * Handles forgotten timer detection and alerting.
 *
 * Runs every 30 minutes to check for timers that have been running
 * for more than 4 hours, then sends an email alert.
 *
 * Migrated from: WPCode Snippet #2360
 */
class ForgottenTimerHandler {

    /**
     * Threshold in seconds for a timer to be considered "forgotten".
     * 4 hours = 14400 seconds
     */
    private const FORGOTTEN_THRESHOLD = 4 * 3600;

    /**
     * Check for forgotten timers and send alert if found.
     *
     * This is the main cron handler method.
     */
    public static function check(): void {
        $forgotten = self::getForgottenTimers();

        if (empty($forgotten)) {
            Logger::debug('ForgottenTimerHandler', 'No forgotten timers found');
            return;
        }

        $count = count($forgotten);
        Logger::debug('ForgottenTimerHandler', "Found {$count} forgotten timer(s)");

        self::sendAlertEmail($forgotten);
    }

    /**
     * Get all timers that have been running longer than the threshold.
     *
     * @return array Array of objects with ID, post_title, start_timestamp
     */
    public static function getForgottenTimers(): array {
        global $wpdb;

        $threshold_time = time() - self::FORGOTTEN_THRESHOLD;

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, pm.meta_value as start_timestamp
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
            WHERE p.post_type = 'time_entry'
            AND p.post_status IN ('publish', 'draft')
            AND pm.meta_key = 'start_timestamp'
            AND pm.meta_value != ''
            AND pm.meta_value < %d
            AND pm2.meta_key = 'timer_status'
            AND pm2.meta_value = 'running'
        ", $threshold_time));

        return $results ?: [];
    }

    /**
     * Send alert email about forgotten timers.
     *
     * @param array $forgotten Array of forgotten timer objects.
     */
    private static function sendAlertEmail(array $forgotten): void {
        $count = count($forgotten);
        $recipient = Settings::get('forgotten_timer_email', get_option('admin_email'));

        $subject = sprintf(
            '%s BBAB: %d forgotten timer%s running',
            "\u{26A0}\u{FE0F}", // Warning emoji
            $count,
            $count > 1 ? 's' : ''
        );

        $message = "The following time entry timer(s) have been running for more than 4 hours:\n\n";

        foreach ($forgotten as $timer) {
            $elapsed_hours = round((time() - intval($timer->start_timestamp)) / 3600, 1);
            $edit_url = admin_url('post.php?post=' . $timer->ID . '&action=edit');

            $message .= sprintf(
                "* TE #%d - Running for %s hours\n  Edit: %s\n\n",
                $timer->ID,
                $elapsed_hours,
                $edit_url
            );
        }

        $message .= "You may want to stop these timers and adjust the times as needed.";

        $sent = wp_mail($recipient, $subject, $message);

        if ($sent) {
            Logger::debug('ForgottenTimerHandler', "Sent alert email about {$count} forgotten timer(s) to {$recipient}");
        } else {
            Logger::error('ForgottenTimerHandler', "Failed to send alert email to {$recipient}");
        }
    }

    /**
     * Manual trigger for testing (called via AJAX from Settings page).
     *
     * @return array Result with 'success', 'message', and optionally 'data'.
     */
    public static function manualCheck(): array {
        $forgotten = self::getForgottenTimers();

        if (empty($forgotten)) {
            return [
                'success' => true,
                'message' => 'No forgotten timers found (none running 4+ hours).',
                'data' => [
                    'count' => 0,
                    'email_sent' => false,
                ],
            ];
        }

        $count = count($forgotten);
        self::sendAlertEmail($forgotten);

        return [
            'success' => true,
            'message' => sprintf('Found %d forgotten timer(s). Alert email sent.', $count),
            'data' => [
                'count' => $count,
                'email_sent' => true,
                'timers' => array_map(function($timer) {
                    return [
                        'id' => $timer->ID,
                        'elapsed_hours' => round((time() - intval($timer->start_timestamp)) / 3600, 1),
                    ];
                }, $forgotten),
            ],
        ];
    }
}
