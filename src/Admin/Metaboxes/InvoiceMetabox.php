<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Metaboxes;

use BBAB\ServiceCenter\Modules\Billing\InvoiceService;
use BBAB\ServiceCenter\Modules\Billing\LineItemService;
use BBAB\ServiceCenter\Modules\Billing\PDFService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Invoice editor metaboxes.
 *
 * Displays on invoice edit screens:
 * - Invoice Summary (sidebar) - status, amounts, dates
 * - Line Items (main area) - list of invoice line items
 * - Related Items (sidebar) - links to milestone, project, report
 *
 * Foundation for Phase 5.3 - full metabox migration in Phase 5.4
 * (snippets 2052, 2053, 2749)
 */
class InvoiceMetabox {

    /**
     * Register hooks.
     */
    public static function register(): void {
        add_action('add_meta_boxes', [self::class, 'registerMetaboxes']);
        add_action('admin_head', [self::class, 'renderStyles']);
        add_action('wp_ajax_bbab_generate_invoice_pdf', [self::class, 'handleGeneratePDF']);

        Logger::debug('InvoiceMetabox', 'Registered invoice metabox hooks');
    }

    /**
     * Register the metaboxes.
     */
    public static function registerMetaboxes(): void {
        // Invoice Summary (sidebar)
        add_meta_box(
            'bbab_invoice_summary',
            'Invoice Summary',
            [self::class, 'renderSummaryMetabox'],
            'invoice',
            'side',
            'high'
        );

        // Line Items (main content area)
        add_meta_box(
            'bbab_invoice_line_items',
            'Line Items',
            [self::class, 'renderLineItemsMetabox'],
            'invoice',
            'normal',
            'high'
        );

        // Related Items (sidebar)
        add_meta_box(
            'bbab_invoice_related',
            'Related Items',
            [self::class, 'renderRelatedMetabox'],
            'invoice',
            'side',
            'default'
        );
    }

