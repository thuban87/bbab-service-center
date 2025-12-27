<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Modules\TimeTracking\TimerService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Custom admin columns for Time Entries.
 *
 * Handles:
 * - Custom column definitions and rendering
 * - Display of linked SR/Project/Milestone
 * - Hours display with timer indicator
 * - Organization column
 */
class TimeEntryColumns {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Column definition and rendering
        add_filter('manage_time_entry_posts_columns', [self::class, 'defineColumns']);
        add_action('manage_time_entry_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
        add_filter('manage_edit-time_entry_sortable_columns', [self::class, 'sortableColumns']);

        // Filters
        add_action('restrict_manage_posts', [self::class, 'renderFilters']);
        add_filter('pre_get_posts', [self::class, 'applyFilters']);

        // Admin styles
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('TimeEntryColumns', 'Registered TE column hooks');
    }

    /**
     * Define custom columns.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public static function defineColumns(array $columns): array {
        $new_columns = [];
        $new_columns['cb'] = $columns['cb'];
        $new_columns['reference'] = 'Ref';
        $new_columns['entry_date'] = 'Date';
        $new_columns['description'] = 'Description';
        $new_columns['linked_to'] = 'Linked To';
        $new_columns['organization'] = 'Client';
        $new_columns['time_breakdown'] = 'Time';
        $new_columns['hours'] = 'Hours';
        $new_columns['billable'] = 'Billable';
        $new_columns['work_type'] = 'Type';

        return $new_columns;
    }

    /**
     * Render column content.
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public static function renderColumn(string $column, int $post_id): void {
        switch ($column) {
            case 'reference':
                $ref = get_post_meta($post_id, 'reference_number', true);
                if ($ref) {
                    echo '<span class="te-reference">' . esc_html($ref) . '</span>';
                } else {
                    echo '<span class="te-no-ref">—</span>';
                }
                break;

            case 'entry_date':
                $entry_date = get_post_meta($post_id, 'entry_date', true);
                if (is_array($entry_date)) {
                    $entry_date = reset($entry_date);
                }
                if ($entry_date) {
                    echo esc_html(date('M j, Y', strtotime($entry_date)));
                } else {
                    echo '—';
                }
                break;

            case 'description':
                $description = get_post_meta($post_id, 'description', true);
                $max_length = 60;
                if (strlen($description) > $max_length) {
                    echo esc_html(substr($description, 0, $max_length)) . '...';
                } else {
                    echo esc_html($description ?: '(No description)');
                }
                break;

            case 'linked_to':
                self::renderLinkedToColumn($post_id);
                break;

            case 'organization':
                self::renderOrganizationColumn($post_id);
                break;

            case 'time_breakdown':
                self::renderTimeBreakdownColumn($post_id);
                break;

            case 'hours':
                self::renderHoursColumn($post_id);
                break;

            case 'billable':
                $billable = get_post_meta($post_id, 'billable', true);
                if ($billable === '0' || $billable === 0 || $billable === false) {
                    echo '<span class="te-non-billable">No charge</span>';
                } else {
                    echo '<span class="te-billable">Yes</span>';
                }
                break;

            case 'work_type':
                $work_type_id = get_post_meta($post_id, 'work_type', true);
                if ($work_type_id) {
                    $term = get_term((int) $work_type_id, 'work_type');
                    if ($term && !is_wp_error($term)) {
                        echo esc_html($term->name);
                    } else {
                        echo '—';
                    }
                } else {
                    echo '—';
                }
                break;
        }
    }

    /**
     * Render the "Linked To" column showing SR/Project/Milestone link.
     *
     * @param int $post_id Time entry post ID.
     */
    private static function renderLinkedToColumn(int $post_id): void {
        $sr_id = get_post_meta($post_id, 'related_service_request', true);
        $project_id = get_post_meta($post_id, 'related_project', true);
        $milestone_id = get_post_meta($post_id, 'related_milestone', true);

        if (!empty($sr_id)) {
            $sr_post = get_post((int) $sr_id);
            if ($sr_post) {
                $ref = get_post_meta($sr_id, 'reference_number', true);
                $subject = get_post_meta($sr_id, 'subject', true);
                $edit_url = get_edit_post_link($sr_id);
                echo '<span class="te-link-icon">📋</span> ';
                echo '<a href="' . esc_url($edit_url) . '">' . esc_html($ref) . '</a>';
                echo '<span class="te-link-subject"> - ' . esc_html(substr($subject, 0, 30)) . '</span>';
            } else {
                echo '📋 <em>Deleted SR</em>';
            }
        } elseif (!empty($milestone_id)) {
            $ms_post = get_post((int) $milestone_id);
            if ($ms_post) {
                $name = get_post_meta($milestone_id, 'milestone_name', true);
                $edit_url = get_edit_post_link($milestone_id);
                echo '<span class="te-link-icon">🏁</span> ';
                echo '<a href="' . esc_url($edit_url) . '">' . esc_html($name ?: 'Milestone') . '</a>';
            } else {
                echo '🏁 <em>Deleted Milestone</em>';
            }
        } elseif (!empty($project_id)) {
            $proj_post = get_post((int) $project_id);
            if ($proj_post) {
                $name = get_post_meta($project_id, 'project_name', true);
                $edit_url = get_edit_post_link($project_id);
                echo '<span class="te-link-icon">📁</span> ';
                echo '<a href="' . esc_url($edit_url) . '">' . esc_html($name ?: 'Project') . '</a>';
            } else {
                echo '📁 <em>Deleted Project</em>';
            }
        } else {
            echo '<span class="te-orphan">⚠️ Orphan</span>';
        }
    }

