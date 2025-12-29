<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Billing;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Utils\UserContext;
use BBAB\ServiceCenter\Utils\Settings;

/**
 * Single Invoice Detail shortcode.
 *
 * Displays a full invoice with header, info cards, line items,
 * totals, and payment options (Zelle, ACH, Credit Card).
 *
 * Shortcode: [invoice_detail]
 *
 * Attributes:
 * - id: Invoice post ID (default: current post)
 *
 * Migrated from snippet: 2104
 */
class InvoiceDetail extends BaseShortcode {

    protected string $tag = 'invoice_detail';
    protected bool $requires_org = false; // We do our own access control
    protected bool $requires_login = true;

    /**
     * Render the invoice detail.
     *
     * @param array $atts   Shortcode attributes.
     * @param int   $org_id Organization ID (not used directly).
     * @return string HTML output.
     */
    protected function output(array $atts, int $org_id): string {
        $atts = $this->parseAtts($atts, [
            'id' => get_the_ID(),
        ]);

        $invoice_id = (int) $atts['id'];

        if (!$invoice_id || get_post_type($invoice_id) !== 'invoice') {
            return '<p>Invoice not found.</p>';
        }

        // Security check - user must belong to invoice's org OR be admin
        $user_id = get_current_user_id();
        $user_org = get_user_meta($user_id, 'organization', true);
        $invoice_org = get_post_meta($invoice_id, 'organization', true);

        // Also check simulation mode
        $current_org = UserContext::getCurrentOrgId();

        if (!current_user_can('manage_options') && $user_org != $invoice_org && $current_org != $invoice_org) {
            return '<p>You do not have permission to view this invoice.</p>';
        }

        // Draft invoices not visible to clients
        $status = get_post_meta($invoice_id, 'invoice_status', true);
        if ($status === 'Draft' && !current_user_can('manage_options')) {
            return '<p>This invoice is not yet available.</p>';
        }

        // Gather invoice data
        $data = $this->getInvoiceData($invoice_id, $status);

        return $this->renderInvoice($data);
    }

