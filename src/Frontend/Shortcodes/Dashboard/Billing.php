<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;

/**
 * Dashboard Billing shortcode.
 *
 * Shows recent invoices and contract renewal info.
 *
 * Shortcode: [dashboard_billing]
 * Migrated from: WPCode Snippet #1254
 */
class Billing extends BaseShortcode {

    protected string $tag = 'dashboard_billing';

    /**
     * Render the billing output.
     */
    protected function output(array $atts, int $org_id): string {
        // Get recent standard invoices
        $invoices = pods('invoice', [
            'where' => "organization.ID = {$org_id}",
            'orderby' => 'invoice_date DESC',
            'limit' => 10,
        ]);

        // Calculate outstanding balance from all invoices
        $balance_data = $this->calculateOutstandingBalance($org_id);

        // Get contract renewal date from org
        $org_pod = pods('client_organization', $org_id);
        $renewal_date = $org_pod->field('contract_renewal_date');
        $contract_type = $org_pod->field('contract_type');

        ob_start();
        ?>
        <div class="bbab-billing-section">
            <div class="bbab-billing-header">
                <h3>Billing</h3>
            </div>

            <?php if ($balance_data['outstanding'] > 0): ?>
            <div class="bbab-outstanding-balance">
                <div class="bbab-balance-amount">
                    <span class="balance-label">Outstanding Balance</span>
                    <span class="balance-value">$<?php echo number_format($balance_data['outstanding'], 2); ?></span>
                </div>
                <div class="bbab-balance-details">
                    <span><?php echo $balance_data['pending_count']; ?> unpaid invoice<?php echo $balance_data['pending_count'] !== 1 ? 's' : ''; ?></span>
                    <a href="/client-dashboard/invoices/" class="bbab-pay-now-link">View &amp; Pay</a>
                </div>
            </div>
            <?php endif; ?>

            <div class="bbab-invoices-list">
                <h4>Recent Invoices</h4>

                <?php
                $displayed = 0;
                $max_display = 5;

                if ($invoices->total() > 0):
                    while ($invoices->fetch() && $displayed < $max_display):
                        // Filter for Standard invoices in PHP
                        $inv_type = $invoices->field('invoice_type');
                        if (!empty($inv_type) && $inv_type !== 'Standard') {
                            continue;
                        }

                        // Hide Draft and Cancelled invoices from clients
                        $inv_status = $invoices->field('invoice_status');
                        if (in_array($inv_status, ['Draft', 'Cancelled'], true)) {
                            continue;
                        }

                        $displayed++;

                        $inv_id = $invoices->id();
                        $inv_number = $invoices->field('invoice_number');
                        $inv_date = $invoices->field('invoice_date');
                        $amount = floatval($invoices->field('amount'));
                        $amount_paid = floatval($invoices->field('amount_paid'));
                        $status = $inv_status;
                        $due_date = $invoices->field('due_date');
                        $pdf = $invoices->field('invoice_pdf');

                        // Check if overdue
                        if ($status === 'Pending' && $due_date && strtotime($due_date) < strtotime('today')) {
                            $status = 'Overdue';
                        }

                        // Check if partial
                        if ($status === 'Pending' && $amount_paid > 0 && $amount_paid < $amount) {
                            $status = 'Partial';
                        }

                        $status_class = sanitize_title($status);
                        $status_icons = [
                            'paid' => '&#10003;',
                            'pending' => '&#9203;',
                            'partial' => '&#9684;',
                            'overdue' => '&#9888;',
                            'cancelled' => '&#10007;',
                        ];
                        $status_icon = $status_icons[$status_class] ?? '';

                        // Format date for display
                        $display_date = $inv_date ? date('F Y', strtotime($inv_date)) : '';
                        ?>
                        <div class="bbab-invoice-card">
                            <div class="bbab-invoice-main">
                                <div class="bbab-invoice-info">
                                    <a href="<?php echo esc_url(get_permalink($inv_id)); ?>" class="bbab-invoice-number-link"><?php echo esc_html($inv_number); ?></a>
                                    <span class="bbab-invoice-period"><?php echo esc_html($display_date); ?></span>
                                </div>
                                <div class="bbab-invoice-amount">$<?php echo esc_html(number_format($amount, 2)); ?></div>
                                <div class="bbab-invoice-status status-<?php echo esc_attr($status_class); ?>">
                                    <?php echo $status_icon; ?> <?php echo esc_html($status); ?>
                                </div>
                            </div>
                            <div class="bbab-invoice-actions">
                                <a href="<?php echo esc_url(get_permalink($inv_id)); ?>" class="bbab-view-link">View Details</a>
                                <?php if ($pdf): ?>
                                    <a href="<?php echo esc_url($pdf['guid']); ?>" target="_blank" class="bbab-pdf-link">Download PDF</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>

                    <?php if ($displayed === 0): ?>
                        <p class="bbab-no-invoices">No standard invoices yet.</p>
                    <?php endif; ?>

                    <a href="/invoices/" class="bbab-view-all-link">View All Invoices</a>
                <?php else: ?>
                    <p class="bbab-no-invoices">No invoices yet.</p>
                <?php endif; ?>
            </div>

            <?php if ($renewal_date): ?>
                <?php
                $renewal_timestamp = strtotime($renewal_date);
                $days_until = (int) floor(($renewal_timestamp - strtotime('today')) / DAY_IN_SECONDS);
                $renewal_class = '';
                if ($days_until <= 7) {
                    $renewal_class = 'bbab-renewal-urgent';
                } elseif ($days_until <= 30) {
                    $renewal_class = 'bbab-renewal-soon';
                }
                ?>
                <div class="bbab-contract-renewal <?php echo esc_attr($renewal_class); ?>">
                    <span class="bbab-renewal-icon">&#128197;</span>
                    <span class="bbab-renewal-text">
                        Contract Renewal: <?php echo esc_html(date('F j, Y', $renewal_timestamp)); ?>
                        (<?php echo esc_html($days_until); ?> days)
                        <?php if ($contract_type): ?>
                            <span class="bbab-contract-type">&bull; <?php echo esc_html($contract_type); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .bbab-billing-section {
                background: #F3F5F8;
                border-radius: 12px;
                padding: 24px;
                margin-bottom: 24px;
                font-family: 'Poppins', sans-serif;
            }
            .bbab-billing-header h3 {
                font-size: 22px;
                font-weight: 600;
                color: #1C244B;
                margin: 0 0 16px 0;
            }
            .bbab-invoices-list h4 {
                font-size: 16px;
                font-weight: 500;
                color: #324A6D;
                margin: 0 0 12px 0;
            }
            .bbab-invoice-card {
                background: white;
                border-radius: 8px;
                padding: 16px;
                margin-bottom: 12px;
            }
            .bbab-invoice-main {
                display: flex;
                align-items: center;
                gap: 16px;
                flex-wrap: wrap;
            }
            .bbab-invoice-info {
                flex: 1;
                min-width: 150px;
            }
            .bbab-invoice-number-link {
                font-weight: 600;
                color: #467FF7;
                text-decoration: none;
                margin-right: 8px;
            }
            .bbab-invoice-number-link:hover {
                text-decoration: underline;
            }
            .bbab-invoice-period {
                color: #324A6D;
            }
            .bbab-invoice-amount {
                font-weight: 600;
                font-size: 18px;
                color: #1C244B;
            }
            .bbab-invoice-status {
                font-size: 14px;
                padding: 4px 12px;
                border-radius: 20px;
            }
            .bbab-invoice-status.status-paid {
                background: #d5f5e3;
                color: #1e8449;
            }
            .bbab-invoice-status.status-pending {
                background: #fef9e7;
                color: #b7950b;
            }
            .bbab-invoice-status.status-partial {
                background: #e8f4fd;
                color: #2980b9;
            }
            .bbab-invoice-status.status-overdue {
                background: #fdedec;
                color: #c0392b;
            }
            .bbab-invoice-status.status-cancelled {
                background: #f5f5f5;
                color: #7f8c8d;
            }
            .bbab-invoice-actions {
                margin-top: 12px;
                padding-top: 12px;
                border-top: 1px solid #eee;
                display: flex;
                gap: 16px;
            }
            .bbab-invoice-actions a {
                font-size: 14px;
                color: #467FF7;
                text-decoration: none;
            }
            .bbab-invoice-actions a:hover {
                text-decoration: underline;
            }
            .bbab-view-all-link {
                display: block;
                text-align: center;
                color: #467FF7;
                text-decoration: none;
                margin-top: 12px;
            }
            .bbab-no-invoices {
                text-align: center;
                color: #324A6D;
                padding: 24px;
            }
            .bbab-outstanding-balance {
                background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
                border-radius: 8px;
                padding: 16px 20px;
                margin-bottom: 16px;
                color: white;
            }
            .bbab-balance-amount {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 8px;
            }
            .bbab-balance-amount .balance-label {
                font-size: 14px;
                font-weight: 500;
                opacity: 0.9;
            }
            .bbab-balance-amount .balance-value {
                font-size: 24px;
                font-weight: 700;
            }
            .bbab-balance-details {
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 13px;
                opacity: 0.9;
            }
            .bbab-pay-now-link {
                color: white !important;
                background: rgba(255,255,255,0.2);
                padding: 4px 12px;
                border-radius: 4px;
                text-decoration: none;
                font-weight: 500;
                transition: background 0.2s;
            }
            .bbab-pay-now-link:hover {
                background: rgba(255,255,255,0.3);
            }
            .bbab-contract-renewal {
                margin-top: 20px;
                padding: 16px;
                background: white;
                border-radius: 8px;
                border-left: 4px solid #3498db;
            }
            .bbab-contract-renewal.bbab-renewal-soon {
                border-left-color: #f39c12;
                background: #fef9e7;
            }
            .bbab-contract-renewal.bbab-renewal-urgent {
                border-left-color: #e74c3c;
                background: #fdedec;
            }
            .bbab-renewal-icon {
                margin-right: 8px;
            }
            .bbab-renewal-text {
                color: #324A6D;
            }
            .bbab-contract-type {
                color: #7f8c8d;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Calculate outstanding balance from all invoices.
     *
     * @param int $org_id Organization ID.
     * @return array Balance data with 'outstanding' and 'pending_count'.
     */
    private function calculateOutstandingBalance(int $org_id): array {
        $invoices = get_posts([
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'organization',
                'value' => $org_id,
                'compare' => '=',
            ]],
        ]);

        $total_outstanding = 0;
        $pending_count = 0;

        foreach ($invoices as $invoice) {
            $status = get_post_meta($invoice->ID, 'invoice_status', true);

            // Skip Draft, Cancelled, and Paid invoices
            if (in_array($status, ['Draft', 'Cancelled', 'Paid'])) {
                continue;
            }

            $amount = floatval(get_post_meta($invoice->ID, 'amount', true));
            $paid = floatval(get_post_meta($invoice->ID, 'amount_paid', true));
            $balance = $amount - $paid;

            if ($balance > 0) {
                $total_outstanding += $balance;
                $pending_count++;
            }
        }

        return [
            'outstanding' => $total_outstanding,
            'pending_count' => $pending_count,
        ];
    }
}