    /**
     * Render organization column (derived from linked item).
     *
     * @param int $post_id Time entry post ID.
     */
    private static function renderOrganizationColumn(int $post_id): void {
        $org_id = 0;

        // Check SR first
        $sr_id = get_post_meta($post_id, 'related_service_request', true);
        if (!empty($sr_id)) {
            $org_id = (int) get_post_meta($sr_id, 'organization', true);
        }

        // Then project
        if (!$org_id) {
            $project_id = get_post_meta($post_id, 'related_project', true);
            if (!empty($project_id)) {
                $org_id = (int) get_post_meta($project_id, 'organization', true);
            }
        }

        // Then milestone (get org from its project)
        if (!$org_id) {
            $milestone_id = get_post_meta($post_id, 'related_milestone', true);
            if (!empty($milestone_id)) {
                $project_id = get_post_meta($milestone_id, 'related_project', true);
                if (!empty($project_id)) {
                    $org_id = (int) get_post_meta($project_id, 'organization', true);
                }
            }
        }

        if ($org_id) {
            echo esc_html(get_the_title($org_id));
        } else {
            echo '—';
        }
    }

    /**
     * Render hours column with timer indicator.
     *
     * @param int $post_id Time entry post ID.
     */
    private static function renderHoursColumn(int $post_id): void {
        $hours = get_post_meta($post_id, 'hours', true);

        // Check if timer is running
        if (TimerService::isRunning($post_id)) {
            $start_timestamp = get_post_meta($post_id, 'start_timestamp', true);
            echo '<span class="te-timer-running">⏱️ Running</span>';
        } elseif ($hours) {
            echo number_format((float) $hours, 2);
        } else {
            echo '—';
        }
    }

    /**
     * Render time breakdown column (actual minutes vs billed minutes).
     *
     * @param int $post_id Time entry post ID.
     */
    private static function renderTimeBreakdownColumn(int $post_id): void {
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
            echo '—';
            return;
        }

        $start_ts = strtotime("today " . $time_start);
        $end_ts = strtotime("today " . $time_end);

        if (!$start_ts || !$end_ts) {
            echo '—';
            return;
        }

