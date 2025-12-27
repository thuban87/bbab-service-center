<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Global Timer Indicator for admin screens.
 *
 * Shows a persistent bar at the bottom of all admin pages when a timer is running.
 * Also enqueues the timer JavaScript on time_entry edit screens.
 *
 * Migrated from: WPCode Snippets #2332, #2357
 */
class GlobalTimerIndicator {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Enqueue timer script on time_entry screens
        add_action('admin_enqueue_scripts', [self::class, 'enqueueTimerScript']);

        // Render global indicator on all admin pages
        add_action('admin_footer', [self::class, 'renderGlobalIndicator']);

        Logger::debug('GlobalTimerIndicator', 'Registered global timer indicator');
    }

    /**
     * Enqueue the timer script on time_entry screens.
     */
    public static function enqueueTimerScript(): void {
        $screen = get_current_screen();

        if (!$screen || $screen->post_type !== 'time_entry') {
            return;
        }

        wp_enqueue_script(
            'bbab-sc-admin-timer',
            BBAB_SC_URL . 'assets/js/admin-timer.js',
            ['jquery'],
            BBAB_SC_VERSION,
            true
        );
    }

    /**
     * Render the global timer indicator if a timer is running.
     */
    public static function renderGlobalIndicator(): void {
        if (!current_user_can('edit_posts')) {
            return;
        }

        global $wpdb;

        // Check for any running timer
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $running = $wpdb->get_row("
            SELECT p.ID, p.post_title, pm.meta_value as start_timestamp
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
        ");
        // phpcs:enable

        if (!$running) {
            return;
        }

        $edit_url = admin_url('post.php?post=' . $running->ID . '&action=edit');
        $elapsed = time() - intval($running->start_timestamp);

        // Get more info about what this TE is for
        $sr_id = get_post_meta($running->ID, 'related_service_request', true);
        $project_id = get_post_meta($running->ID, 'related_project', true);
        $milestone_id = get_post_meta($running->ID, 'related_milestone', true);

        $context = '';
        if ($sr_id) {
            $ref = get_post_meta($sr_id, 'reference_number', true);
            $subject = get_post_meta($sr_id, 'subject', true);
            $context = $ref . ' - ' . substr($subject, 0, 40);
        } elseif ($project_id) {
            $name = get_post_meta($project_id, 'project_name', true);
            $context = 'Project: ' . $name;
        } elseif ($milestone_id) {
            $name = get_post_meta($milestone_id, 'milestone_name', true);
            $context = 'Milestone: ' . $name;
        }
        ?>
        <div id="bbab-global-timer" style="
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #1e3a5f;
            color: #fff;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            z-index: 99999;
            font-size: 14px;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.2);
        ">
            <span style="color: #ff6b6b; font-size: 18px;">&#9679;</span>
            <span>Timer running:</span>
            <strong id="bbab-global-timer-display" style="font-family: monospace; font-size: 16px;">
                <?php echo esc_html(gmdate('H:i:s', $elapsed)); ?>
            </strong>
            <?php if ($context): ?>
                <span style="color: #aaa;">&#8212;</span>
                <span style="color: #aaa; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <?php echo esc_html($context); ?>
                </span>
            <?php endif; ?>
            <span style="color: #aaa;">&#8212;</span>
            <a href="<?php echo esc_url($edit_url); ?>" style="color: #66b3ff; text-decoration: none;">
                Click to return to Time Entry
            </a>
        </div>
        <script>
        (function() {
            var startTimestamp = <?php echo intval($running->start_timestamp); ?>;
            var display = document.getElementById('bbab-global-timer-display');

            function updateGlobalTimer() {
                var elapsed = Math.floor(Date.now() / 1000) - startTimestamp;
                var hours = Math.floor(elapsed / 3600);
                var minutes = Math.floor((elapsed % 3600) / 60);
                var seconds = elapsed % 60;

                display.textContent =
                    String(hours).padStart(2, '0') + ':' +
                    String(minutes).padStart(2, '0') + ':' +
                    String(seconds).padStart(2, '0');
            }

            setInterval(updateGlobalTimer, 1000);
        })();
        </script>
        <?php
    }
}
