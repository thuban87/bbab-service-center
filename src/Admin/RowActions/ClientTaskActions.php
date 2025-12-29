<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\RowActions;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Quick actions for Client Tasks admin list.
 *
 * Provides:
 * - "Mark Complete" row action for pending tasks
 * - Auto-populates created_date on new tasks
 *
 * Migrated from: WPCode Snippet #1328
 */
class ClientTaskActions {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Row actions
        add_filter('post_row_actions', [self::class, 'addRowActions'], 10, 2);

        // Handle the Mark Complete action
        add_action('admin_action_bbab_complete_client_task', [self::class, 'handleMarkComplete']);

        // Handle the Reopen action
        add_action('admin_action_bbab_reopen_client_task', [self::class, 'handleReopen']);

        // Show success message
        add_action('admin_notices', [self::class, 'showAdminNotices']);

        // Auto-populate created_date on new tasks
        add_action('save_post_client_task', [self::class, 'autoPopulateCreatedDate'], 10, 3);

        Logger::debug('ClientTaskActions', 'Registered client task action hooks');
    }

    /**
     * Add "Mark Complete" row action to pending tasks.
     *
     * @param array    $actions Existing row actions.
     * @param \WP_Post $post    Post object.
     * @return array Modified actions.
     */
    public static function addRowActions(array $actions, \WP_Post $post): array {
        if ($post->post_type !== 'client_task') {
            return $actions;
        }

        $status = get_post_meta($post->ID, 'task_status', true);

        if ($status === 'Pending') {
            $complete_url = wp_nonce_url(
                admin_url('admin.php?action=bbab_complete_client_task&task_id=' . $post->ID),
                'bbab_complete_task_' . $post->ID
            );
            $actions['complete'] = '<a href="' . esc_url($complete_url) . '" style="color: #22c55e; font-weight: 500;">Mark Complete</a>';
        } elseif ($status === 'Completed') {
            $reopen_url = wp_nonce_url(
                admin_url('admin.php?action=bbab_reopen_client_task&task_id=' . $post->ID),
                'bbab_reopen_task_' . $post->ID
            );
            $actions['reopen'] = '<a href="' . esc_url($reopen_url) . '" style="color: #f59e0b; font-weight: 500;">Reopen</a>';
        }

        return $actions;
    }

    /**
     * Handle the "Mark Complete" action.
     */
    public static function handleMarkComplete(): void {
        $task_id = isset($_GET['task_id']) ? (int) $_GET['task_id'] : 0;

        if (!$task_id) {
            wp_die('Invalid task ID');
        }

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_complete_task_' . $task_id)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('edit_post', $task_id)) {
            wp_die('Permission denied');
        }

        $task = get_post($task_id);
        if (!$task || $task->post_type !== 'client_task') {
            wp_die('Invalid client task');
        }

        // Update status and completed date
        update_post_meta($task_id, 'task_status', 'Completed');
        update_post_meta($task_id, 'completed_date', current_time('m/d/Y'));

        Logger::debug('ClientTaskActions', 'Task marked complete', ['task_id' => $task_id]);

        // Redirect back to list
        wp_redirect(admin_url('edit.php?post_type=client_task&task_completed=1'));
        exit;
    }

    /**
     * Handle the "Reopen" action for completed tasks.
     */
    public static function handleReopen(): void {
        $task_id = isset($_GET['task_id']) ? (int) $_GET['task_id'] : 0;

        if (!$task_id) {
            wp_die('Invalid task ID');
        }

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_reopen_task_' . $task_id)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('edit_post', $task_id)) {
            wp_die('Permission denied');
        }

        $task = get_post($task_id);
        if (!$task || $task->post_type !== 'client_task') {
            wp_die('Invalid client task');
        }

        // Update status back to Pending and clear completed date
        update_post_meta($task_id, 'task_status', 'Pending');
        delete_post_meta($task_id, 'completed_date');

        Logger::debug('ClientTaskActions', 'Task reopened', ['task_id' => $task_id]);

        // Redirect back to list
        wp_redirect(admin_url('edit.php?post_type=client_task&task_reopened=1'));
        exit;
    }

    /**
     * Show success message after completing a task.
     */
    public static function showAdminNotices(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'client_task') {
            return;
        }

        if (isset($_GET['task_completed']) && $_GET['task_completed'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Task marked as complete.</p></div>';
        }

        if (isset($_GET['task_reopened']) && $_GET['task_reopened'] === '1') {
            echo '<div class="notice notice-info is-dismissible"><p>Task reopened and set to Pending.</p></div>';
        }
    }

    /**
     * Auto-populate created_date on new tasks.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update.
     */
    public static function autoPopulateCreatedDate(int $post_id, \WP_Post $post, bool $update): void {
        // Don't run on autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Only on new posts (not updates)
        if ($update) {
            return;
        }

        // Check if created_date already exists
        $existing = get_post_meta($post_id, 'created_date', true);
        if (!empty($existing)) {
            return;
        }

        update_post_meta($post_id, 'created_date', current_time('m/d/Y'));

        Logger::debug('ClientTaskActions', 'Auto-populated created_date for new task', ['task_id' => $post_id]);
    }
}
