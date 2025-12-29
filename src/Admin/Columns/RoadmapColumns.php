<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Utils\Logger;
use BBAB\ServiceCenter\Utils\Pods;

/**
 * Custom admin columns and filters for Roadmap Items.
 *
 * Handles:
 * - Custom column definitions (Client, Status, Submitted By, Priority, ADR)
 * - Color-coded status and priority badges
 * - Admin list filters (Org, Status, Submitter type)
 * - Sortable columns
 *
 * Migrated from: WPCode Snippets #1922, #1923
 */
class RoadmapColumns {

    /**
     * Status badge colors.
     */
    private const STATUS_COLORS = [
        'Idea' => ['bg' => '#e0e7ff', 'text' => '#3730a3'],
        'ADR In Progress' => ['bg' => '#fef3c7', 'text' => '#92400e'],
        'Proposed' => ['bg' => '#dbeafe', 'text' => '#1e40af'],
        'Approved' => ['bg' => '#d1fae5', 'text' => '#065f46'],
        'Declined' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
    ];

    /**
     * Priority badge colors.
     */
    private const PRIORITY_COLORS = [
        'Low' => ['bg' => '#f3f4f6', 'text' => '#6b7280'],
        'Medium' => ['bg' => '#fef3c7', 'text' => '#92400e'],
        'High' => ['bg' => '#fee2e2', 'text' => '#dc2626'],
    ];

    /**
     * Fallback statuses (used if Pods unavailable).
     */
    private const FALLBACK_STATUSES = ['Idea', 'ADR In Progress', 'Proposed', 'Approved', 'Declined'];

    /**
     * Get all available roadmap statuses.
     *
     * Fetches from Pods field configuration, falls back to hardcoded list.
     *
     * @return array Status values.
     */
    public static function getStatuses(): array {
        return Pods::getFieldOptions('roadmap_item', 'roadmap_status', self::FALLBACK_STATUSES);
    }

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Column definition and rendering
        add_filter('manage_roadmap_item_posts_columns', [self::class, 'defineColumns']);
        add_action('manage_roadmap_item_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
        add_filter('manage_edit-roadmap_item_sortable_columns', [self::class, 'sortableColumns']);

        // Filters
        add_action('restrict_manage_posts', [self::class, 'renderFilters']);
        add_action('pre_get_posts', [self::class, 'applyFilters']);

        // Admin styles
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('RoadmapColumns', 'Registered roadmap column hooks');
    }

    /**
     * Define custom columns.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public static function defineColumns(array $columns): array {
        $new_columns = [];

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            if ($key === 'title') {
                $new_columns['roadmap_client'] = 'Client';
                $new_columns['roadmap_status'] = 'Status';
                $new_columns['roadmap_submitted_by'] = 'Submitted By';
                $new_columns['roadmap_priority'] = 'Priority';
                $new_columns['roadmap_adr'] = 'ADR';
            }
        }

        return $new_columns;
    }

    /**
     * Render column content.
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public static function renderColumn(string $column, int $post_id): void {
        $pod = function_exists('pods') ? pods('roadmap_item', $post_id) : null;

        switch ($column) {
            case 'roadmap_client':
                $org_id = null;
                $org_title = null;

                // Try Pods first
                if ($pod) {
                    $org = $pod->field('organization');
                    if ($org) {
                        // Pods returns array for relationships
                        if (is_array($org) && !empty($org['ID'])) {
                            $org_id = $org['ID'];
                            $org_title = $org['post_title'] ?? get_the_title($org_id);
                        } elseif (is_numeric($org)) {
                            // Might return just ID
                            $org_id = (int) $org;
                            $org_title = get_the_title($org_id);
                        }
                    }
                }

                // Fallback to raw meta if Pods didn't return anything
                if (!$org_id) {
                    $org_id = get_post_meta($post_id, 'organization', true);
                    if ($org_id) {
                        $org_title = get_the_title($org_id);
                    }
                }

                if ($org_id && $org_title) {
                    $filter_url = admin_url('edit.php?post_type=roadmap_item&filter_roadmap_org=' . $org_id);
                    echo '<a href="' . esc_url($filter_url) . '">' . esc_html($org_title) . '</a>';
                } else {
                    echo '—';
                }
                break;

            case 'roadmap_status':
                $status = $pod ? $pod->field('roadmap_status') : get_post_meta($post_id, 'roadmap_status', true);
                echo self::getStatusBadgeHtml($status ?: '');
                break;

            case 'roadmap_submitted_by':
                if ($pod) {
                    $user = $pod->field('submitted_by');
                    if ($user && !empty($user['ID'])) {
                        $user_obj = get_userdata($user['ID']);
                        echo $user_obj ? esc_html($user_obj->display_name) : 'Unknown';
                    } else {
                        echo '—';
                    }
                } else {
                    $user_id = get_post_meta($post_id, 'submitted_by', true);
                    if ($user_id) {
                        $user_obj = get_userdata($user_id);
                        echo $user_obj ? esc_html($user_obj->display_name) : '—';
                    } else {
                        echo '—';
                    }
                }
                break;

            case 'roadmap_priority':
                $priority = $pod ? $pod->field('priority') : get_post_meta($post_id, 'priority', true);
                echo self::getPriorityBadgeHtml($priority ?: '');
                break;

            case 'roadmap_adr':
                if ($pod) {
                    $adr = $pod->field('adr_pdf');
                    if ($adr && !empty($adr['guid'])) {
                        echo '<a href="' . esc_url($adr['guid']) . '" target="_blank" class="adr-link">View</a>';
                    } else {
                        echo '—';
                    }
                } else {
                    $adr_id = get_post_meta($post_id, 'adr_pdf', true);
                    if ($adr_id) {
                        $adr_url = wp_get_attachment_url($adr_id);
                        echo $adr_url ? '<a href="' . esc_url($adr_url) . '" target="_blank" class="adr-link">View</a>' : '—';
                    } else {
                        echo '—';
                    }
                }
                break;
        }
    }

    /**
     * Get status badge HTML.
     *
     * @param string $status Status value.
     * @return string HTML badge.
     */
    public static function getStatusBadgeHtml(string $status): string {
        if (empty($status)) {
            return '—';
        }

        $class = sanitize_title($status);
        return '<span class="roadmap-badge status-' . esc_attr($class) . '">' . esc_html($status) . '</span>';
    }

