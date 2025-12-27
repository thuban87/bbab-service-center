<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Modules\Billing\InvoiceService;
use BBAB\ServiceCenter\Modules\Billing\LineItemService;
use BBAB\ServiceCenter\Modules\Billing\PDFService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Custom admin columns, filters, and row actions for Invoices.
 *
 * Handles:
 * - Custom column definitions and rendering
 * - Admin list filters (Org, Status, Type)
 * - Sortable columns
 * - Row actions (Finalize, Mark Paid, Cancel, etc.)
 * - Admin notices for actions
 *
 * Migrated from snippets: 1256, 1257
 */
class InvoiceColumns {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Column definition and rendering
        add_filter('manage_invoice_posts_columns', [self::class, 'defineColumns']);
        add_action('manage_invoice_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
        add_filter('manage_edit-invoice_sortable_columns', [self::class, 'sortableColumns']);

        // Filters
        add_action('restrict_manage_posts', [self::class, 'renderFilters']);
        add_action('pre_get_posts', [self::class, 'applyFilters']);

        // Row actions
        add_filter('post_row_actions', [self::class, 'addRowActions'], 10, 2);

        // Handle row action requests
        add_action('admin_post_bbab_finalize_invoice', [self::class, 'handleFinalize']);
        add_action('admin_post_bbab_mark_invoice_paid', [self::class, 'handleMarkPaid']);
        add_action('admin_post_bbab_mark_invoice_overdue', [self::class, 'handleMarkOverdue']);
        add_action('admin_post_bbab_cancel_invoice', [self::class, 'handleCancel']);
        add_action('admin_post_bbab_revert_invoice_draft', [self::class, 'handleRevertToDraft']);
        add_action('admin_post_bbab_record_partial_payment', [self::class, 'handleRecordPartial']);

        // Admin notices
        add_action('admin_notices', [self::class, 'showAdminNotices']);

        // Admin styles
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('InvoiceColumns', 'Registered invoice column hooks');
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
        $new_columns['invoice_number'] = 'Invoice #';
        $new_columns['invoice_date'] = 'Date';
        $new_columns['organization'] = 'Client';
        $new_columns['related_source'] = 'Source';
        $new_columns['invoice_type'] = 'Type';
        $new_columns['amount'] = 'Amount';
        $new_columns['amount_paid'] = 'Paid';
        $new_columns['balance'] = 'Balance';
        $new_columns['invoice_status'] = 'Status';
        $new_columns['due_date'] = 'Due Date';
        $new_columns['line_items'] = 'Items';
        $new_columns['pdf'] = 'PDF';

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
            case 'invoice_number':
                $number = InvoiceService::getNumber($post_id);
                if ($number) {
                    echo '<span class="invoice-number">' . esc_html($number) . '</span>';
                } else {
                    echo '<span class="no-number"><em>Pending</em></span>';
                }
                break;

            case 'invoice_date':
                $date = InvoiceService::getDate($post_id);
                if (!empty($date) && strtotime($date) !== false) {
                    echo esc_html(date('M j, Y', strtotime($date)));
                } else {
                    echo '—';
                }
                break;

            case 'organization':
                $org = InvoiceService::getOrganization($post_id);
                if ($org) {
                    $filter_url = admin_url('edit.php?post_type=invoice&org_filter=' . $org->ID);
                    echo '<a href="' . esc_url($filter_url) . '">' . esc_html($org->post_title) . '</a>';
                } else {
                    echo '—';
                }
                break;

            case 'related_source':
                self::renderSourceColumn($post_id);
                break;

            case 'invoice_type':
                $type = InvoiceService::getType($post_id);
                echo esc_html($type);
                break;

            case 'amount':
                $amount = InvoiceService::getAmount($post_id);
                echo '$' . number_format($amount, 2);
                break;

            case 'amount_paid':
                $paid = InvoiceService::getPaidAmount($post_id);
                $status = InvoiceService::getStatus($post_id);

                // If paid, show full amount
                if ($status === InvoiceService::STATUS_PAID) {
                    $amount = InvoiceService::getAmount($post_id);
                    echo '<span style="color: #1e8449;">$' . number_format($amount, 2) . '</span>';
                } elseif ($paid > 0) {
                    echo '<span style="color: #1e8449;">$' . number_format($paid, 2) . '</span>';
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                break;

            case 'balance':
                $balance = InvoiceService::getBalance($post_id);
                if ($balance > 0) {
                    echo '<span style="color: #c62828; font-weight: 500;">$' . number_format($balance, 2) . '</span>';
                } else {
                    echo '<span style="color: #1e8449;">$0.00</span>';
                }
                break;

            case 'invoice_status':
                $status = InvoiceService::getStatus($post_id);
                // Check if actually overdue
                if (InvoiceService::isOverdue($post_id) && $status === InvoiceService::STATUS_PENDING) {
                    $status = InvoiceService::STATUS_OVERDUE;
                }
                echo InvoiceService::getStatusBadgeHtml($status);
                break;

            case 'due_date':
                $due_date = InvoiceService::getDueDate($post_id);
                if (!empty($due_date) && strtotime($due_date) !== false) {
                    $is_overdue = InvoiceService::isOverdue($post_id);
                    $style = $is_overdue ? 'color: #c62828; font-weight: 500;' : '';
                    echo '<span style="' . $style . '">' . esc_html(date('M j, Y', strtotime($due_date))) . '</span>';
                } else {
                    echo '—';
                }
                break;

            case 'line_items':
                $count = LineItemService::getCountForInvoice($post_id);
                if ($count > 0) {
                    $url = admin_url('edit.php?post_type=invoice_line_item&filter_invoice=' . $post_id);
                    echo '<a href="' . esc_url($url) . '">' . $count . '</a>';
                } else {
                    echo '<span style="color: #999;">0</span>';
                }
                break;

            case 'pdf':
                $pdf = InvoiceService::getPdf($post_id);
                if ($pdf && !empty($pdf['url'])) {
                    echo '<a href="' . esc_url($pdf['url']) . '" target="_blank" title="Download PDF">📄</a>';
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                break;
        }
    }

    /**
     * Render the source column (Monthly Report, Milestone, or Project).
     *
     * @param int $post_id Invoice post ID.
     */
    private static function renderSourceColumn(int $post_id): void {
        $related_report = get_post_meta($post_id, 'related_monthly_report', true);
        $related_project = get_post_meta($post_id, 'related_project', true);
        $related_milestone = get_post_meta($post_id, 'related_milestone', true);

        if (!empty($related_milestone)) {
            // Milestone: show ref + name
            $ms_name = get_post_meta($related_milestone, 'milestone_name', true);
            $ms_ref = get_post_meta($related_milestone, 'reference_number', true);
            $edit_link = get_edit_post_link((int) $related_milestone);
            $display = $ms_ref ? $ms_ref . ': ' . $ms_name : $ms_name;
            echo '<a href="' . esc_url($edit_link) . '" title="Milestone">' . esc_html($display) . '</a>';
        } elseif (!empty($related_project)) {
            // Project: show ref + name
            $proj_name = get_post_meta($related_project, 'project_name', true);
            $proj_ref = get_post_meta($related_project, 'reference_number', true);
            $edit_link = get_edit_post_link((int) $related_project);
            $display = $proj_ref ? $proj_ref . ': ' . $proj_name : $proj_name;
            echo '<a href="' . esc_url($edit_link) . '" title="Project">' . esc_html($display) . '</a>';
        } elseif (!empty($related_report)) {
            // Monthly Report: show org shortcode + report month
            $report_month = get_post_meta($related_report, 'report_month', true);
            $org_id = get_post_meta($related_report, 'organization', true);
            $org_shortcode = '';
            if ($org_id) {
                $org_shortcode = get_post_meta($org_id, 'organization_shortcode', true);
            }
            $edit_link = get_edit_post_link((int) $related_report);
            $display = $org_shortcode ? $org_shortcode . ': ' . $report_month : $report_month;
            echo '<a href="' . esc_url($edit_link) . '" title="Monthly Report">' . esc_html($display ?: 'Report') . '</a>';
        } else {
            echo '<span style="color: #999;">—</span>';
        }
    }

    /**
     * Define sortable columns.
     *
     * @param array $columns Sortable columns.
     * @return array Modified sortable columns.
     */
    public static function sortableColumns(array $columns): array {
        $columns['invoice_number'] = 'invoice_number';
        $columns['invoice_date'] = 'invoice_date';
        $columns['due_date'] = 'due_date';
        $columns['amount'] = 'amount';
        $columns['invoice_status'] = 'invoice_status';
        return $columns;
    }

    /**
     * Render filter dropdowns.
     *
     * @param string $post_type Current post type.
     */
    public static function renderFilters(string $post_type): void {
        if ($post_type !== 'invoice') {
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
            InvoiceService::STATUS_DRAFT,
            InvoiceService::STATUS_PENDING,
            InvoiceService::STATUS_PARTIAL,
            InvoiceService::STATUS_PAID,
            InvoiceService::STATUS_OVERDUE,
            InvoiceService::STATUS_VOID,
        ];
        $selected_status = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';

        echo '<select name="status_filter">';
        echo '<option value="">All Statuses</option>';
        foreach ($statuses as $status) {
            $selected = selected($selected_status, $status, false);
            echo '<option value="' . esc_attr($status) . '"' . $selected . '>' . esc_html($status) . '</option>';
        }
        echo '</select>';

        // Type filter
        $types = [
            InvoiceService::TYPE_STANDARD,
            InvoiceService::TYPE_MILESTONE,
            InvoiceService::TYPE_CLOSEOUT,
            InvoiceService::TYPE_DEPOSIT,
        ];
        $selected_type = isset($_GET['type_filter']) ? sanitize_text_field($_GET['type_filter']) : '';

        echo '<select name="type_filter">';
        echo '<option value="">All Types</option>';
        foreach ($types as $type) {
            $selected = selected($selected_type, $type, false);
            echo '<option value="' . esc_attr($type) . '"' . $selected . '>' . esc_html($type) . '</option>';
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

        if ($query->get('post_type') !== 'invoice') {
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
                'key' => 'invoice_status',
                'value' => sanitize_text_field($_GET['status_filter']),
            ];
        }

        // Type filter
        if (!empty($_GET['type_filter'])) {
            $meta_query[] = [
                'key' => 'invoice_type',
                'value' => sanitize_text_field($_GET['type_filter']),
            ];
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }

        // Handle sorting
        $orderby = $query->get('orderby');
        if ($orderby === 'invoice_number') {
            $query->set('meta_key', 'invoice_number');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'invoice_date') {
            $query->set('meta_key', 'invoice_date');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'due_date') {
            $query->set('meta_key', 'due_date');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'amount') {
            $query->set('meta_key', 'amount');
            $query->set('orderby', 'meta_value_num');
        } elseif ($orderby === 'invoice_status') {
            $query->set('meta_key', 'invoice_status');
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * Add row actions to invoices.
     *
     * @param array    $actions Existing actions.
     * @param \WP_Post $post    Post object.
     * @return array Modified actions.
     */
    public static function addRowActions(array $actions, \WP_Post $post): array {
        if ($post->post_type !== 'invoice') {
            return $actions;
        }

        $status = InvoiceService::getStatus($post->ID);

        // Finalize action (Draft -> Pending)
        if ($status === InvoiceService::STATUS_DRAFT) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=bbab_finalize_invoice&invoice_id=' . $post->ID),
                'bbab_finalize_invoice_' . $post->ID
            );
            $actions['finalize'] = '<a href="' . esc_url($url) . '" style="color: #2271b1; font-weight: 500;">Finalize</a>';
        }

        // Mark Paid action (Pending/Partial/Overdue -> Paid)
        if (in_array($status, [InvoiceService::STATUS_PENDING, InvoiceService::STATUS_PARTIAL, InvoiceService::STATUS_OVERDUE], true)) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=bbab_mark_invoice_paid&invoice_id=' . $post->ID),
                'bbab_mark_paid_' . $post->ID
            );
            $actions['mark_paid'] = '<a href="' . esc_url($url) . '" style="color: #00a32a;">Mark Paid</a>';
        }

