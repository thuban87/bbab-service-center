<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\TimeTracking;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Time Entry business logic service.
 *
 * Handles:
 * - Hours calculation with 15-minute rounding
 * - Orphan prevention (entries must link to SR, Project, or Milestone)
 * - Transient-based linking from "Log Time" row actions
 * - Query helpers for retrieving time entries
 *
 * Migrated from: WPCode Snippets #1863, #1886
 */
class TimeEntryService {

    /**
     * Register all hooks for time entry processing.
     */
    public static function register(): void {
        // Main save hook - hours calculation + transient linking
        add_action('save_post_time_entry', [self::class, 'handleSave'], 10, 3);

        // Pods-specific save hook (runs after Pods saves field data)
        add_action('pods_api_post_save_pod_item_time_entry', [self::class, 'handlePodsSave'], 10, 3);

        // Orphan prevention - runs after linking attempt
        add_action('save_post_time_entry', [self::class, 'validateOrphan'], 20, 3);

        // Time format validation
        add_action('save_post_time_entry', [self::class, 'validateTimeFormat'], 25, 3);

        // Admin notice for time format errors
        add_action('admin_notices', [self::class, 'showTimeFormatError']);

        Logger::debug('TimeEntryService', 'Registered time entry hooks');
    }

    /**
     * Main save handler for time entries.
     *
     * Handles hours calculation and transient-based linking.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update.
     */
    public static function handleSave(int $post_id, \WP_Post $post, bool $update): void {
        // Skip autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        Logger::debug('TimeEntryService', "handleSave fired for post {$post_id}", [
            'update' => $update,
        ]);

        // Section 1: Auto-calculate hours (runs on both new and update)
        self::calculateAndSaveHours($post_id);

        // Section 2: Transient linking (only on new posts)
        if (!$update) {
            self::linkFromTransients($post_id);
        }
    }

    /**
     * Pods-specific save handler.
     *
     * Runs after Pods saves field data, handles array unwrapping.
     *
     * @param mixed $pieces      Save pieces from Pods (can be null or array).
     * @param mixed $is_new_item Whether this is a new item.
     * @param int   $id          Post ID.
     */
    public static function handlePodsSave($pieces, $is_new_item, int $id): void {
        Logger::debug('TimeEntryService', "Pods save hook fired for post {$id}");

        // Pods sometimes stores times as arrays - unwrap them
        $time_start = get_post_meta($id, 'time_start', true);
        $time_end = get_post_meta($id, 'time_end', true);

        if (is_array($time_start)) {
            $time_start = reset($time_start);
        }
        if (is_array($time_end)) {
            $time_end = reset($time_end);
        }

        if (!empty($time_start) && !empty($time_end)) {
            $hours = self::calculateHours($time_start, $time_end);

            if ($hours !== null) {
                update_post_meta($id, 'hours', $hours);
                delete_post_meta($id, '_time_format_error');
                Logger::debug('TimeEntryService', "Pods calc: Set {$hours} billable hours");
            } else {
                update_post_meta($id, '_time_format_error', '1');
                update_post_meta($id, 'hours', '');
                Logger::warning('TimeEntryService', 'Pods calc: Invalid time format');
            }
        }
    }

    /**
     * Calculate hours and save to post meta.
     *
     * @param int $post_id Post ID.
     */
    private static function calculateAndSaveHours(int $post_id): void {
        $time_start = get_post_meta($post_id, 'time_start', true);
        $time_end = get_post_meta($post_id, 'time_end', true);

        // Handle Pods array storage
        if (is_array($time_start)) {
            $time_start = reset($time_start);
        }
        if (is_array($time_end)) {
            $time_end = reset($time_end);
        }

        if (empty($time_start) || empty($time_end)) {
            Logger::debug('TimeEntryService', 'No start/end times set', [
                'time_start' => $time_start,
                'time_end' => $time_end,
            ]);
            return;
        }

        $hours = self::calculateHours($time_start, $time_end);

        if ($hours !== null) {
            update_post_meta($post_id, 'hours', $hours);
            Logger::debug('TimeEntryService', "Set billable hours to {$hours}");
        } else {
            Logger::warning('TimeEntryService', 'Failed to calculate hours - invalid time format');
        }
    }

    /**
     * Calculate billable hours from start/end times.
     *
     * Rounds UP to nearest 15 minutes.
     *
     * @param string $time_start Start time (e.g., "2:15 PM" or "14:15").
     * @param string $time_end   End time.
     * @return float|null Billable hours or null if parsing failed.
     */
    public static function calculateHours(string $time_start, string $time_end): ?float {
        $time_start = trim($time_start);
        $time_end = trim($time_end);

        $start_timestamp = strtotime("today " . $time_start);
        $end_timestamp = strtotime("today " . $time_end);

        if (!$start_timestamp || !$end_timestamp) {
            Logger::debug('TimeEntryService', 'Failed to parse times', [
                'start' => $time_start,
                'end' => $time_end,
            ]);
            return null;
        }

        // Handle same-minute entries (sub-minute timer runs)
        // When start and end round to same minute, return minimum billing
        if ($end_timestamp === $start_timestamp) {
            Logger::debug('TimeEntryService', 'Same-minute entry, returning minimum billing', [
                'start' => $time_start,
                'end' => $time_end,
            ]);
            return 0.25; // Minimum 15-minute billing increment
        }

        // Handle overnight spans (end time is before start - crossed midnight)
        if ($end_timestamp < $start_timestamp) {
            $end_timestamp += 86400; // Add 24 hours
        }

        $seconds = $end_timestamp - $start_timestamp;
        $minutes = round($seconds / 60);

        // Round UP to nearest 15 minutes
        $billable_hours = ceil($minutes / 15) * 0.25;

        Logger::debug('TimeEntryService', 'Calculated hours', [
            'actual_minutes' => $minutes,
            'billable_hours' => $billable_hours,
        ]);

        return $billable_hours;
    }

    /**
     * Link time entry from transients set by "Log Time" row actions.
     *
     * @param int $post_id Post ID.
     */
    private static function linkFromTransients(int $post_id): void {
        $user_id = get_current_user_id();

        // Service Request linking
        $pending_sr = get_transient('bbab_pending_sr_link_' . $user_id);
        if ($pending_sr) {
            $sr_id = absint($pending_sr);
            $sr_post = get_post($sr_id);

            if ($sr_post && $sr_post->post_type === 'service_request') {
                update_post_meta($post_id, 'related_service_request', $sr_id);

                // Set default entry type if not set
                $current_entry_type = get_post_meta($post_id, 'entry_type', true);
                if (empty($current_entry_type)) {
                    update_post_meta($post_id, 'entry_type', 'Monthly Support');
                }

                // Set description from SR subject if not set
                $current_desc = get_post_meta($post_id, 'description', true);
                if (empty($current_desc)) {
                    $subject = get_post_meta($sr_id, 'subject', true);
                    if ($subject) {
                        update_post_meta($post_id, 'description', $subject);
                    }
                }

                delete_transient('bbab_pending_sr_link_' . $user_id);
                Logger::debug('TimeEntryService', "Linked to SR {$sr_id}");
            }
        }

        // Project linking
        $pending_project = get_transient('bbab_pending_project_link_' . $user_id);
        if ($pending_project) {
            $project_id = absint($pending_project);
            $project_post = get_post($project_id);

            if ($project_post && $project_post->post_type === 'project') {
                update_post_meta($post_id, 'related_project', $project_id);

                // Set default entry type if not set
                $current_entry_type = get_post_meta($post_id, 'entry_type', true);
                if (empty($current_entry_type)) {
                    update_post_meta($post_id, 'entry_type', 'Project');
                }

                // Set description from project name if not set
                $current_desc = get_post_meta($post_id, 'description', true);
                if (empty($current_desc)) {
                    $name = get_post_meta($project_id, 'project_name', true);
                    if ($name) {
                        update_post_meta($post_id, 'description', 'Project: ' . $name);
                    }
                }

                delete_transient('bbab_pending_project_link_' . $user_id);
                Logger::debug('TimeEntryService', "Linked to Project {$project_id}");
            }
        }

        // Milestone linking
        $pending_milestone = get_transient('bbab_pending_milestone_link_' . $user_id);
        if ($pending_milestone) {
            $milestone_id = absint($pending_milestone);
            $milestone_post = get_post($milestone_id);

            if ($milestone_post && $milestone_post->post_type === 'milestone') {
                update_post_meta($post_id, 'related_milestone', $milestone_id);

                // Set default entry type if not set
                $current_entry_type = get_post_meta($post_id, 'entry_type', true);
                if (empty($current_entry_type)) {
                    update_post_meta($post_id, 'entry_type', 'Project');
                }

                // Set description from milestone/project names if not set
                $current_desc = get_post_meta($post_id, 'description', true);
                if (empty($current_desc)) {
                    $m_name = get_post_meta($milestone_id, 'milestone_name', true);
                    $project_id = get_post_meta($milestone_id, 'related_project', true);
                    $p_name = '';
                    if ($project_id) {
                        $p_name = get_post_meta($project_id, 'project_name', true);
                    }
                    if ($m_name) {
                        $desc = $p_name ? "{$p_name} - {$m_name}" : $m_name;
                        update_post_meta($post_id, 'description', $desc);
                    }
                }

                delete_transient('bbab_pending_milestone_link_' . $user_id);
                Logger::debug('TimeEntryService', "Linked to Milestone {$milestone_id}");
            }
        }
    }

    /**
     * Validate that time entry has at least one relationship.
     *
     * Prevents orphan entries by checking for SR, Project, or Milestone link.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update.
     */
    public static function validateOrphan(int $post_id, \WP_Post $post, bool $update): void {
        // Skip autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $user_id = get_current_user_id();

        // Skip if there are pending transients (linking will happen)
        if (get_transient('bbab_pending_sr_link_' . $user_id) ||
            get_transient('bbab_pending_project_link_' . $user_id) ||
            get_transient('bbab_pending_milestone_link_' . $user_id)) {
            return;
        }

        // Check relationships
        $sr = get_post_meta($post_id, 'related_service_request', true);
        $project = get_post_meta($post_id, 'related_project', true);
        $milestone = get_post_meta($post_id, 'related_milestone', true);

        if (empty($sr) && empty($project) && empty($milestone)) {
            // Trash the orphan post (use trash instead of hard delete for recovery buffer)
            wp_trash_post($post_id);

            Logger::warning('TimeEntryService', "Trashed orphan time entry {$post_id}");

            wp_die(
                '<h1>Time Entry Not Saved</h1>' .
                '<p>Time entries must be linked to a Service Request, Project, or Milestone.</p>' .
                '<p><strong>Please use the "Log Time" button</strong> from the Service Request, Project, or Milestone you\'re working on.</p>' .
                '<p><a href="' . esc_url(admin_url('edit.php?post_type=service_request')) . '">&larr; Go to Service Requests</a> | ' .
                '<a href="' . esc_url(admin_url('edit.php?post_type=project')) . '">&larr; Go to Projects</a></p>',
                'Time Entry Error',
                ['back_link' => true]
            );
        }
    }

    /**
     * Validate time format and flag errors.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update.
     */
    public static function validateTimeFormat(int $post_id, \WP_Post $post, bool $update): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $time_start = get_post_meta($post_id, 'time_start', true);
        $time_end = get_post_meta($post_id, 'time_end', true);

        // Handle Pods array storage
        if (is_array($time_start)) {
            $time_start = reset($time_start);
        }
        if (is_array($time_end)) {
            $time_end = reset($time_end);
        }

        if (!empty($time_start) && !empty($time_end)) {
            $start_ok = strtotime("today " . $time_start);
            $end_ok = strtotime("today " . $time_end);

            if (!$start_ok || !$end_ok) {
                update_post_meta($post_id, '_time_format_error', '1');
                Logger::warning('TimeEntryService', 'Invalid time format detected', [
                    'time_start' => $time_start,
                    'time_end' => $time_end,
                ]);
            } else {
                delete_post_meta($post_id, '_time_format_error');
            }
        }
    }

    /**
     * Show admin notice for time format errors.
     */
    public static function showTimeFormatError(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'time_entry') {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $error = get_post_meta($post->ID, '_time_format_error', true);

        if ($error) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Time Format Error:</strong> Invalid time format detected. ';
            echo 'Use format like <code>4:01 AM</code> or <code>16:01</code>. ';
            echo 'Hours may not have been calculated correctly.';
            echo '</p></div>';
        }
    }

    /**
     * Get time entries for a service request.
     *
     * @param int $sr_id Service request ID.
     * @return array Array of time entry post objects.
     */
    public static function getForServiceRequest(int $sr_id): array {
        return get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => 'related_service_request',
                    'value' => $sr_id,
                    'compare' => '=',
                ],
            ],
            'orderby' => 'meta_value',
            'meta_key' => 'entry_date',
            'order' => 'DESC',
        ]);
    }

    /**
     * Get time entries for a project (general bucket only, excludes milestone-specific).
     *
     * @param int $project_id Project ID.
     * @return array Array of time entry post objects.
     */
    public static function getForProject(int $project_id): array {
        global $wpdb;

        // Get IDs of entries linked to project but NOT to a milestone
        $te_ids = $wpdb->get_col($wpdb->prepare("
            SELECT p.post_id
            FROM {$wpdb->postmeta} p
            LEFT JOIN {$wpdb->postmeta} m ON p.post_id = m.post_id AND m.meta_key = 'related_milestone'
            WHERE p.meta_key = 'related_project'
            AND p.meta_value = %d
            AND (m.meta_value IS NULL OR m.meta_value = '')
        ", $project_id));

        if (empty($te_ids)) {
            return [];
        }

        return get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'post__in' => array_map('intval', $te_ids),
            'orderby' => 'meta_value',
            'meta_key' => 'entry_date',
            'order' => 'DESC',
        ]);
    }

    /**
     * Get time entries for a milestone.
     *
     * @param int $milestone_id Milestone ID.
     * @return array Array of time entry post objects.
     */
    public static function getForMilestone(int $milestone_id): array {
        return get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => 'related_milestone',
                    'value' => $milestone_id,
                    'compare' => '=',
                ],
            ],
            'orderby' => 'meta_value',
            'meta_key' => 'entry_date',
            'order' => 'DESC',
        ]);
    }

    /**
     * Get total billable hours for a service request.
     *
     * @param int $sr_id Service request ID.
     * @return float Total hours.
     */
    public static function getTotalHoursForSR(int $sr_id): float {
        $entries = self::getForServiceRequest($sr_id);
        $total = 0.0;

        foreach ($entries as $entry) {
            $hours = get_post_meta($entry->ID, 'hours', true);
            $total += floatval($hours);
        }

        return $total;
    }

    /**
     * Get total billable hours for a project (including all milestones).
     *
     * @param int $project_id Project ID.
     * @return float Total hours.
     */
    public static function getTotalHoursForProject(int $project_id): float {
        global $wpdb;

        // Get all time entries linked to this project OR any of its milestones
        $total = $wpdb->get_var($wpdb->prepare("
            SELECT SUM(CAST(pm_hours.meta_value AS DECIMAL(10,2)))
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_hours ON p.ID = pm_hours.post_id AND pm_hours.meta_key = 'hours'
            LEFT JOIN {$wpdb->postmeta} pm_project ON p.ID = pm_project.post_id AND pm_project.meta_key = 'related_project'
            LEFT JOIN {$wpdb->postmeta} pm_milestone ON p.ID = pm_milestone.post_id AND pm_milestone.meta_key = 'related_milestone'
            WHERE p.post_type = 'time_entry'
            AND p.post_status = 'publish'
            AND (
                pm_project.meta_value = %d
                OR pm_milestone.meta_value IN (
                    SELECT ID FROM {$wpdb->posts}
                    WHERE post_type = 'milestone'
                    AND post_status = 'publish'
                    AND ID IN (
                        SELECT post_id FROM {$wpdb->postmeta}
                        WHERE meta_key = 'related_project'
                        AND meta_value = %d
                    )
                )
            )
        ", $project_id, $project_id));

        return floatval($total);
    }

    /**
     * Get total billable hours for a milestone.
     *
     * @param int $milestone_id Milestone ID.
     * @return float Total hours.
     */
    public static function getTotalHoursForMilestone(int $milestone_id): float {
        $entries = self::getForMilestone($milestone_id);
        $total = 0.0;

        foreach ($entries as $entry) {
            $hours = get_post_meta($entry->ID, 'hours', true);
            $total += floatval($hours);
        }

        return $total;
    }

    /**
     * Check if a time entry has a valid relationship.
     *
     * @param int $post_id Post ID.
     * @return bool True if valid relationship exists.
     */
    public static function hasValidRelationship(int $post_id): bool {
        $sr = get_post_meta($post_id, 'related_service_request', true);
        $project = get_post_meta($post_id, 'related_project', true);
        $milestone = get_post_meta($post_id, 'related_milestone', true);

        return !empty($sr) || !empty($project) || !empty($milestone);
    }

    /**
     * Get time entry IDs for a service request.
     *
     * More efficient than getForServiceRequest() when you only need IDs.
     *
     * @param int $sr_id Service request ID.
     * @return array Array of time entry IDs.
     */
    public static function getEntriesForSR(int $sr_id): array {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare("
            SELECT pm.post_id
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = 'related_service_request'
            AND pm.meta_value = %d
            AND p.post_status = 'publish'
            AND p.post_type = 'time_entry'
            ORDER BY pm.post_id DESC
        ", $sr_id));

        return array_map('intval', $ids);
    }
}