        // Handle overnight spans
        if ($end_ts <= $start_ts) {
            $end_ts += 86400;
        }

        $actual_minutes = round(($end_ts - $start_ts) / 60);

        // Get billed hours and convert to minutes
        $hours = get_post_meta($post_id, 'hours', true);
        $billed_minutes = intval(floatval($hours) * 60);

        echo '<span class="te-time-breakdown">';
        echo esc_html(sprintf('%d min → %d min', $actual_minutes, $billed_minutes));
        echo '</span>';
    }

    /**
     * Define sortable columns.
     *
     * @param array $columns Sortable columns.
     * @return array Modified sortable columns.
     */
    public static function sortableColumns(array $columns): array {
        $columns['reference'] = 'reference_number';
        $columns['entry_date'] = 'entry_date';
        $columns['hours'] = 'hours';
        return $columns;
    }

    /**
     * Render filter dropdowns.
     */
    public static function renderFilters(): void {
        global $typenow;

        if ($typenow !== 'time_entry') {
            return;
        }

        // Organization filter
        $orgs = get_posts([
            'post_type' => 'client_organization',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
        ]);

        $selected_org = isset($_GET['filter_org']) ? sanitize_text_field($_GET['filter_org']) : '';

        echo '<select name="filter_org">';
        echo '<option value="">All Clients</option>';
        foreach ($orgs as $org) {
            $selected = selected($selected_org, (string) $org->ID, false);
            echo '<option value="' . esc_attr($org->ID) . '" ' . $selected . '>' .
                 esc_html($org->post_title) . '</option>';
        }
        echo '</select>';

        // Billable filter
        $selected_billable = isset($_GET['filter_billable']) ? sanitize_text_field($_GET['filter_billable']) : '';

        echo '<select name="filter_billable">';
        echo '<option value="">All Entries</option>';
        echo '<option value="1"' . selected($selected_billable, '1', false) . '>Billable Only</option>';
        echo '<option value="0"' . selected($selected_billable, '0', false) . '>Non-Billable Only</option>';
        echo '</select>';
    }

    /**
     * Apply filters to the query.
     *
     * @param \WP_Query $query The query object.
     */
    public static function applyFilters(\WP_Query $query): void {
        global $pagenow, $typenow;

        if ($pagenow !== 'edit.php' || $typenow !== 'time_entry') {
            return;
        }

        if (!$query->is_admin || !$query->is_main_query()) {
            return;
        }

        $meta_query = [];

        // Billable filter
        if (isset($_GET['filter_billable']) && $_GET['filter_billable'] !== '') {
            $meta_query[] = [
                'key' => 'billable',
                'value' => sanitize_text_field($_GET['filter_billable']),
                'compare' => '=',
            ];
        }

        // Organization filter - this is trickier since org is stored on the linked item
        // For now, we'll skip this complex filter - it would require multiple JOINs
        // TODO: Consider implementing with a custom SQL query in Phase 5

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
    }

    /**
     * Render column styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'time_entry') {
            return;
        }

        echo '<style>
            .wp-list-table .column-reference {
                width: 80px;
            }
            .wp-list-table .column-time_breakdown {
                width: 100px;
            }
            .te-reference {
                font-family: monospace;
                font-weight: 600;
                color: #467FF7;
            }
            .te-no-ref {
                color: #999;
            }
            .te-time-breakdown {
                font-size: 11px;
                color: #666;
            }
            .te-link-icon {
                margin-right: 4px;
            }
            .te-link-subject {
                color: #666;
                font-size: 12px;
            }
            .te-orphan {
                color: #c62828;
                font-weight: 500;
            }
            .te-timer-running {
                color: #1976d2;
                font-weight: 500;
                animation: pulse 1.5s infinite;
            }
            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.6; }
            }
            .te-non-billable {
                background: #d5f5e3;
                color: #1e8449;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 500;
            }
            .te-billable {
                color: #666;
                font-size: 12px;
            }
        </style>';
    }
}