        // Record Partial Payment (Pending only)
        if ($status === InvoiceService::STATUS_PENDING) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=bbab_record_partial_payment&invoice_id=' . $post->ID),
                'bbab_partial_payment_' . $post->ID
            );
            $actions['partial'] = '<a href="' . esc_url($url) . '">Record Payment</a>';
        }

        // Mark Overdue action (Pending -> Overdue, if past due)
        if ($status === InvoiceService::STATUS_PENDING) {
            $due_date = InvoiceService::getDueDate($post->ID);
            if ($due_date && strtotime($due_date) < strtotime('today')) {
                $url = wp_nonce_url(
                    admin_url('admin-post.php?action=bbab_mark_invoice_overdue&invoice_id=' . $post->ID),
                    'bbab_mark_overdue_' . $post->ID
                );
                $actions['mark_overdue'] = '<a href="' . esc_url($url) . '" style="color: #d63638;">Mark Overdue</a>';
            }
        }

        // Cancel action (any status except Paid/Void)
        if (!in_array($status, [InvoiceService::STATUS_PAID, InvoiceService::STATUS_VOID], true)) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=bbab_cancel_invoice&invoice_id=' . $post->ID),
                'bbab_cancel_invoice_' . $post->ID
            );
            $actions['cancel'] = '<a href="' . esc_url($url) . '" style="color: #a00;" onclick="return confirm(\'Are you sure you want to cancel this invoice?\');">Cancel</a>';
        }

        // Revert to Draft (Pending only)
        if ($status === InvoiceService::STATUS_PENDING) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=bbab_revert_invoice_draft&invoice_id=' . $post->ID),
                'bbab_revert_draft_' . $post->ID
            );
            $actions['revert_draft'] = '<a href="' . esc_url($url) . '" style="color: #666;">Revert to Draft</a>';
        }

        return $actions;
    }

    /**
     * Handle Finalize action.
     */
    public static function handleFinalize(): void {
        $invoice_id = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_finalize_invoice_' . $invoice_id)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('edit_post', $invoice_id)) {
            wp_die('Permission denied');
        }

        // Update status and set finalized date
        update_post_meta($invoice_id, 'invoice_status', InvoiceService::STATUS_PENDING);
        update_post_meta($invoice_id, 'finalized_date', current_time('Y-m-d'));

        Logger::debug('InvoiceColumns', 'Invoice finalized', ['invoice_id' => $invoice_id]);

        // Generate PDF
        $pdf_result = PDFService::generateInvoicePDF($invoice_id);

        if (is_wp_error($pdf_result)) {
            Logger::error('InvoiceColumns', 'PDF generation failed during finalize', [
                'invoice_id' => $invoice_id,
                'error' => $pdf_result->get_error_message(),
            ]);
            wp_redirect(admin_url('edit.php?post_type=invoice&finalized=1&pdf_error=1'));
            exit;
        }

        Logger::debug('InvoiceColumns', 'PDF generated during finalize', [
            'invoice_id' => $invoice_id,
            'path' => $pdf_result,
        ]);

        wp_redirect(admin_url('edit.php?post_type=invoice&finalized=1&pdf_generated=1'));
        exit;
    }

    /**
     * Handle Mark Paid action.
     */
    public static function handleMarkPaid(): void {
        $invoice_id = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_mark_paid_' . $invoice_id)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('edit_post', $invoice_id)) {
            wp_die('Permission denied');
        }

        $amount = InvoiceService::getAmount($invoice_id);

        update_post_meta($invoice_id, 'invoice_status', InvoiceService::STATUS_PAID);
        update_post_meta($invoice_id, 'amount_paid', $amount);
        update_post_meta($invoice_id, 'payment_date', current_time('Y-m-d'));

        Logger::debug('InvoiceColumns', 'Invoice marked paid', ['invoice_id' => $invoice_id, 'amount' => $amount]);

        wp_redirect(admin_url('edit.php?post_type=invoice&marked_paid=1'));
        exit;
    }

    /**
     * Handle Mark Overdue action.
     */
    public static function handleMarkOverdue(): void {
        $invoice_id = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_mark_overdue_' . $invoice_id)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('edit_post', $invoice_id)) {
            wp_die('Permission denied');
        }

        update_post_meta($invoice_id, 'invoice_status', InvoiceService::STATUS_OVERDUE);

        Logger::debug('InvoiceColumns', 'Invoice marked overdue', ['invoice_id' => $invoice_id]);

        wp_redirect(admin_url('edit.php?post_type=invoice&marked_overdue=1'));
        exit;
    }

    /**
     * Handle Cancel action.
     */
    public static function handleCancel(): void {
        $invoice_id = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_cancel_invoice_' . $invoice_id)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('edit_post', $invoice_id)) {
            wp_die('Permission denied');
        }

        update_post_meta($invoice_id, 'invoice_status', InvoiceService::STATUS_VOID);

        Logger::debug('InvoiceColumns', 'Invoice cancelled', ['invoice_id' => $invoice_id]);

        wp_redirect(admin_url('edit.php?post_type=invoice&cancelled=1'));
        exit;
    }

    /**
     * Handle Revert to Draft action.
     */
    public static function handleRevertToDraft(): void {
        $invoice_id = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_revert_draft_' . $invoice_id)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('edit_post', $invoice_id)) {
            wp_die('Permission denied');
        }

        update_post_meta($invoice_id, 'invoice_status', InvoiceService::STATUS_DRAFT);
        update_post_meta($invoice_id, 'finalized_date', '');

        Logger::debug('InvoiceColumns', 'Invoice reverted to draft', ['invoice_id' => $invoice_id]);

        wp_redirect(admin_url('edit.php?post_type=invoice&reverted=1'));
        exit;
    }

    /**
     * Handle Record Partial Payment - redirects to edit screen.
     */
    public static function handleRecordPartial(): void {
        $invoice_id = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_partial_payment_' . $invoice_id)) {
            wp_die('Security check failed');
        }

        // Redirect to edit screen where user can enter partial amount
        wp_redirect(admin_url('post.php?post=' . $invoice_id . '&action=edit&partial_payment=1'));
        exit;
    }

    /**
     * Show admin notices for row actions.
     */
    public static function showAdminNotices(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'invoice') {
            return;
        }

        if (isset($_GET['finalized'])) {
            $pdf_msg = '';
            if (isset($_GET['pdf_generated'])) {
                $pdf_msg = ' PDF generated successfully.';
            } elseif (isset($_GET['pdf_error'])) {
                $pdf_msg = ' <span style="color: #d63638;">PDF generation failed - check logs.</span>';
            }
            echo '<div class="notice notice-success is-dismissible"><p><strong>Invoice finalized!</strong> It is now visible to the client.' . $pdf_msg . '</p></div>';
        }
        if (isset($_GET['marked_paid'])) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Invoice marked as paid.</strong></p></div>';
        }
        if (isset($_GET['marked_overdue'])) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>Invoice marked as overdue.</strong></p></div>';
        }
        if (isset($_GET['cancelled'])) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>Invoice cancelled.</strong></p></div>';
        }
        if (isset($_GET['reverted'])) {
            echo '<div class="notice notice-info is-dismissible"><p><strong>Invoice reverted to draft.</strong></p></div>';
        }
        if (isset($_GET['partial_payment'])) {
            echo '<div class="notice notice-info is-dismissible"><p>Update the "Amount Paid" field below and set status to "Partial", then save.</p></div>';
        }
    }

    /**
     * Render column and badge styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'invoice') {
            return;
        }

        echo '<style>
            /* Invoice Number */
            .invoice-number {
                font-family: monospace;
                font-weight: 600;
                color: #467FF7;
            }
            .no-number {
                color: #999;
            }

            /* Column widths */
            .column-invoice_number { width: 120px; }
            .column-invoice_date { width: 90px; }
            .column-organization { width: 130px; }
            .column-related_source { width: 150px; }
            .column-invoice_type { width: 80px; }
            .column-amount { width: 85px; text-align: right; }
            .column-amount_paid { width: 85px; text-align: right; }
            .column-balance { width: 85px; text-align: right; }
            .column-invoice_status { width: 90px; }
            .column-due_date { width: 90px; }
            .column-line_items { width: 50px; text-align: center; }
            .column-pdf { width: 40px; text-align: center; }

            /* Right-align monetary columns */
            .column-amount,
            .column-amount_paid,
            .column-balance {
                text-align: right !important;
            }

            /* Line items center */
            .column-line_items {
                text-align: center !important;
            }
        </style>';
    }
}
