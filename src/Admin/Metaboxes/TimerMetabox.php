<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Metaboxes;

use BBAB\ServiceCenter\Modules\TimeTracking\TimerService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Timer UI metabox for Time Entry edit screen.
 *
 * Provides start/stop timer buttons with live elapsed time display.
 * AJAX handlers are in TimerService.
 *
 * Migrated from: WPCode Snippet #2327
 */
class TimerMetabox {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        add_action('add_meta_boxes', [self::class, 'registerMetabox']);

        Logger::debug('TimerMetabox', 'Registered timer metabox');
    }

    /**
     * Register the metabox.
     */
    public static function registerMetabox(): void {
        add_meta_box(
            'bbab_timer_box',
            '&#9200; Timer',
            [self::class, 'renderMetabox'],
            'time_entry',
            'side',
            'high'
        );
    }

    /**
     * Render the timer metabox.
     *
     * @param \WP_Post $post Current post object.
     */
    public static function renderMetabox(\WP_Post $post): void {
        $timer_status = get_post_meta($post->ID, 'timer_status', true) ?: 'stopped';
        $start_timestamp = get_post_meta($post->ID, 'start_timestamp', true);
        $time_start = get_post_meta($post->ID, 'time_start', true);
        $time_end = get_post_meta($post->ID, 'time_end', true);

        // Output nonce for AJAX
        wp_nonce_field('bbab_timer_action', 'bbab_timer_nonce');

        echo '<div id="bbab-timer-container" data-post-id="' . esc_attr((string) $post->ID) . '">';

        // Check if this is an unsaved post (auto-draft)
        if ($post->post_status === 'auto-draft') {
            echo '<p style="text-align: center; color: #666; font-style: italic;">Save the time entry first to enable the timer.</p>';
            echo '</div>';
            return;
        }

        if ($timer_status === 'running' && $start_timestamp) {
            // Timer is running - show stop button with elapsed time
            $elapsed = time() - intval($start_timestamp);
            echo '<p style="font-size: 24px; font-weight: bold; text-align: center; margin: 10px 0;" id="bbab-timer-display">';
            echo esc_html(gmdate('H:i:s', $elapsed));
            echo '</p>';
            echo '<button type="button" id="bbab-stop-timer" class="button button-primary" style="width: 100%; height: 40px; font-size: 14px;">';
            echo '&#9209; Stop Timer</button>';
            echo '<input type="hidden" id="bbab-start-timestamp" value="' . esc_attr((string) $start_timestamp) . '">';

        } elseif (!empty($time_start) && !empty($time_end)) {
            // Times already filled in - show reset option
            echo '<p style="text-align: center; color: #666;">Times already entered manually.</p>';
            echo '<button type="button" id="bbab-start-timer" class="button" style="width: 100%; height: 40px; font-size: 14px;">';
            echo '&#9654; Start New Timer</button>';
            echo '<p style="font-size: 11px; color: #999; text-align: center; margin-top: 8px;">This will clear existing times</p>';

        } else {
            // No timer, no times - show start button
            echo '<button type="button" id="bbab-start-timer" class="button button-primary" style="width: 100%; height: 40px; font-size: 14px;">';
            echo '&#9654; Start Timer</button>';
        }

        echo '</div>';
    }
}