    /**
     * Get all invoice data for rendering.
     *
     * @param int    $invoice_id Invoice post ID.
     * @param string $status     Invoice status.
     * @return array Invoice data.
     */
    private function getInvoiceData(int $invoice_id, string $status): array {
        $invoice_number = get_post_meta($invoice_id, 'invoice_number', true);
        $invoice_date = get_post_meta($invoice_id, 'invoice_date', true);
        $due_date = get_post_meta($invoice_id, 'due_date', true);
        $amount = floatval(get_post_meta($invoice_id, 'amount', true));
        $amount_paid = floatval(get_post_meta($invoice_id, 'amount_paid', true));
        $balance = $amount - $amount_paid;
        $pdf = get_post_meta($invoice_id, 'invoice_pdf', true);
        $report_id = get_post_meta($invoice_id, 'related_monthly_report', true);
        $invoice_org = get_post_meta($invoice_id, 'organization', true);

        // Get org name
        $org_name = $invoice_org ? get_the_title($invoice_org) : '';

        // Check if overdue
        if ($status === 'Pending' && $due_date && strtotime($due_date) < strtotime('today')) {
            $status = 'Overdue';
        }

        // Get line items
        $line_items = get_posts([
            'post_type' => 'invoice_line_item',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'related_invoice',
                'value' => $invoice_id,
                'compare' => '=',
            ]],
            'orderby' => 'meta_value_num',
            'meta_key' => 'display_order',
            'order' => 'ASC',
        ]);

        // Get report month if applicable
        $report_month = '';
        if ($report_id) {
            $report_month = get_post_meta($report_id, 'report_month', true);
        }

        // PDF URL
        $pdf_url = '';
        if ($pdf) {
            $pdf_url = is_array($pdf) ? $pdf['guid'] : wp_get_attachment_url((int) $pdf);
        }

        return [
            'invoice_id' => $invoice_id,
            'invoice_number' => $invoice_number,
            'invoice_date' => $invoice_date,
            'due_date' => $due_date,
            'amount' => $amount,
            'amount_paid' => $amount_paid,
            'balance' => $balance,
            'status' => $status,
            'org_name' => $org_name,
            'report_month' => $report_month,
            'pdf_url' => $pdf_url,
            'line_items' => $line_items,
        ];
    }

    /**
     * Render the invoice HTML.
     *
     * @param array $data Invoice data.
     * @return string HTML output.
     */
    private function renderInvoice(array $data): string {
        $status_classes = [
            'Draft' => 'status-draft',
            'Pending' => 'status-pending',
            'Paid' => 'status-paid',
            'Partial' => 'status-partial',
            'Overdue' => 'status-overdue',
            'Cancelled' => 'status-cancelled',
        ];
        $status_class = $status_classes[$data['status']] ?? 'status-pending';

        ob_start();
        ?>
        <div class="bbab-invoice-view">
            <!-- Header -->
            <div class="bbab-invoice-view-header">
                <div class="bbab-invoice-view-title">
                    <h1>Invoice <?php echo esc_html($data['invoice_number']); ?></h1>
                    <span class="bbab-status-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html($data['status']); ?></span>
                </div>
                <div class="bbab-invoice-view-actions">
                    <?php if ($data['pdf_url']): ?>
                        <a href="<?php echo esc_url($data['pdf_url']); ?>" target="_blank" class="bbab-btn-primary">Download PDF</a>
                    <?php endif; ?>
                    <a href="/client-dashboard/invoices/" class="bbab-btn-secondary">&larr; All Invoices</a>
                </div>
            </div>

            <!-- Info Cards Row -->
            <div class="bbab-invoice-info-row">
                <div class="bbab-info-card">
                    <span class="info-label">Bill To</span>
                    <span class="info-value"><?php echo esc_html($data['org_name']); ?></span>
                </div>
                <div class="bbab-info-card">
                    <span class="info-label">Invoice Date</span>
                    <span class="info-value"><?php echo $data['invoice_date'] ? date('M j, Y', strtotime($data['invoice_date'])) : '—'; ?></span>
                </div>
                <div class="bbab-info-card">
                    <span class="info-label">Due Date</span>
                    <span class="info-value <?php echo ($data['status'] === 'Overdue') ? 'overdue' : ''; ?>">
                        <?php echo $data['due_date'] ? date('M j, Y', strtotime($data['due_date'])) : '—'; ?>
                    </span>
                </div>
                <?php if ($data['report_month']): ?>
                <div class="bbab-info-card">
                    <span class="info-label">Period</span>
                    <span class="info-value"><?php echo esc_html($data['report_month']); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Line Items -->
            <?php if (!empty($data['line_items'])): ?>
            <div class="bbab-invoice-section">
                <h3>Line Items</h3>
                <div class="bbab-line-items-list">
                    <?php foreach ($data['line_items'] as $item):
                        $line_type = get_post_meta($item->ID, 'line_type', true);
                        $description = get_post_meta($item->ID, 'description', true);
                        $quantity = get_post_meta($item->ID, 'quantity', true);
                        $rate = get_post_meta($item->ID, 'rate', true);
                        $line_amount = floatval(get_post_meta($item->ID, 'amount', true));
                        $is_credit = $line_amount < 0;

                        // Type badge class
                        $type_classes = [
                            'Hosting Fee' => 'type-hosting',
                            'Support' => 'type-support',
                            'Support (Non-Billable)' => 'type-nonbillable',
                            'Free Hours Credit' => 'type-credit',
                            'Project (Flat)' => 'type-project',
                            'Project (Hourly)' => 'type-project',
                            'Late Fee' => 'type-latefee',
                            'Deposit' => 'type-deposit',
                            'Deposit Credit' => 'type-credit',
                        ];
                        $type_class = $type_classes[$line_type] ?? 'type-default';
                    ?>
                    <div class="bbab-line-item-card <?php echo $is_credit ? 'credit-item' : ''; ?>">
                        <div class="line-item-main">
                            <div class="line-item-info">
                                <span class="line-item-type <?php echo esc_attr($type_class); ?>"><?php echo esc_html($line_type); ?></span>
                                <span class="line-item-desc"><?php echo esc_html($description); ?></span>
                            </div>
                            <div class="line-item-details">
                                <?php if (is_numeric($quantity) && floatval($quantity) > 0): ?>
                                    <span class="line-item-qty"><?php
                                        echo number_format(floatval($quantity), 2);
                                        if (in_array($line_type, ['Support', 'Support (Non-Billable)', 'Project (Hourly)'])) {
                                            echo ' hrs';
                                        }
                                    ?></span>
                                <?php endif; ?>
                                <?php if (is_numeric($rate) && floatval($rate) != 0):
                                    $rate_float = floatval($rate);
                                ?>
                                    <span class="line-item-rate">@ <?php echo ($rate_float < 0 ? '-' : '') . '$' . number_format(abs($rate_float), 2); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="line-item-amount <?php echo $is_credit ? 'credit' : ''; ?>">
                                <?php echo ($line_amount < 0 ? '-' : '') . '$' . number_format(abs($line_amount), 2); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Totals -->
            <div class="bbab-invoice-totals-section">
                <div class="bbab-totals-card">
                    <div class="totals-row">
                        <span class="totals-label">Invoice Total</span>
                        <span class="totals-value">$<?php echo number_format($data['amount'], 2); ?></span>
                    </div>
                    <?php if ($data['amount_paid'] > 0): ?>
                    <div class="totals-row paid-row">
                        <span class="totals-label">Paid</span>
                        <span class="totals-value">-$<?php echo number_format($data['amount_paid'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="totals-row balance-row <?php echo $data['balance'] <= 0 ? 'paid-full' : ''; ?>">
                        <span class="totals-label"><?php echo $data['balance'] <= 0 ? 'Balance' : 'Balance Due'; ?></span>
                        <span class="totals-value">$<?php echo number_format(max(0, $data['balance']), 2); ?></span>
                    </div>
                </div>

                <?php if ($data['balance'] > 0 && in_array($data['status'], ['Pending', 'Partial', 'Overdue'])):
                    $cc_fee_rate = Settings::get('cc_fee_percentage', 0.03);
                    $cc_fee = round($data['balance'] * $cc_fee_rate, 2);
                    $total_with_fee = $data['balance'] + $cc_fee;
                    $zelle_email = Settings::get('zelle_email', 'wales108@gmail.com');
                ?>
                <div class="bbab-payment-section">
                    <h3>Payment Options</h3>

                    <div class="bbab-payment-options">
                        <!-- Zelle Option -->
                        <div class="bbab-payment-option" data-method="zelle">
                            <div class="payment-option-header">
                                <input type="radio" name="payment_method" id="pay-zelle" value="zelle">
                                <label for="pay-zelle">
                                    <strong>Zelle</strong> <span class="payment-badge recommended">No Fee</span>
                                </label>
                            </div>
                            <div class="payment-option-details" id="zelle-details" style="display: none;">
                                <p>Send payment to: <strong><?php echo esc_html($zelle_email); ?></strong></p>
                                <p>Amount: <strong>$<?php echo number_format($data['balance'], 2); ?></strong></p>
                                <p class="payment-note">After sending, click below to notify us:</p>
                                <button type="button" class="bbab-btn-primary" id="zelle-notify-btn">I've Sent Payment via Zelle</button>
                            </div>
                        </div>

                        <!-- ACH Option -->
                        <div class="bbab-payment-option" data-method="ach">
                            <div class="payment-option-header">
                                <input type="radio" name="payment_method" id="pay-ach" value="ach">
                                <label for="pay-ach">
                                    <strong>Bank Transfer (ACH)</strong> <span class="payment-badge">No Fee</span>
                                </label>
                            </div>
                            <div class="payment-option-details" id="ach-details" style="display: none;">
                                <p>Amount: <strong>$<?php echo number_format($data['balance'], 2); ?></strong></p>
                                <button type="button" class="bbab-btn-primary bbab-pay-btn" data-method="ach">Pay $<?php echo number_format($data['balance'], 2); ?> via Bank Transfer</button>
                                <p class="payment-note">You'll be redirected to Stripe to complete payment securely.</p>
                            </div>
                        </div>

                        <!-- Credit Card Option -->
                        <div class="bbab-payment-option" data-method="card">
                            <div class="payment-option-header">
                                <input type="radio" name="payment_method" id="pay-card" value="card">
                                <label for="pay-card">
                                    <strong>Credit/Debit Card</strong> <span class="payment-badge fee">+3% Fee</span>
                                </label>
                            </div>
                            <div class="payment-option-details" id="card-details" style="display: none;">
                                <div class="fee-breakdown">
                                    <div class="fee-row"><span>Invoice Amount:</span> <span>$<?php echo number_format($data['balance'], 2); ?></span></div>
                                    <div class="fee-row"><span>Processing Fee (3%):</span> <span>$<?php echo number_format($cc_fee, 2); ?></span></div>
                                    <div class="fee-row total"><span>Total:</span> <span>$<?php echo number_format($total_with_fee, 2); ?></span></div>
                                </div>
                                <button type="button" class="bbab-btn-primary bbab-pay-btn" data-method="card">Pay $<?php echo number_format($total_with_fee, 2); ?> by Card</button>
                                <p class="payment-note">You'll be redirected to Stripe to complete payment securely.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="payment-message" class="bbab-payment-message" style="display: none;"></div>

                <script>
                jQuery(document).ready(function($) {
                    var invoiceId = <?php echo $data['invoice_id']; ?>;
                    var nonce = '<?php echo wp_create_nonce('bbab_payment_nonce'); ?>';

                    // Toggle payment details
                    $('input[name="payment_method"]').on('change', function() {
                        $('.payment-option-details').slideUp(200);
                        var method = $(this).val();
                        $('#' + method + '-details').slideDown(200);
                    });

                    // Stripe checkout buttons (AJAX handlers will be wired in Phase 6.3)
                    $('.bbab-pay-btn').on('click', function() {
                        var btn = $(this);
                        var method = btn.data('method');
                        btn.prop('disabled', true).text('Processing...');

                        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'bbab_create_checkout',
                            invoice_id: invoiceId,
                            payment_method: method,
                            nonce: nonce
                        }, function(response) {
                            if (response.success) {
                                window.location.href = response.data.checkout_url;
                            } else {
                                btn.prop('disabled', false).text('Try Again');
                                $('#payment-message').text(response.data.message || 'Payment system not yet configured.').addClass('error').show();
                            }
                        }).fail(function() {
                            btn.prop('disabled', false).text('Try Again');
                            $('#payment-message').text('Connection error. Please try again.').addClass('error').show();
                        });
                    });

                    // Zelle notification
                    $('#zelle-notify-btn').on('click', function() {
                        var btn = $(this);
                        btn.prop('disabled', true).text('Sending...');

                        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'bbab_zelle_paid_notification',
                            invoice_id: invoiceId,
                            nonce: nonce
                        }, function(response) {
                            if (response.success) {
                                btn.text('Notification Sent');
                                $('#payment-message').text('Thank you! We\'ve been notified and will verify your payment shortly.').addClass('success').show();
                            } else {
                                btn.prop('disabled', false).text('Try Again');
                                $('#payment-message').text(response.data.message || 'Could not send notification.').addClass('error').show();
                            }
                        });
                    });

                    // Check for payment status in URL
                    var urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.get('payment') === 'success') {
                        $('#payment-message').html('Payment successful! Thank you.<br><small>Refreshing to update status...</small>').addClass('success').show();
                        // Hide payment options since payment was just made
                        $('.bbab-payment-section').hide();
                        // Refresh after 3 seconds to allow webhook to process
                        setTimeout(function() {
                            // Remove query param and refresh
                            window.location.href = window.location.pathname;
                        }, 3000);
                    } else if (urlParams.get('payment') === 'cancelled') {
                        $('#payment-message').text('Payment was cancelled.').addClass('error').show();
                    }
                });
                </script>
                <?php endif; ?>
            </div>
        </div>

        <?php echo $this->getStyles(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Get CSS styles for the invoice view.
     *
     * @return string CSS styles.
     */
    private function getStyles(): string {
        return '<style>
        .bbab-invoice-view {
            font-family: "Poppins", sans-serif;
            max-width: 900px;
            margin: 0 auto;
        }

        /* Header */
        .bbab-invoice-view-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .bbab-invoice-view-title h1 {
            font-size: 28px;
            font-weight: 600;
            color: #1C244B;
            margin: 0 0 8px 0;
        }
        .bbab-invoice-view-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .bbab-btn-primary {
            display: inline-block;
            padding: 10px 20px;
            background: #467FF7;
            color: white !important;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: background 0.2s;
            border: none;
            cursor: pointer;
        }
        .bbab-btn-primary:hover {
            background: #3366cc;
            color: white;
        }
        .bbab-btn-secondary {
            display: inline-block;
            padding: 10px 20px;
            background: #F3F5F8;
            color: #324A6D !important;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: background 0.2s;
        }
        .bbab-btn-secondary:hover {
            background: #e5e7eb;
        }

        /* Status Badges */
        .bbab-status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
        }
        .status-draft { background: #f0f0f1; color: #50575e; }
        .status-pending { background: #fef9e7; color: #b7950b; }
        .status-paid { background: #d5f5e3; color: #1e8449; }
        .status-partial { background: #e8f4fd; color: #2980b9; }
        .status-overdue { background: #fdedec; color: #c0392b; }
        .status-cancelled { background: #f5f5f5; color: #7f8c8d; }

        /* Info Cards Row */
        .bbab-invoice-info-row {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .bbab-info-card {
            flex: 1;
            min-width: 150px;
            background: #F3F5F8;
            border-radius: 8px;
            padding: 16px;
        }
        .info-label {
            display: block;
            font-size: 12px;
            color: #324A6D;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-value {
            display: block;
            font-size: 16px;
            font-weight: 600;
            color: #1C244B;
        }
        .info-value.overdue {
            color: #c0392b;
        }

        /* Line Items Section */
        .bbab-invoice-section {
            margin-bottom: 24px;
        }
        .bbab-invoice-section h3 {
            font-size: 18px;
            font-weight: 600;
            color: #1C244B;
            margin: 0 0 16px 0;
        }
        .bbab-line-items-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .bbab-line-item-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px 20px;
            transition: box-shadow 0.2s;
        }
        .bbab-line-item-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .bbab-line-item-card.credit-item {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }
        .line-item-main {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .line-item-info {
            flex: 1;
            min-width: 200px;
        }
        .line-item-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
            margin-right: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .type-hosting { background: #dbeafe; color: #1d4ed8; }
        .type-support { background: #fef3c7; color: #b45309; }
        .type-nonbillable { background: #f3f4f6; color: #6b7280; }
        .type-credit { background: #d1fae5; color: #047857; }
        .type-project { background: #ede9fe; color: #6d28d9; }
        .type-latefee { background: #fee2e2; color: #b91c1c; }
        .type-deposit { background: #dbeafe; color: #1d4ed8; }
        .type-default { background: #f3f4f6; color: #374151; }

        .line-item-desc {
            font-weight: 500;
            color: #1C244B;
        }
        .line-item-details {
            display: flex;
            gap: 12px;
            color: #324A6D;
            font-size: 14px;
        }
        .line-item-amount {
            font-size: 18px;
            font-weight: 600;
            color: #1C244B;
            min-width: 100px;
            text-align: right;
        }
        .line-item-amount.credit {
            color: #047857;
        }

        /* Totals Section */
        .bbab-invoice-totals-section {
            margin-top: 24px;
        }
        .bbab-totals-card {
            background: #F3F5F8;
            border-radius: 8px;
            padding: 20px 24px;
            max-width: 350px;
            margin-left: auto;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 15px;
        }
        .totals-label {
            color: #324A6D;
        }
        .totals-value {
            font-weight: 600;
            color: #1C244B;
        }
        .paid-row .totals-value {
            color: #047857;
        }
        .balance-row {
            border-top: 2px solid #d1d5db;
            margin-top: 8px;
            padding-top: 12px;
            font-size: 18px;
        }
        .balance-row .totals-value {
            color: #c0392b;
        }
        .balance-row.paid-full .totals-value {
            color: #047857;
        }

        /* Payment Section */
        .bbab-payment-section {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 2px solid #e5e7eb;
        }
        .bbab-payment-section h3 {
            font-size: 18px;
            font-weight: 600;
            color: #1C244B;
            margin: 0 0 16px 0;
        }
        .bbab-payment-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .bbab-payment-option {
            background: #F3F5F8;
            border: 2px solid transparent;
            border-radius: 8px;
            padding: 16px;
            transition: all 0.2s;
        }
        .bbab-payment-option:has(input:checked) {
            border-color: #467FF7;
            background: #f0f5ff;
        }
        .payment-option-header {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
        }
        .payment-option-header label {
            cursor: pointer;
            flex: 1;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .payment-badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 10px;
            background: #d1fae5;
            color: #047857;
        }
        .payment-badge.fee {
            background: #fef3c7;
            color: #b45309;
        }
        .payment-badge.recommended {
            background: #dbeafe;
            color: #1d4ed8;
        }
        .payment-option-details {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #d1d5db;
        }
        .payment-option-details p {
            margin: 0 0 12px 0;
            font-size: 14px;
        }
        .payment-note {
            font-size: 13px !important;
            color: #6b7280;
        }
        .fee-breakdown {
            background: white;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 16px;
        }
        .fee-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 14px;
        }
        .fee-row.total {
            border-top: 1px solid #e5e7eb;
            margin-top: 8px;
            padding-top: 8px;
            font-weight: 600;
        }
        .bbab-payment-message {
            margin-top: 16px;
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 14px;
        }
        .bbab-payment-message.success {
            background: #d1fae5;
            color: #047857;
        }
        .bbab-payment-message.error {
            background: #fee2e2;
            color: #b91c1c;
        }

        /* Responsive */
        @media (max-width: 600px) {
            .bbab-invoice-view-header {
                flex-direction: column;
            }
            .bbab-info-card {
                min-width: 100%;
            }
            .line-item-main {
                flex-direction: column;
                align-items: flex-start;
            }
            .line-item-amount {
                text-align: left;
                margin-top: 8px;
            }
            .bbab-totals-card {
                max-width: 100%;
            }
        }
        </style>';
    }
}
