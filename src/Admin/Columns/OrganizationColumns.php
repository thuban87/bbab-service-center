<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Custom admin columns for Client Organizations.
 *
 * Displays:
 * - Full Name (organization_name field)
 * - Shortcode (organization_shortcode field)
 * - Looker URL status
 * - Report count
 * - User count
 *
 * Migrated from: WPCode Snippet #1109 (CO section)
 */
class OrganizationColumns {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Column definitions
        add_filter('manage_client_organization_posts_columns', [self::class, 'defineColumns']);
        add_action('manage_client_organization_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
        add_filter('manage_edit-client_organization_sortable_columns', [self::class, 'sortableColumns']);

        // Handle sorting
        add_action('pre_get_posts', [self::class, 'handleSorting']);

        // Admin styles
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('OrganizationColumns', 'Registered organization column hooks');
    }

    /**
     * Define custom columns.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public static function defineColumns(array $columns): array {
        $new_columns = [];

        // Keep checkbox
        if (isset($columns['cb'])) {
            $new_columns['cb'] = $columns['cb'];
        }

        // Our custom columns - Phase 8.3 update
        $new_columns['org_name'] = 'Full Name';
        $new_columns['org_shortcode'] = 'Shortcode';
        $new_columns['contact_email'] = 'Email';
        $new_columns['contract_type'] = 'Contract';
        $new_columns['renewal_date'] = 'Renewal';
        $new_columns['free_hours'] = 'Free Hrs';
        $new_columns['hourly_rate'] = 'Rate';
        $new_columns['active_projects'] = 'Projects';
        $new_columns['open_srs'] = 'Open SRs';
        $new_columns['report_count'] = 'Reports';
        $new_columns['user_count'] = 'Users';

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
            case 'org_name':
                $name = get_post_meta($post_id, 'organization_name', true);
                $display = !empty($name) ? $name : get_the_title($post_id);
                $edit_link = get_edit_post_link($post_id);
                echo '<strong><a class="row-title" href="' . esc_url($edit_link) . '">' . esc_html($display) . '</a></strong>';
                break;

            case 'org_shortcode':
                $code = get_post_meta($post_id, 'organization_shortcode', true);
                echo $code ? '<code class="org-shortcode">' . esc_html($code) . '</code>' : '<span class="no-value">—</span>';
                break;

            case 'contact_email':
                $email = get_post_meta($post_id, 'contact_email', true);
                if (!empty($email)) {
                    echo '<a href="mailto:' . esc_attr($email) . '" class="org-email">' . esc_html($email) . '</a>';
                } else {
                    echo '<span class="no-value">—</span>';
                }
                break;

            case 'contract_type':
                $type = get_post_meta($post_id, 'contract_type', true);
                echo $type ? esc_html($type) : '<span class="no-value">—</span>';
                break;

            case 'renewal_date':
                $date = get_post_meta($post_id, 'contract_renewal_date', true);
                if (!empty($date) && strtotime($date) !== false) {
                    $is_soon = strtotime($date) <= strtotime('+30 days');
                    $style = $is_soon ? 'color: #d63638; font-weight: 500;' : '';
                    echo '<span style="' . $style . '">' . esc_html(date('M j, Y', strtotime($date))) . '</span>';
                } else {
                    echo '<span class="no-value">—</span>';
                }
                break;

            case 'free_hours':
                $hours = get_post_meta($post_id, 'free_hours_limit', true);
                echo $hours ? esc_html($hours) : '<span class="no-value">—</span>';
                break;

            case 'hourly_rate':
                $rate = get_post_meta($post_id, 'hourly_rate', true);
                echo $rate ? '$' . number_format((float) $rate, 0) : '<span class="no-value">—</span>';
                break;

            case 'active_projects':
                $count = self::getActiveProjectCount($post_id);
                if ($count > 0) {
                    $url = admin_url('edit.php?post_type=project&org_filter=' . $post_id . '&status_filter=Active');
                    echo '<a href="' . esc_url($url) . '">' . $count . '</a>';
                } else {
                    echo '<span class="no-value">0</span>';
                }
                break;

            case 'open_srs':
                $count = self::getOpenSRCount($post_id);
                if ($count > 0) {
                    $url = admin_url('edit.php?post_type=service_request&filter_org=' . $post_id);
                    echo '<a href="' . esc_url($url) . '" style="color: #d63638; font-weight: 500;">' . $count . '</a>';
                } else {
                    echo '<span class="no-value">0</span>';
                }
                break;

            case 'report_count':
                $count = self::getReportCount($post_id);
                if ($count > 0) {
                    $url = admin_url('edit.php?post_type=monthly_report&filter_org=' . $post_id);
                    echo '<a href="' . esc_url($url) . '">' . $count . '</a>';
                } else {
                    echo '<span class="no-value">0</span>';
                }
                break;

            case 'user_count':
                echo esc_html((string) self::getUserCount($post_id));
                break;
        }
    }

    /**
     * Get count of monthly reports for an organization.
     *
     * @param int $org_id Organization ID.
     * @return int Report count.
     */
    private static function getReportCount(int $org_id): int {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = 'organization'
             AND pm.meta_value = %s
             AND p.post_type = 'monthly_report'
             AND p.post_status = 'publish'",
            $org_id
        ));

        return (int) $count;
    }

    /**
     * Get count of users assigned to an organization.
     *
     * @param int $org_id Organization ID.
     * @return int User count.
     */
    private static function getUserCount(int $org_id): int {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'organization' AND meta_value = %s",
            $org_id
        ));

        return (int) $count;
    }

    /**
     * Get count of active projects for an organization.
     *
     * @param int $org_id Organization ID.
     * @return int Active project count.
     */
    private static function getActiveProjectCount(int $org_id): int {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'project_status'
             WHERE pm.meta_key = 'organization'
             AND pm.meta_value = %s
             AND p.post_type = 'project'
             AND p.post_status = 'publish'
             AND pm2.meta_value = 'Active'",
            $org_id
        ));

        return (int) $count;
    }

    /**
     * Get count of open service requests for an organization.
     *
     * @param int $org_id Organization ID.
     * @return int Open SR count.
     */
    private static function getOpenSRCount(int $org_id): int {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'request_status'
             WHERE pm.meta_key = 'organization'
             AND pm.meta_value = %s
             AND p.post_type = 'service_request'
             AND p.post_status = 'publish'
             AND pm2.meta_value NOT IN ('Completed', 'Cancelled', 'Closed')",
            $org_id
        ));

        return (int) $count;
    }

    /**
     * Define sortable columns.
     *
     * @param array $columns Sortable columns.
     * @return array Modified sortable columns.
     */
    public static function sortableColumns(array $columns): array {
        $columns['org_name'] = 'org_name';
        $columns['org_shortcode'] = 'org_shortcode';
        return $columns;
    }

    /**
     * Handle custom column sorting.
     *
     * @param \WP_Query $query The query object.
     */
    public static function handleSorting(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== 'client_organization') {
            return;
        }

        $orderby = $query->get('orderby');

        if ($orderby === 'org_name') {
            $query->set('meta_key', 'organization_name');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'org_shortcode') {
            $query->set('meta_key', 'organization_shortcode');
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * Render column styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'client_organization') {
            return;
        }

        echo '<style>
            /* Organization columns - Phase 8.3 update */
            .column-org_name { width: 180px; }
            .column-org_shortcode { width: 80px; }
            .column-contact_email { width: 180px; }
            .column-contract_type { width: 90px; }
            .column-renewal_date { width: 90px; }
            .column-free_hours { width: 60px; text-align: center; }
            .column-hourly_rate { width: 60px; text-align: right; }
            .column-active_projects { width: 70px; text-align: center; }
            .column-open_srs { width: 70px; text-align: center; }
            .column-report_count { width: 70px; text-align: center; }
            .column-user_count { width: 60px; text-align: center; }

            /* Shortcode styling */
            .org-shortcode {
                background: #f0f6fc;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 12px;
            }

            /* Email styling */
            .org-email {
                color: #2271b1;
                text-decoration: none;
                font-size: 12px;
            }
            .org-email:hover {
                text-decoration: underline;
            }

            .no-value {
                color: #999;
            }
        </style>';
    }
}
