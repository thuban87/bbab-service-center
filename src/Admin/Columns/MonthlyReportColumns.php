<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Modules\Billing\MonthlyReportService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Custom admin columns for Monthly Reports.
 *
 * Displays:
 * - Reference number
 * - Report month
 * - Organization (client)
 * - Time entry count
 * - Total hours / Billable hours
 * - Free hours used
 * - Amount (overage)
 * - Related invoice status
 *
 * Phase 8.3 - New file
 */
class MonthlyReportColumns {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Column definitions
        add_filter('manage_monthly_report_posts_columns', [self::class, 'defineColumns']);
        add_action('manage_monthly_report_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
        add_filter('manage_edit-monthly_report_sortable_columns', [self::class, 'sortableColumns']);

        // Filters
        add_action('restrict_manage_posts', [self::class, 'renderFilters']);
        add_action('pre_get_posts', [self::class, 'applyFilters']);

        // Admin styles
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('MonthlyReportColumns', 'Registered monthly report column hooks');
    }

    /**
     * Define custom columns.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public static function defineColumns(array $columns): array {
        $new_columns = [];

        if (isset($columns['cb'])) {
            $new_columns['cb'] = $columns['cb'];
        }

        $new_columns['reference'] = 'Ref';
        $new_columns['report_month'] = 'Month';
        $new_columns['organization'] = 'Client';
        $new_columns['te_count'] = 'Entries';
        $new_columns['total_hours'] = 'Hours';
        $new_columns['billable_hours'] = 'Billable';
        $new_columns['free_hours'] = 'Free Hrs';
        $new_columns['amount'] = 'Amount';
        $new_columns['invoice_status'] = 'Invoice';

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
                    echo '<a href="' . esc_url($edit_url) . '" class="mr-ref-link">' . esc_html($ref) . '</a>';
                } else {
                    echo '<span class="no-ref">—</span>';
                }
                break;

            case 'report_month':
                $month = get_post_meta($post_id, 'report_month', true);
                echo $month ? esc_html($month) : '—';
                break;

            case 'organization':
                $org_id = get_post_meta($post_id, 'organization', true);
                if ($org_id) {
                    $org_name = get_the_title($org_id);
                    $filter_url = admin_url('edit.php?post_type=monthly_report&filter_org=' . $org_id);
                    echo '<a href="' . esc_url($filter_url) . '">' . esc_html($org_name) . '</a>';
                } else {
                    echo '—';
                }
                break;

            case 'te_count':
                $time_entries = MonthlyReportService::getTimeEntries($post_id);
                $count = count($time_entries);
                if ($count > 0) {
                    echo '<span style="font-weight: 500;">' . $count . '</span>';
                } else {
                    echo '<span class="no-value">0</span>';
                }
                break;

            case 'total_hours':
                $time_entries = MonthlyReportService::getTimeEntries($post_id);
                $total = 0.0;
                foreach ($time_entries as $te) {
                    $total += (float) get_post_meta($te->ID, 'hours', true);
                }
                echo $total > 0 ? number_format($total, 2) : '<span class="no-value">—</span>';
                break;

            case 'billable_hours':
                $time_entries = MonthlyReportService::getTimeEntries($post_id);
                $billable = 0.0;
                foreach ($time_entries as $te) {
                    $is_billable = get_post_meta($te->ID, 'billable', true);
                    if ($is_billable !== '0' && $is_billable !== 0 && $is_billable !== false) {
                        $billable += (float) get_post_meta($te->ID, 'hours', true);
                    }
                }
                if ($billable > 0) {
                    echo '<span style="color: #059669; font-weight: 500;">' . number_format($billable, 2) . '</span>';
                } else {
                    echo '<span class="no-value">—</span>';
                }
                break;

            case 'free_hours':
                $org_id = get_post_meta($post_id, 'organization', true);
                $free_limit = 0;
                if ($org_id) {
                    $free_limit = (float) get_post_meta($org_id, 'free_hours_limit', true);
                }
                echo $free_limit > 0 ? number_format($free_limit, 1) : '<span class="no-value">—</span>';
                break;

            case 'amount':
                // Calculate overage amount if applicable
                $org_id = get_post_meta($post_id, 'organization', true);
                if (!$org_id) {
                    echo '—';
                    break;
                }

                $free_limit = (float) get_post_meta($org_id, 'free_hours_limit', true);
                $hourly_rate = (float) get_post_meta($org_id, 'hourly_rate', true);

                $time_entries = MonthlyReportService::getTimeEntries($post_id);
                $billable = 0.0;
                foreach ($time_entries as $te) {
                    $is_billable = get_post_meta($te->ID, 'billable', true);
                    if ($is_billable !== '0' && $is_billable !== 0 && $is_billable !== false) {
                        $billable += (float) get_post_meta($te->ID, 'hours', true);
                    }
                }

                $overage = max(0, $billable - $free_limit);
                if ($overage > 0 && $hourly_rate > 0) {
                    $amount = $overage * $hourly_rate;
                    echo '<span style="color: #c62828; font-weight: 500;">$' . number_format($amount, 2) . '</span>';
                } else {
                    echo '<span class="no-value">$0.00</span>';
                }
                break;

            case 'invoice_status':
                // Check for related invoice
                $invoices = get_posts([
                    'post_type' => 'invoice',
                    'posts_per_page' => 1,
                    'post_status' => 'publish',
                    'meta_query' => [[
                        'key' => 'related_monthly_report',
                        'value' => $post_id,
                        'compare' => '=',
                    ]],
                ]);

                if (!empty($invoices)) {
                    $invoice = $invoices[0];
                    $status = get_post_meta($invoice->ID, 'invoice_status', true);
                    $invoice_num = get_post_meta($invoice->ID, 'invoice_number', true);
                    $edit_url = get_edit_post_link($invoice->ID);

                    $status_class = 'invoice-status-' . sanitize_html_class(strtolower($status));
                    echo '<a href="' . esc_url($edit_url) . '" class="mr-invoice-link ' . $status_class . '">';
                    echo esc_html($invoice_num ?: $status);
                    echo '</a>';
                } else {
                    echo '<span class="no-value">—</span>';
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
        $columns['report_month'] = 'report_month';
        $columns['reference'] = 'reference_number';
        return $columns;
    }

    /**
     * Render filter dropdowns.
     *
     * @param string $post_type Current post type.
     */
    public static function renderFilters(string $post_type): void {
        if ($post_type !== 'monthly_report') {
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
            echo '<option value="' . esc_attr($org->ID) . '"' . $selected . '>' . esc_html($org->post_title) . '</option>';
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

        if ($query->get('post_type') !== 'monthly_report') {
            return;
        }

        $meta_query = $query->get('meta_query') ?: [];

        // Organization filter
        if (!empty($_GET['filter_org'])) {
            $meta_query[] = [
                'key' => 'organization',
                'value' => sanitize_text_field($_GET['filter_org']),
            ];
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }

        // Handle sorting
        $orderby = $query->get('orderby');
        if ($orderby === 'report_month') {
            $query->set('meta_key', 'report_month');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'reference_number') {
            $query->set('meta_key', 'reference_number');
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * Render column styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'monthly_report') {
            return;
        }

        echo '<style>
            /* Monthly Report Columns */
            .column-reference { width: 90px; }
            .column-report_month { width: 110px; }
            .column-organization { width: 140px; }
            .column-te_count { width: 70px; text-align: center; }
            .column-total_hours { width: 70px; text-align: right; }
            .column-billable_hours { width: 80px; text-align: right; }
            .column-free_hours { width: 70px; text-align: center; }
            .column-amount { width: 90px; text-align: right; }
            .column-invoice_status { width: 100px; }

            /* Reference link */
            .mr-ref-link {
                font-family: monospace;
                font-weight: 600;
                color: #467FF7;
                text-decoration: none;
            }
            .mr-ref-link:hover {
                text-decoration: underline;
            }
            .no-ref, .no-value {
                color: #999;
            }

            /* Invoice status link */
            .mr-invoice-link {
                text-decoration: none;
                font-size: 12px;
                padding: 2px 6px;
                border-radius: 3px;
            }
            .mr-invoice-link:hover {
                text-decoration: underline;
            }
            .invoice-status-paid {
                background: #d5f5e3;
                color: #1e8449;
            }
            .invoice-status-pending {
                background: #fef3c7;
                color: #92400e;
            }
            .invoice-status-overdue {
                background: #fee2e2;
                color: #c62828;
            }
            .invoice-status-draft {
                background: #e5e7eb;
                color: #4b5563;
            }
        </style>';
    }
}
