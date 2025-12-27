<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\RowActions;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Adds "Log Time" row action to Service Requests, Projects, and Milestones.
 *
 * Creates a convenient link to log time entries directly from
 * admin post lists. The time entry is automatically linked
 * via transient (handled by TimeEntryLinker).
 *
 * Migrated from: WPCode Snippet #1884
 */
class LogTimeAction {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Row action on SR list
        add_filter('post_row_actions', [self::class, 'addRowAction'], 10, 2);

        // Admin notice when creating TE from SR link
        add_action('admin_notices', [self::class, 'showLinkingNotice']);

        Logger::debug('LogTimeAction', 'Registered row action hooks');
    }

    /**
     * Add "Log Time" row action to Service Requests, Projects, and Milestones.
     *
     * @param array    $actions Existing row actions.
     * @param \WP_Post $post    Post object.
     * @return array Modified row actions.
     */
    public static function addRowAction(array $actions, \WP_Post $post): array {
        // Service Request
        if ($post->post_type === 'service_request') {
            $create_url = add_query_arg([
                'post_type' => 'time_entry',
                'related_service_request' => $post->ID,
            ], admin_url('post-new.php'));

            $actions['create_time_entry'] = sprintf(
                '<a href="%s" style="color: #467FF7; font-weight: 500;">%s Log Time</a>',
                esc_url($create_url),
                '⏱️'
            );
        }

        // Project
        if ($post->post_type === 'project') {
            $create_url = add_query_arg([
                'post_type' => 'time_entry',
                'related_project' => $post->ID,
            ], admin_url('post-new.php'));

            $actions['create_time_entry'] = sprintf(
                '<a href="%s" style="color: #467FF7; font-weight: 500;">%s Log Time</a>',
                esc_url($create_url),
                '⏱️'
            );
        }

        // Milestone
        if ($post->post_type === 'milestone') {
            $create_url = add_query_arg([
                'post_type' => 'time_entry',
                'related_milestone' => $post->ID,
            ], admin_url('post-new.php'));

            $actions['create_time_entry'] = sprintf(
                '<a href="%s" style="color: #467FF7; font-weight: 500;">%s Log Time</a>',
                esc_url($create_url),
                '⏱️'
            );
        }

        return $actions;
    }

    /**
     * Show admin notice when creating a time entry from an SR link.
     */
    public static function showLinkingNotice(): void {
        global $pagenow, $post_type;

        if ($pagenow !== 'post-new.php' || $post_type !== 'time_entry') {
            return;
        }

        // Service Request notice
        if (isset($_GET['related_service_request'])) {
            $sr_id = intval($_GET['related_service_request']);

            if (function_exists('pods')) {
                $sr = pods('service_request', $sr_id);
                if ($sr && $sr->exists()) {
                    $ref = $sr->field('reference_number');
                    $subject = $sr->field('subject');
                    ?>
                    <div class="notice notice-info is-dismissible">
                        <p><strong>📋 Logging time for Service Request:</strong> <?php echo esc_html($ref . ' - ' . $subject); ?></p>
                    </div>
                    <?php
                }
            } else {
                // Fallback without Pods
                $sr_post = get_post($sr_id);
                if ($sr_post) {
                    $ref = get_post_meta($sr_id, 'reference_number', true);
                    $subject = get_post_meta($sr_id, 'subject', true);
                    ?>
                    <div class="notice notice-info is-dismissible">
                        <p><strong>📋 Logging time for Service Request:</strong> <?php echo esc_html($ref . ' - ' . $subject); ?></p>
                    </div>
                    <?php
                }
            }
        }

        // Project notice (for Phase 5, but we can add notice support now)
        if (isset($_GET['related_project'])) {
            $project_id = intval($_GET['related_project']);

            if (function_exists('pods')) {
                $project = pods('project', $project_id);
                if ($project && $project->exists()) {
                    $name = $project->field('project_name');
                    ?>
                    <div class="notice notice-info is-dismissible">
                        <p><strong>📁 Logging time for Project:</strong> <?php echo esc_html($name); ?></p>
                        <p style="margin: 5px 0 0 0; color: #666; font-size: 13px;">This will be added to the general project time bucket (not milestone-specific).</p>
                    </div>
                    <?php
                }
            }
        }

        // Milestone notice (for Phase 5)
        if (isset($_GET['related_milestone'])) {
            $milestone_id = intval($_GET['related_milestone']);

            if (function_exists('pods')) {
                $milestone = pods('milestone', $milestone_id);
                if ($milestone && $milestone->exists()) {
                    $name = $milestone->field('milestone_name');
                    $project = $milestone->field('related_project');
                    $project_name = is_array($project) ? ($project['post_title'] ?? 'Unknown Project') : 'Unknown Project';
                    ?>
                    <div class="notice notice-info is-dismissible">
                        <p><strong>🏁 Logging time for Milestone:</strong> <?php echo esc_html($name); ?></p>
                        <p style="margin: 5px 0 0 0; color: #666; font-size: 13px;">Project: <?php echo esc_html($project_name); ?> | This will be linked to this specific milestone.</p>
                    </div>
                    <?php
                }
            }
        }
    }
}