    /**
     * Render the invoice summary metabox.
     *
     * @param \WP_Post $post The post object.
     */
    public static function renderSummaryMetabox(\WP_Post $post): void {
        $invoice_id = $post->ID;

        $number = InvoiceService::getNumber($invoice_id);
        $status = InvoiceService::getStatus($invoice_id);
        $type = InvoiceService::getType($invoice_id);
        $amount = InvoiceService::getAmount($invoice_id);
        $paid = InvoiceService::getPaidAmount($invoice_id);
        $balance = InvoiceService::getBalance($invoice_id);
        $invoice_date = InvoiceService::getDate($invoice_id);
        $due_date = InvoiceService::getDueDate($invoice_id);
        $is_overdue = InvoiceService::isOverdue($invoice_id);

        // Check if actually overdue
        if ($is_overdue && $status === InvoiceService::STATUS_PENDING) {
            $status = InvoiceService::STATUS_OVERDUE;
        }

        echo '<div class="invoice-summary-grid">';

        // Invoice Number
        if ($number) {
            echo '<div class="summary-row">';
            echo '<span class="summary-label">Invoice #</span>';
            echo '<span class="summary-value invoice-number">' . esc_html($number) . '</span>';
            echo '</div>';
        }

        // Status
        echo '<div class="summary-row">';
        echo '<span class="summary-label">Status</span>';
        echo '<span class="summary-value">' . InvoiceService::getStatusBadgeHtml($status) . '</span>';
        echo '</div>';

        // Type
        echo '<div class="summary-row">';
        echo '<span class="summary-label">Type</span>';
        echo '<span class="summary-value">' . esc_html($type) . '</span>';
        echo '</div>';

        // Dates
        if ($invoice_date) {
            echo '<div class="summary-row">';
            echo '<span class="summary-label">Invoice Date</span>';
            echo '<span class="summary-value">' . esc_html(date('M j, Y', strtotime($invoice_date))) . '</span>';
            echo '</div>';
        }

        if ($due_date) {
            $due_style = $is_overdue ? 'color: #c62828; font-weight: 500;' : '';
            echo '<div class="summary-row">';
            echo '<span class="summary-label">Due Date</span>';
            echo '<span class="summary-value" style="' . $due_style . '">' . esc_html(date('M j, Y', strtotime($due_date))) . '</span>';
            echo '</div>';
        }

        echo '</div>'; // .invoice-summary-grid

        // Financial Summary
        echo '<div class="invoice-financial">';
        echo '<div class="financial-row">';
        echo '<span class="financial-label">Amount:</span>';
        echo '<span class="financial-value">$' . number_format($amount, 2) . '</span>';
        echo '</div>';

        echo '<div class="financial-row">';
        echo '<span class="financial-label">Paid:</span>';
        echo '<span class="financial-value" style="color: #1e8449;">$' . number_format($paid, 2) . '</span>';
        echo '</div>';

        $balance_style = $balance > 0 ? 'color: #c62828; font-weight: 600;' : 'color: #1e8449;';
        echo '<div class="financial-row balance-row">';
        echo '<span class="financial-label">Balance:</span>';
        echo '<span class="financial-value" style="' . $balance_style . '">$' . number_format($balance, 2) . '</span>';
        echo '</div>';
        echo '</div>';

        // Finalize Button (only for Draft invoices)
        if ($status === InvoiceService::STATUS_DRAFT) {
            $finalize_url = wp_nonce_url(
                admin_url('admin-post.php?action=bbab_finalize_invoice&invoice_id=' . $invoice_id),
                'bbab_finalize_invoice_' . $invoice_id
            );
            echo '<div class="invoice-finalize-action" style="margin-bottom: 12px; text-align: center;">';
            echo '<a href="' . esc_url($finalize_url) . '" class="button button-primary" style="width: 100%;">Finalize Invoice</a>';
            echo '<p style="margin: 6px 0 0 0; font-size: 11px; color: #666;">Makes invoice visible to client & generates PDF</p>';
            echo '</div>';
        }

        // PDF Link / Generate Button
        $pdf = InvoiceService::getPdf($invoice_id);
        echo '<div class="invoice-pdf-actions">';

        if ($pdf && !empty($pdf['url'])) {
            echo '<a href="' . esc_url($pdf['url']) . '" target="_blank" class="button">📄 View PDF</a> ';
            echo '<button type="button" class="button bbab-regenerate-pdf" data-invoice-id="' . esc_attr($invoice_id) . '">🔄 Regenerate</button>';
        } else {
            echo '<button type="button" class="button button-primary bbab-generate-pdf" data-invoice-id="' . esc_attr($invoice_id) . '">📄 Generate PDF</button>';
        }

        echo '<div class="pdf-status" style="margin-top: 8px; font-size: 12px;"></div>';
        echo '</div>';
        echo self::getPDFScript($invoice_id);
    }

