<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Modules\Projects\MilestoneService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Custom admin columns and filters for Milestones.
 *
 * Handles:
 * - Custom column definitions and rendering
 * - Admin list filters (Project, Payment Status)
 * - Post-query filtering for calculated payment status
 * - Column styles
 *
 * Migrated from: WPCode Snippet #1492 (Milestone section)
 */
class MilestoneColumns {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Column definition and rendering
        add_filter('manage_milestone_posts_columns', [self::class, 'defineColumns']);
        add_action('manage_milestone_posts_custom_column', [self::class, 'renderColumn'], 10, 2);

        // Filters
        add_action('restrict_manage_posts', [self::class, 'renderFilters']);
        add_action('pre_get_posts', [self::class, 'applyFilters']);

        // Post-query filter for calculated payment status
        add_filter('posts_results', [self::class, 'filterByPaymentStatus'], 10, 2);

        // Admin styles
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('MilestoneColumns', 'Registered milestone column hooks');
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
        $new_columns['title'] = 'Milestone';
        $new_columns['related_project'] = 'Project';
        $new_columns['milestone_order'] = 'Order';
        $new_columns['milestone_amount'] = 'Amount';
        $new_columns['hours_worked'] = 'Hours';
        $new_columns['work_status'] = 'Work Status';
        $new_columns['payment_status'] = 'Payment Status';
        $new_columns['invoice_count'] = 'Invoices';

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
                    echo '<span class="milestone-ref">' . esc_html($ref) . '</span>';
                } else {
                    echo '<span class="no-ref">—</span>';
                }
                break;

            case 'related_project':
                $project_id = get_post_meta($post_id, 'related_project', true);
                if ($project_id) {
                    $project_title = get_the_title($project_id);
                    $edit_url = get_edit_post_link($project_id);
                    echo '<a href="' . esc_url($edit_url) . '">' . esc_html($project_title) . '</a>';
                } else {
                    echo '—';
                }
                break;

            case 'milestone_order':
                echo esc_html(MilestoneService::getOrderDisplay($post_id) ?: '—');
                break;

            case 'milestone_amount':
                $amount = MilestoneService::getAmount($post_id);
                echo $amount > 0 ? '$' . number_format($amount, 2) : '—';
                break;

            case 'hours_worked':
                $hours = MilestoneService::getTotalHours($post_id);
                if ($hours > 0) {
                    echo number_format($hours, 2) . ' hrs';
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                break;

            case 'work_status':
                $status = MilestoneService::getWorkStatus($post_id);
                echo MilestoneService::getWorkStatusBadgeHtml($status);
                break;

            case 'payment_status':
                $status = MilestoneService::getPaymentStatus($post_id);
                echo MilestoneService::getPaymentStatusBadgeHtml($status, $post_id);
                break;

            case 'invoice_count':
                $count = MilestoneService::getInvoiceCount($post_id);
                if ($count > 0) {
                    $url = admin_url('edit.php?post_type=invoice&milestone_filter=' . $post_id);
                    echo '<a href="' . esc_url($url) . '">' . $count . '</a>';
                } else {
                    echo '0';
                }
                break;
        }
    }

    /**
     * Render filter dropdowns.
     *
     * @param string $post_type Current post type.
     */
    public static function renderFilters(string $post_type): void {
        if ($post_type !== 'milestone') {
            return;
        }

        // Project filter
        $projects = get_posts([
            'post_type' => 'project',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
        ]);

        $selected_project = isset($_GET['project_filter']) ? sanitize_text_field($_GET['project_filter']) : '';

        echo '<select name="project_filter">';
        echo '<option value="">All Projects</option>';
        foreach ($projects as $project) {
            $selected = selected($selected_project, (string) $project->ID, false);
            echo '<option value="' . esc_attr($project->ID) . '"' . $selected . '>' . esc_html($project->post_title) . '</option>';
        }
        echo '</select>';

        // Payment status filter (calculated status)
        $statuses = [
            MilestoneService::PAYMENT_PENDING,
            MilestoneService::PAYMENT_INVOICED,
            MilestoneService::PAYMENT_PAID,
        ];
        $selected_status = isset($_GET['milestone_status_filter']) ? sanitize_text_field($_GET['milestone_status_filter']) : '';

        echo '<select name="milestone_status_filter">';
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

        if ($query->get('post_type') !== 'milestone') {
            return;
        }

        $meta_query = $query->get('meta_query') ?: [];

        // Project filter
        if (!empty($_GET['project_filter'])) {
            $meta_query[] = [
                'key' => 'related_project',
                'value' => sanitize_text_field($_GET['project_filter']),
            ];
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
    }

    /**
     * Filter posts by calculated payment status.
     *
     * Payment status is calculated, not stored, so we need post-query filtering.
     *
     * @param array     $posts Array of post objects.
     * @param \WP_Query $query The query object.
     * @return array Filtered posts.
     */
    public static function filterByPaymentStatus(array $posts, \WP_Query $query): array {
        if (!is_admin() || !$query->is_main_query()) {
            return $posts;
        }

        if ($query->get('post_type') !== 'milestone') {
            return $posts;
        }

        if (empty($_GET['milestone_status_filter'])) {
            return $posts;
        }

        $filter_status = sanitize_text_field($_GET['milestone_status_filter']);

        return array_filter($posts, function ($post) use ($filter_status) {
            $status = MilestoneService::getPaymentStatus($post->ID);
            return $status === $filter_status;
        });
    }

    /**
     * Render column and badge styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'milestone') {
            return;
        }

        echo '<style>
            /* Milestone Reference */
            .milestone-ref {
                font-family: monospace;
                font-weight: 600;
                color: #467FF7;
            }
            .no-ref {
                color: #999;
            }

            /* Column widths */
            .column-reference { width: 110px; }
            .column-milestone_order { width: 70px; }
            .column-milestone_amount { width: 100px; }
            .column-hours_worked { width: 80px; }
            .column-work_status { width: 130px; }
            .column-payment_status { width: 130px; }
            .column-invoice_count { width: 80px; }
        </style>';
    }
}