    /**
     * Get priority badge HTML.
     *
     * @param string $priority Priority value.
     * @return string HTML badge.
     */
    public static function getPriorityBadgeHtml(string $priority): string {
        if (empty($priority)) {
            return '—';
        }

        $class = sanitize_title($priority);
        return '<span class="priority-badge priority-' . esc_attr($class) . '">' . esc_html($priority) . '</span>';
    }

    /**
     * Define sortable columns.
     *
     * @param array $columns Sortable columns.
     * @return array Modified sortable columns.
     */
    public static function sortableColumns(array $columns): array {
        $columns['roadmap_client'] = 'roadmap_client';
        $columns['roadmap_status'] = 'roadmap_status';
        $columns['roadmap_priority'] = 'roadmap_priority';
        return $columns;
    }

    /**
     * Render filter dropdowns.
     *
     * @param string $post_type Current post type.
     */
    public static function renderFilters(string $post_type): void {
        if ($post_type !== 'roadmap_item') {
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

        $selected_org = isset($_GET['filter_roadmap_org']) ? sanitize_text_field($_GET['filter_roadmap_org']) : '';

        echo '<select name="filter_roadmap_org">';
        echo '<option value="">All Clients</option>';
        foreach ($orgs as $org) {
            $selected = selected($selected_org, (string) $org->ID, false);
            echo '<option value="' . esc_attr($org->ID) . '"' . $selected . '>' . esc_html($org->post_title) . '</option>';
        }
        echo '</select>';

        // Status filter (pulls options from Pods field config)
        $selected_status = isset($_GET['filter_roadmap_status']) ? sanitize_text_field($_GET['filter_roadmap_status']) : '';
        $statuses = self::getStatuses();

        echo '<select name="filter_roadmap_status">';
        echo '<option value="">All Statuses</option>';
        foreach ($statuses as $status) {
            $selected = selected($selected_status, $status, false);
            echo '<option value="' . esc_attr($status) . '"' . $selected . '>' . esc_html($status) . '</option>';
        }
        echo '</select>';

        // Submitted By filter (Brad vs Clients)
        $selected_submitter = isset($_GET['filter_roadmap_submitter']) ? sanitize_text_field($_GET['filter_roadmap_submitter']) : '';

        echo '<select name="filter_roadmap_submitter">';
        echo '<option value="">All Submitters</option>';
        echo '<option value="brad"' . selected($selected_submitter, 'brad', false) . '>Brad</option>';
        echo '<option value="client"' . selected($selected_submitter, 'client', false) . '>Clients</option>';
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

        if ($query->get('post_type') !== 'roadmap_item') {
            return;
        }

        $meta_query = $query->get('meta_query') ?: [];

        // Organization filter (fixed: use 'organization' not 'client_organization')
        if (!empty($_GET['filter_roadmap_org'])) {
            $meta_query[] = [
                'key' => 'organization',
                'value' => sanitize_text_field($_GET['filter_roadmap_org']),
                'compare' => '=',
            ];
        }

        // Status filter
        if (!empty($_GET['filter_roadmap_status'])) {
            $meta_query[] = [
                'key' => 'roadmap_status',
                'value' => sanitize_text_field($_GET['filter_roadmap_status']),
                'compare' => '=',
            ];
        }

        // Submitted By filter
        if (!empty($_GET['filter_roadmap_submitter'])) {
            $submitter = sanitize_text_field($_GET['filter_roadmap_submitter']);

            // Get all admin user IDs
            $admins = get_users(['role' => 'administrator', 'fields' => 'ID']);

            if ($submitter === 'brad') {
                $meta_query[] = [
                    'key' => 'submitted_by',
                    'value' => $admins,
                    'compare' => 'IN',
                ];
            } elseif ($submitter === 'client') {
                $meta_query[] = [
                    'key' => 'submitted_by',
                    'value' => $admins,
                    'compare' => 'NOT IN',
                ];
            }
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
    }

    /**
     * Render column and badge styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'roadmap_item') {
            return;
        }

        echo '<style>
            /* Status badges */
            .roadmap-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .status-idea { background: #e0e7ff; color: #3730a3; }
            .status-adr-in-progress { background: #fef3c7; color: #92400e; }
            .status-proposed { background: #dbeafe; color: #1e40af; }
            .status-approved { background: #d1fae5; color: #065f46; }
            .status-declined { background: #fee2e2; color: #991b1b; }

            /* Priority badges */
            .priority-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
            }
            .priority-low { background: #f3f4f6; color: #6b7280; }
            .priority-medium { background: #fef3c7; color: #92400e; }
            .priority-high { background: #fee2e2; color: #dc2626; }

            /* ADR link */
            .adr-link {
                text-decoration: none;
                color: #467FF7;
            }
            .adr-link:hover {
                text-decoration: underline;
            }

            /* Column widths */
            .column-roadmap_client { width: 150px; }
            .column-roadmap_status { width: 130px; }
            .column-roadmap_submitted_by { width: 150px; }
            .column-roadmap_priority { width: 90px; }
            .column-roadmap_adr { width: 70px; }
        </style>';
    }
}