    /**
     * Get JavaScript for PDF generation button.
     *
     * @param int $invoice_id Invoice post ID.
     * @return string JavaScript code.
     */
    private static function getPDFScript(int $invoice_id): string {
        $nonce = wp_create_nonce('bbab_generate_pdf_' . $invoice_id);

        return "
        <script>
        jQuery(document).ready(function($) {
            $('.bbab-generate-pdf, .bbab-regenerate-pdf').on('click', function() {
                var btn = $(this);
                var invoiceId = btn.data('invoice-id');
                var statusDiv = btn.parent().find('.pdf-status');
                var originalText = btn.text();

                btn.prop('disabled', true).text('Generating...');
                statusDiv.text('').removeClass('success error');

                $.post(ajaxurl, {
                    action: 'bbab_generate_invoice_pdf',
                    invoice_id: invoiceId,
                    nonce: '" . esc_js($nonce) . "'
                }, function(response) {
                    if (response.success) {
                        statusDiv.text('PDF generated successfully!').addClass('success');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        btn.prop('disabled', false).text(originalText);
                        statusDiv.text(response.data.message || 'Error generating PDF').addClass('error');
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text(originalText);
                    statusDiv.text('Connection error. Please try again.').addClass('error');
                });
            });
        });
        </script>";
    }

    /**
     * Handle AJAX request to generate invoice PDF.
     */
    public static function handleGeneratePDF(): void {
        $invoice_id = isset($_POST['invoice_id']) ? absint($_POST['invoice_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

        // Verify nonce
        if (!wp_verify_nonce($nonce, 'bbab_generate_pdf_' . $invoice_id)) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        // Verify user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }

        // Verify invoice exists
        if (!$invoice_id || get_post_type($invoice_id) !== 'invoice') {
            wp_send_json_error(['message' => 'Invalid invoice.']);
            return;
        }

        // Generate PDF
        $result = PDFService::generateInvoicePDF($invoice_id);

        if (is_wp_error($result)) {
            Logger::error('InvoiceMetabox', 'PDF generation failed', [
                'invoice_id' => $invoice_id,
                'error' => $result->get_error_message(),
            ]);
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        Logger::debug('InvoiceMetabox', 'PDF generated via admin action', [
            'invoice_id' => $invoice_id,
            'path' => $result,
        ]);

        wp_send_json_success([
            'message' => 'PDF generated successfully.',
            'path' => $result,
        ]);
    }

    /**
     * Render the line items metabox.
     *
     * @param \WP_Post $post The post object.
     */
    public static function renderLineItemsMetabox(\WP_Post $post): void {
        $invoice_id = $post->ID;
        $line_items = LineItemService::getForInvoice($invoice_id);

        if (empty($line_items)) {
            echo '<p style="color: #666; font-style: italic;">No line items yet.</p>';
            echo '<p><a href="' . admin_url('post-new.php?post_type=invoice_line_item&invoice_id=' . $invoice_id) . '" class="button">+ Add Line Item</a></p>';
            return;
        }

        echo '<div class="invoice-line-items-wrapper">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th class="column-type">Type</th>';
        echo '<th class="column-description">Description</th>';
        echo '<th class="column-qty">Qty</th>';
        echo '<th class="column-rate">Rate</th>';
        echo '<th class="column-amount">Amount</th>';
        echo '<th class="column-actions">Actions</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        $total = 0;

        foreach ($line_items as $item) {
            $item_id = $item->ID;
            $line_type = get_post_meta($item_id, 'line_type', true);
            $description = get_post_meta($item_id, 'description', true);
            $quantity = get_post_meta($item_id, 'quantity', true);
            $rate = get_post_meta($item_id, 'rate', true);
            $amount = (float) get_post_meta($item_id, 'amount', true);

            $total += $amount;

            // Determine row styling for credits/negative amounts
            $row_class = $amount < 0 ? 'credit-row' : '';

            echo '<tr class="' . esc_attr($row_class) . '">';
            echo '<td class="column-type">' . esc_html($line_type ?: '—') . '</td>';
            echo '<td class="column-description">' . esc_html($description ?: '—') . '</td>';
            echo '<td class="column-qty">' . ($quantity ? esc_html($quantity) : '—') . '</td>';
            echo '<td class="column-rate">';
            if ($rate !== '' && $rate !== null) {
                echo ($rate < 0 ? '-' : '') . '$' . number_format(abs((float) $rate), 2);
            } else {
                echo '—';
            }
            echo '</td>';
            echo '<td class="column-amount">';
            if ($amount < 0) {
                echo '<span class="credit-amount">-$' . number_format(abs($amount), 2) . '</span>';
            } else {
                echo '$' . number_format($amount, 2);
            }
            echo '</td>';
            echo '<td class="column-actions">';
            echo '<a href="' . esc_url(get_edit_post_link($item_id)) . '" class="button button-small">Edit</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '<tfoot>';
        echo '<tr class="total-row">';
        echo '<td colspan="4" style="text-align: right; font-weight: 600;">Total:</td>';
        echo '<td class="column-amount" style="font-weight: 600;">$' . number_format($total, 2) . '</td>';
        echo '<td></td>';
        echo '</tr>';
        echo '</tfoot>';
        echo '</table>';
        echo '</div>';

        echo '<p style="margin-top: 12px;">';
        echo '<a href="' . admin_url('post-new.php?post_type=invoice_line_item&invoice_id=' . $invoice_id) . '" class="button">+ Add Line Item</a>';
        echo '</p>';
    }

    /**
     * Render the related items metabox.
     *
     * @param \WP_Post $post The post object.
     */
    public static function renderRelatedMetabox(\WP_Post $post): void {
        $invoice_id = $post->ID;

        // Organization
        $org = InvoiceService::getOrganization($invoice_id);
        if ($org) {
            echo '<div class="related-row">';
            echo '<span class="related-label">Client:</span>';
            echo '<a href="' . esc_url(get_edit_post_link($org->ID)) . '">' . esc_html($org->post_title) . '</a>';
            echo '</div>';
        }

        // Related Milestone
        $milestone_id = get_post_meta($invoice_id, 'related_milestone', true);
        if ($milestone_id) {
            $milestone = get_post($milestone_id);
            if ($milestone) {
                echo '<div class="related-row">';
                echo '<span class="related-label">Milestone:</span>';
                echo '<a href="' . esc_url(get_edit_post_link($milestone->ID)) . '">' . esc_html($milestone->post_title) . '</a>';
                echo '</div>';
            }
        }

        // Related Project
        $project_id = get_post_meta($invoice_id, 'related_project', true);
        if ($project_id) {
            $project = get_post($project_id);
            if ($project) {
                echo '<div class="related-row">';
                echo '<span class="related-label">Project:</span>';
                echo '<a href="' . esc_url(get_edit_post_link($project->ID)) . '">' . esc_html($project->post_title) . '</a>';
                echo '</div>';
            }
        }

        // Related Monthly Report
        $report_id = get_post_meta($invoice_id, 'related_monthly_report', true);
        if ($report_id) {
            $report = get_post($report_id);
            if ($report) {
                echo '<div class="related-row">';
                echo '<span class="related-label">Monthly Report:</span>';
                echo '<a href="' . esc_url(get_edit_post_link($report->ID)) . '">' . esc_html($report->post_title) . '</a>';
                echo '</div>';
            }
        }

        // No relations found
        if (!$org && !$milestone_id && !$project_id && !$report_id) {
            echo '<p style="color: #666; font-style: italic; margin: 0;">No related items.</p>';
        }
    }

    /**
     * Render metabox styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'invoice') {
            return;
        }

        echo '<style>
            /* Invoice Summary */
            .invoice-summary-grid {
                margin-bottom: 16px;
            }
            .summary-row {
                display: flex;
                justify-content: space-between;
                padding: 6px 0;
                border-bottom: 1px solid #eee;
            }
            .summary-row:last-child {
                border-bottom: none;
            }
            .summary-label {
                color: #666;
                font-size: 12px;
            }
            .summary-value {
                font-weight: 500;
            }
            .summary-value.invoice-number {
                font-family: monospace;
                color: #467FF7;
            }

            /* Financial Summary */
            .invoice-financial {
                background: #f9f9f9;
                padding: 12px;
                border-radius: 4px;
                margin-bottom: 12px;
            }
            .financial-row {
                display: flex;
                justify-content: space-between;
                padding: 4px 0;
            }
            .financial-label {
                color: #666;
            }
            .financial-value {
                font-weight: 500;
            }
            .balance-row {
                border-top: 1px solid #ddd;
                margin-top: 8px;
                padding-top: 8px;
            }

            /* PDF Actions */
            .invoice-pdf-actions {
                text-align: center;
                margin-top: 12px;
            }
            .invoice-pdf-actions .button {
                margin: 2px;
            }
            .pdf-status.success {
                color: #1e8449;
            }
            .pdf-status.error {
                color: #c62828;
            }

            /* Line Items Table */
            .invoice-line-items-wrapper {
                max-height: 400px;
                overflow-y: auto;
            }
            .invoice-line-items-wrapper .column-type { width: 120px; }
            .invoice-line-items-wrapper .column-description { width: auto; }
            .invoice-line-items-wrapper .column-qty { width: 60px; text-align: right; }
            .invoice-line-items-wrapper .column-rate { width: 80px; text-align: right; }
            .invoice-line-items-wrapper .column-amount { width: 100px; text-align: right; }
            .invoice-line-items-wrapper .column-actions { width: 70px; }
            .credit-row {
                background: #fff8e1 !important;
            }
            .credit-amount {
                color: #1976d2;
            }
            .total-row td {
                background: #f5f5f5;
            }

            /* Related Items */
            .related-row {
                padding: 8px 0;
                border-bottom: 1px solid #eee;
            }
            .related-row:last-child {
                border-bottom: none;
            }
            .related-label {
                display: block;
                font-size: 11px;
                color: #666;
                margin-bottom: 2px;
            }
            .related-row a {
                text-decoration: none;
            }
            .related-row a:hover {
                text-decoration: underline;
            }
        </style>';
    }
}
