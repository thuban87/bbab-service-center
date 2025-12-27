<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Billing;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;

/**
 * Invoice Archive shortcode.
 *
 * Displays a paginated list of all invoices for the user's organization
 * with summary stats, status badges, and PDF links.
 *
 * Shortcode: [invoice_archive]
 *
 * Attributes:
 * - per_page: Number of invoices per page (default: 20)
 *
 * Migrated from snippet: 1263
 */
class InvoiceArchive extends BaseShortcode {

    protected string $tag = 'invoice_archive';
    protected bool $requires_org = true;

    /**
     * Render the invoice archive.
     *
     * @param array $atts   Shortcode attributes.
     * @param int   $org_id Organization ID.
     * @return string HTML output.
     */
    protected function output(array $atts, int $org_id): string {
        $atts = $this->parseAtts($atts, [
            'per_page' => 20,
        ]);

        // Get organization name
        $org = get_post($org_id);
        $org_name = $org ? $org->post_title : '';

        // Pagination
        $paged = get_query_var('paged') ? (int) get_query_var('paged') : 1;
        $per_page = (int) $atts['per_page'];
        $offset = ($paged - 1) * $per_page;

        // Get all invoices for totals calculation (excluding Draft/Cancelled)
        $all_invoices = get_posts([
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'organization',
                'value' => $org_id,
                'compare' => '=',
            ]],
        ]);

        $total_invoiced = 0;
        $total_paid = 0;
        $pending_count = 0;

        foreach ($all_invoices as $invoice) {
            $status = get_post_meta($invoice->ID, 'invoice_status', true);

            // Skip Draft and Cancelled invoices entirely
            if (in_array($status, ['Draft', 'Cancelled'])) {
                continue;
            }

            $amount = floatval(get_post_meta($invoice->ID, 'amount', true));
            $paid = floatval(get_post_meta($invoice->ID, 'amount_paid', true));

            $total_invoiced += $amount;

            if ($status === 'Paid') {
                $total_paid += $amount;
            } else {
                $total_paid += $paid;
                if (in_array($status, ['Pending', 'Partial', 'Overdue'])) {
                    $pending_count++;
                }
            }
        }

        $total_outstanding = $total_invoiced - $total_paid;

        // Get paginated invoices (excluding Draft/Cancelled)
        $invoices = get_posts([
            'post_type' => 'invoice',
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'post_status' => 'publish',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'organization',
                    'value' => $org_id,
                    'compare' => '=',
                ],
                [
                    'key' => 'invoice_status',
                    'value' => ['Draft', 'Cancelled'],
                    'compare' => 'NOT IN',
                ],
            ],
            'orderby' => 'meta_value',
            'meta_key' => 'invoice_date',
            'order' => 'DESC',
        ]);

        // Count total for pagination (excluding Draft/Cancelled)
        $total_count = 0;
        foreach ($all_invoices as $invoice) {
            $status = get_post_meta($invoice->ID, 'invoice_status', true);
            if (!in_array($status, ['Draft', 'Cancelled'])) {
                $total_count++;
            }
        }
        $total_pages = ceil($total_count / $per_page);

        return $this->renderArchive([
            'org_name' => $org_name,
            'invoices' => $invoices,
            'total_invoiced' => $total_invoiced,
            'total_paid' => $total_paid,
            'total_outstanding' => $total_outstanding,
            'pending_count' => $pending_count,
            'total_pages' => $total_pages,
            'current_page' => $paged,
        ]);
    }

    /**
     * Render the archive HTML.
     *
     * @param array $data Archive data.
     * @return string HTML output.
     */
    private function renderArchive(array $data): string {
        ob_start();
        ?>
        <div class="bbab-invoice-archive">
            <div class="bbab-archive-header">
                <h2>Invoice History</h2>
                <p class="bbab-archive-subtitle"><?php echo esc_html($data['org_name']); ?></p>
            </div>

            <div class="bbab-archive-summary">
                <div class="bbab-summary-card">
                    <span class="bbab-summary-label">Total Invoiced (All Time)</span>
                    <span class="bbab-summary-value">$<?php echo number_format($data['total_invoiced'], 2); ?></span>
                </div>
                <div class="bbab-summary-card">
                    <span class="bbab-summary-label">Total Paid</span>
                    <span class="bbab-summary-value bbab-text-green">$<?php echo number_format($data['total_paid'], 2); ?></span>
                </div>
                <div class="bbab-summary-card">
                    <span class="bbab-summary-label">Outstanding</span>
                    <span class="bbab-summary-value <?php echo $data['total_outstanding'] > 0 ? 'bbab-text-orange' : ''; ?>">$<?php echo number_format($data['total_outstanding'], 2); ?></span>
                </div>
                <?php if ($data['pending_count'] > 0): ?>
                <div class="bbab-summary-card">
                    <span class="bbab-summary-label">Pending Invoices</span>
                    <span class="bbab-summary-value bbab-text-orange"><?php echo $data['pending_count']; ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($data['invoices'])): ?>
                <div class="bbab-invoice-table-container">
                    <table class="bbab-invoice-table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>PDF</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['invoices'] as $invoice): ?>
                                <?php
                                $inv_number = get_post_meta($invoice->ID, 'invoice_number', true);
                                $inv_date = get_post_meta($invoice->ID, 'invoice_date', true);
                                $amount = floatval(get_post_meta($invoice->ID, 'amount', true));
                                $status = get_post_meta($invoice->ID, 'invoice_status', true);
                                $due_date = get_post_meta($invoice->ID, 'due_date', true);
                                $pdf = get_post_meta($invoice->ID, 'invoice_pdf', true);

                                // Check if overdue
                                if ($status === 'Pending' && $due_date && strtotime($due_date) < strtotime('today')) {
                                    $status = 'Overdue';
                                }

                                $status_class = sanitize_title($status);
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url(get_permalink($invoice->ID)); ?>" class="bbab-invoice-link">
                                            <strong><?php echo esc_html($inv_number); ?></strong>
                                        </a>
                                    </td>
                                    <td><?php echo $inv_date ? date('M j, Y', strtotime($inv_date)) : '—'; ?></td>
                                    <td>$<?php echo number_format($amount, 2); ?></td>
                                    <td><span class="bbab-status-badge status-<?php echo esc_attr($status_class); ?>"><?php echo esc_html($status); ?></span></td>
                                    <td>
                                        <?php if ($pdf): ?>
                                            <?php
                                            $pdf_url = is_array($pdf) ? $pdf['guid'] : wp_get_attachment_url((int) $pdf);
                                            ?>
                                            <a href="<?php echo esc_url($pdf_url); ?>" target="_blank" class="bbab-pdf-btn">View</a>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($data['total_pages'] > 1): ?>
                    <div class="bbab-pagination">
                        <?php
                        echo paginate_links([
                            'total' => $data['total_pages'],
                            'current' => $data['current_page'],
                            'format' => '?paged=%#%',
                        ]);
                        ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <p class="bbab-no-results">No invoices found.</p>
            <?php endif; ?>

            <div class="bbab-archive-footer">
                <a href="/client-dashboard/" class="bbab-back-link">&larr; Back to Dashboard</a>
            </div>
        </div>

        <style>
            .bbab-invoice-archive {
                font-family: 'Poppins', sans-serif;
                max-width: 1000px;
                margin: 0 auto;
            }
            .bbab-archive-header {
                margin-bottom: 24px;
            }
            .bbab-archive-header h2 {
                font-size: 32px;
                font-weight: 600;
                color: #1C244B;
                margin: 0 0 8px 0;
            }
            .bbab-archive-subtitle {
                color: #324A6D;
                margin: 0;
            }
            .bbab-archive-summary {
                display: flex;
                gap: 16px;
                margin-bottom: 24px;
                flex-wrap: wrap;
            }
            .bbab-summary-card {
                background: #F3F5F8;
                border-radius: 8px;
                padding: 16px 24px;
                text-align: center;
                flex: 1;
                min-width: 150px;
            }
            .bbab-summary-label {
                display: block;
                font-size: 12px;
                color: #324A6D;
                margin-bottom: 4px;
            }
            .bbab-summary-value {
                display: block;
                font-size: 24px;
                font-weight: 600;
                color: #1C244B;
            }
            .bbab-text-green { color: #1e8449; }
            .bbab-text-orange { color: #d68910; }
            .bbab-invoice-table-container {
                overflow-x: auto;
            }
            .bbab-invoice-table {
                width: 100%;
                border-collapse: collapse;
                background: white;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }
            .bbab-invoice-table th {
                background: #F3F5F8;
                padding: 12px 16px;
                text-align: left;
                font-size: 12px;
                font-weight: 600;
                color: #324A6D;
                text-transform: uppercase;
            }
            .bbab-invoice-table td {
                padding: 16px;
                border-bottom: 1px solid #f0f0f0;
                color: #324A6D;
            }
            .bbab-invoice-table tr:last-child td {
                border-bottom: none;
            }
            .bbab-invoice-link {
                color: #467FF7;
                text-decoration: none;
            }
            .bbab-invoice-link:hover {
                text-decoration: underline;
            }
            .bbab-status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 500;
            }
            .bbab-status-badge.status-paid { background: #d5f5e3; color: #1e8449; }
            .bbab-status-badge.status-pending { background: #fef9e7; color: #b7950b; }
            .bbab-status-badge.status-partial { background: #e8f4fd; color: #2980b9; }
            .bbab-status-badge.status-overdue { background: #fdedec; color: #c0392b; }
            .bbab-status-badge.status-cancelled { background: #f5f5f5; color: #7f8c8d; }
            .bbab-pdf-btn {
                display: inline-block;
                padding: 4px 8px;
                background: #467FF7;
                color: white !important;
                border-radius: 4px;
                font-size: 12px;
                text-decoration: none !important;
            }
            .bbab-pdf-btn:hover {
                background: #3366cc;
            }
            .bbab-pagination {
                margin-top: 24px;
                text-align: center;
            }
            .bbab-pagination a, .bbab-pagination span {
                display: inline-block;
                padding: 8px 12px;
                margin: 0 4px;
                background: #F3F5F8;
                border-radius: 4px;
                color: #324A6D;
                text-decoration: none;
            }
            .bbab-pagination span.current {
                background: #467FF7;
                color: white;
            }
            .bbab-no-results {
                text-align: center;
                color: #324A6D;
                padding: 48px;
                background: #F3F5F8;
                border-radius: 8px;
            }
            .bbab-archive-footer {
                margin-top: 24px;
            }
            .bbab-back-link {
                color: #467FF7;
                text-decoration: none;
            }
            .bbab-back-link:hover {
                text-decoration: underline;
            }

            @media (max-width: 600px) {
                .bbab-summary-card {
                    min-width: 100%;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }
}
