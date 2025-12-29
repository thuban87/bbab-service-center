<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Modules\Projects\ProjectService;
use BBAB\ServiceCenter\Modules\Projects\MilestoneService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Custom admin columns and filters for Projects.
 *
 * Handles:
 * - Custom column definitions and rendering
 * - Admin list filters (Org, Status)
 * - Sortable columns
 * - Column styles
 *
 * Migrated from: WPCode Snippet #1492 (Project section)
 */
class ProjectColumns {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Column definition and rendering
        add_filter('manage_project_posts_columns', [self::class, 'defineColumns']);
        add_action('manage_project_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
        add_filter('manage_edit-project_sortable_columns', [self::class, 'sortableColumns']);

        // Filters
        add_action('restrict_manage_posts', [self::class, 'renderFilters']);
        add_action('pre_get_posts', [self::class, 'applyFilters']);

        // Admin styles
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('ProjectColumns', 'Registered project column hooks');
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
        $new_columns['title'] = 'Project Name';
        $new_columns['organization'] = 'Client';
        $new_columns['project_status'] = 'Status';
        $new_columns['milestone_count'] = 'Milestones';
        $new_columns['total_hours'] = 'Hours';
        $new_columns['total_budget'] = 'Budget';
        $new_columns['invoiced'] = 'Invoiced';
        $new_columns['paid'] = 'Paid';
        $new_columns['start_date'] = 'Start';
        $new_columns['target_date'] = 'Target';

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
                    $edit_url = get_edit_post_link($post_id);
                    echo '<a href="' . esc_url($edit_url) . '" class="project-ref">' . esc_html($ref) . '</a>';
                } else {
                    echo '<span class="no-ref">—</span>';
                }
                break;

            case 'organization':
                $org_id = get_post_meta($post_id, 'organization', true);
                if ($org_id) {
                    $org_name = get_the_title($org_id);
                    $filter_url = admin_url('edit.php?post_type=project&org_filter=' . $org_id);
                    echo '<a href="' . esc_url($filter_url) . '">' . esc_html($org_name) . '</a>';
                } else {
                    echo '—';
                }
                break;

            case 'project_status':
                $status = get_post_meta($post_id, 'project_status', true);
                echo ProjectService::getStatusBadgeHtml($status ?: '');
                break;

            case 'milestone_count':
                $milestones = ProjectService::getMilestones($post_id);
                $total = count($milestones);
                if ($total > 0) {
                    // Count completed milestones
                    $completed = 0;
                    foreach ($milestones as $ms) {
                        $work_status = MilestoneService::getWorkStatus($ms->ID);
                        if ($work_status === 'Completed') {
                            $completed++;
                        }
                    }
                    $url = admin_url('edit.php?post_type=milestone&project_filter=' . $post_id);
                    echo '<a href="' . esc_url($url) . '">' . $completed . '/' . $total . '</a>';
                } else {
                    echo '<span style="color: #999;">0</span>';
                }
                break;

            case 'total_hours':
                $hours = ProjectService::getTotalHours($post_id);
                if ($hours > 0) {
                    echo number_format($hours, 2) . ' hrs';
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                break;

            case 'total_budget':
                $budget = get_post_meta($post_id, 'total_budget', true);
                if ($budget) {
                    echo '$' . number_format((float) $budget, 2);
                } else {
                    echo '—';
                }
                break;

            case 'invoiced':
                $invoiced = ProjectService::getInvoicedTotal($post_id);
                echo $invoiced > 0 ? '$' . number_format($invoiced, 2) : '—';
                break;

            case 'paid':
                $paid = ProjectService::getPaidTotal($post_id);
                echo $paid > 0 ? '$' . number_format($paid, 2) : '—';
                break;

            case 'start_date':
                $date = get_post_meta($post_id, 'start_date', true);
                if (!empty($date) && strtotime($date) !== false && strtotime($date) > 0) {
                    echo esc_html(date('M j, Y', strtotime($date)));
                } else {
                    echo '—';
                }
                break;

            case 'target_date':
                $date = get_post_meta($post_id, 'target_completion', true);
                if (!empty($date) && strtotime($date) !== false && strtotime($date) > 0) {
                    echo esc_html(date('M j, Y', strtotime($date)));
                } else {
                    echo '—';
                }
                break;
        }
    }

    /**
     * Define sortable columns.
     *
     * @param array $columns Sortable columns.
     * @return array Modified sortable columns.
     */
    public static function sortableColumns(array $columns): array {
        $columns['start_date'] = 'start_date';
        $columns['target_date'] = 'target_date';
        $columns['total_budget'] = 'total_budget';
        return $columns;
    }

    /**
     * Render filter dropdowns.
     *
     * @param string $post_type Current post type.
     */
    public static function renderFilters(string $post_type): void {
        if ($post_type !== 'project') {
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

        $selected_org = isset($_GET['org_filter']) ? sanitize_text_field($_GET['org_filter']) : '';

        echo '<select name="org_filter">';
        echo '<option value="">All Clients</option>';
        foreach ($orgs as $org) {
            $selected = selected($selected_org, (string) $org->ID, false);
            echo '<option value="' . esc_attr($org->ID) . '"' . $selected . '>' . esc_html($org->post_title) . '</option>';
        }
        echo '</select>';

        // Status filter
        $statuses = [
            ProjectService::STATUS_ACTIVE,
            ProjectService::STATUS_WAITING,
            ProjectService::STATUS_HOLD,
            ProjectService::STATUS_COMPLETED,
            ProjectService::STATUS_CANCELLED,
        ];
        $selected_status = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';

        echo '<select name="status_filter">';
        echo '<option value="">All Statuses</option>';
        foreach ($statuses as $status) {
            $selected = selected($selected_status, $status, false);
            echo '<option value="' . esc_attr($status) . '"' . $selected . '>' . esc_html($status) . '</option>';
        }
        echo '</select>';
    }

    /**
     * Apply filters to the query.
     *
     * @param \WP_Query $query The query object.
     */
    public static function applyFilters(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== 'project') {
            return;
        }

        $meta_query = $query->get('meta_query') ?: [];

        // Organization filter
        if (!empty($_GET['org_filter'])) {
            $meta_query[] = [
                'key' => 'organization',
                'value' => sanitize_text_field($_GET['org_filter']),
            ];
        }

        // Status filter
        if (!empty($_GET['status_filter'])) {
            $meta_query[] = [
                'key' => 'project_status',
                'value' => sanitize_text_field($_GET['status_filter']),
            ];
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }

        // Handle sorting
        $orderby = $query->get('orderby');
        if ($orderby === 'start_date') {
            $query->set('meta_key', 'start_date');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'target_date') {
            $query->set('meta_key', 'target_completion');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'total_budget') {
            $query->set('meta_key', 'total_budget');
            $query->set('orderby', 'meta_value_num');
        }
    }

    /**
     * Render column and badge styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'project') {
            return;
        }

        echo '<style>
            /* Project Reference */
            .project-ref {
                font-family: monospace;
                font-weight: 600;
                color: #467FF7;
                text-decoration: none;
            }
            .project-ref:hover {
                text-decoration: underline;
            }
            .no-ref {
                color: #999;
            }

            /* Column widths */
            .column-reference { width: 90px; }
            .column-project_status { width: 130px; }
            .column-milestone_count { width: 90px; }
            .column-total_hours { width: 80px; }
            .column-total_budget { width: 100px; }
            .column-invoiced { width: 100px; }
            .column-paid { width: 100px; }
            .column-start_date { width: 100px; }
            .column-target_date { width: 100px; }
        </style>';
    }
}
