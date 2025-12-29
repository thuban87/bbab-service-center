<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Billing;

use BBAB\ServiceCenter\Utils\Settings;
use BBAB\ServiceCenter\Utils\Logger;
use WP_Error;

/**
 * Stripe Payment Service.
 *
 * Handles Stripe Checkout session creation, payment recording,
 * and AJAX handlers for frontend payment buttons.
 *
 * Migrated from snippets: 2134, 2135
 */
class StripeService {

    /**
     * Register hooks.
     */
    public function register(): void {
        // AJAX handlers for frontend payment (logged-in users only)
        add_action('wp_ajax_bbab_create_checkout', [$this, 'handleCreateCheckout']);
        add_action('wp_ajax_bbab_zelle_paid_notification', [$this, 'handleZelleNotification']);
    }

    /**
     * Create a Stripe Checkout session for invoice payment.
     *
     * @param int    $invoice_id     Invoice post ID.
     * @param string $payment_method Payment method: 'card' or 'ach'.
     * @return array|WP_Error Checkout session data or error.
     */
    public function createCheckoutSession(int $invoice_id, string $payment_method = 'card') {
        if (!Settings::isStripeConfigured()) {
            return new WP_Error('stripe_not_configured', 'Stripe payment is not configured.');
        }

        $invoice = pods('invoice', $invoice_id);
        if (!$invoice || !$invoice->exists()) {
            return new WP_Error('invalid_invoice', 'Invoice not found.');
        }

        $invoice_number = $invoice->field('invoice_number');
        $amount = floatval($invoice->field('amount'));
        $amount_paid = floatval($invoice->field('amount_paid'));
        $balance = $amount - $amount_paid;

        if ($balance <= 0) {
            return new WP_Error('no_balance', 'Invoice already paid.');
        }

        // Calculate CC fee if paying by card
        $cc_fee = 0;
        if ($payment_method === 'card') {
            $cc_fee_rate = floatval(Settings::get('cc_fee_percentage', 0.03));
            $cc_fee = round($balance * $cc_fee_rate, 2);
        }

        $total_charge = $balance + $cc_fee;

        // Build line items for Stripe
        $line_items = [
            [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => 'Invoice ' . $invoice_number,
                        'description' => 'Payment for invoice ' . $invoice_number,
                    ],
                    'unit_amount' => (int) round($balance * 100), // Stripe uses cents
                ],
                'quantity' => 1,
            ],
        ];

        // Add CC fee as separate line item if applicable
        if ($cc_fee > 0) {
            $fee_percent = round($cc_fee / $balance * 100);
            $line_items[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => "Credit Card Processing Fee ({$fee_percent}%)",
                    ],
                    'unit_amount' => (int) round($cc_fee * 100),
                ],
                'quantity' => 1,
            ];
        }

        // Set payment method types based on selection
        $payment_method_types = ($payment_method === 'ach')
            ? ['us_bank_account']
            : ['card'];

        // Build success/cancel URLs using actual permalink (handles slug variations)
        $invoice_permalink = get_permalink($invoice_id);
        $success_url = add_query_arg('payment', 'success', $invoice_permalink);
        $cancel_url = add_query_arg('payment', 'cancelled', $invoice_permalink);

        // Create Stripe Checkout Session via API
        $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', [
            'headers' => [
                'Authorization' => 'Bearer ' . Settings::getStripeSecretKey(),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'payment_method_types' => $payment_method_types,
                'line_items' => $line_items,
                'mode' => 'payment',
                'success_url' => $success_url,
                'cancel_url' => $cancel_url,
                'metadata' => [
                    'invoice_id' => $invoice_id,
                    'invoice_number' => $invoice_number,
                    'cc_fee' => $cc_fee,
                ],
                'payment_intent_data' => [
                    'metadata' => [
                        'invoice_id' => $invoice_id,
                        'invoice_number' => $invoice_number,
                    ],
                ],
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            Logger::error('Stripe', 'Checkout session creation failed', [
                'error' => $response->get_error_message(),
            ]);
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            Logger::error('Stripe', 'Stripe API error', [
                'error' => $body['error']['message'] ?? 'Unknown error',
            ]);
            return new WP_Error('stripe_error', $body['error']['message'] ?? 'Stripe error occurred.');
        }

        Logger::api('Stripe', 'checkout_session', 'success', [
            'invoice_id' => $invoice_id,
            'amount' => $total_charge,
        ]);

        return $body;
    }

    /**
     * Record a payment on an invoice.
     *
     * @param int    $invoice_id     Invoice post ID.
     * @param float  $amount         Payment amount.
     * @param string $method         Payment method: 'stripe', 'ach', 'zelle'.
     * @param string $transaction_id Optional transaction/payment intent ID.
     * @param float  $cc_fee         Optional CC fee amount.
     * @return bool True on success.
     */
    public function recordPayment(
        int $invoice_id,
        float $amount,
        string $method,
        string $transaction_id = '',
        float $cc_fee = 0
    ): bool {
        $invoice = pods('invoice', $invoice_id);

        if (!$invoice || !$invoice->exists()) {
            Logger::error('Stripe', 'Record payment failed - invoice not found', [
                'invoice_id' => $invoice_id,
            ]);
            return false;
        }

        $current_paid = floatval($invoice->field('amount_paid'));
        $invoice_amount = floatval($invoice->field('amount'));
        $new_paid = $current_paid + $amount;

        // Determine new status
        $new_status = ($new_paid >= $invoice_amount) ? 'Paid' : 'Partial';

        // Determine payment method label
        $method_labels = [
            'stripe' => 'Credit Card',
            'ach' => 'ACH',
            'zelle' => 'Zelle',
        ];
        $payment_method_label = $method_labels[$method] ?? $method;

        // Prepare update data
        $update_data = [
            'amount_paid' => $new_paid,
            'invoice_status' => $new_status,
            'payment_method' => $payment_method_label,
        ];

        if ($new_status === 'Paid') {
            $update_data['paid_date'] = current_time('Y-m-d');
        }

        if (!empty($transaction_id)) {
            $update_data['stripe_payment_intent'] = $transaction_id;
        }

        if ($cc_fee > 0) {
            $update_data['cc_fee_amount'] = $cc_fee;
        }

        $invoice->save($update_data);

        $invoice_number = $invoice->field('invoice_number');
        Logger::info('Billing', 'Payment recorded', [
            'invoice_id' => $invoice_id,
            'invoice_number' => $invoice_number,
            'amount' => $amount,
            'method' => $payment_method_label,
            'new_status' => $new_status,
        ]);

        // Send confirmation email to admin
        $this->sendPaymentNotification($invoice_id, $invoice_number, $amount, $payment_method_label, $new_status);

        return true;
    }

    /**
     * Send payment confirmation email to admin.
     *
     * @param int    $invoice_id    Invoice post ID.
     * @param string $invoice_number Invoice number.
     * @param float  $amount        Payment amount.
     * @param string $method        Payment method label.
     * @param string $new_status    New invoice status.
     */
    private function sendPaymentNotification(
        int $invoice_id,
        string $invoice_number,
        float $amount,
        string $method,
        string $new_status
    ): void {
        $org_id = get_post_meta($invoice_id, 'organization', true);
        $org_name = $org_id ? get_the_title((int) $org_id) : 'Unknown';

        $to = get_option('admin_email');
        $subject = "Payment Received - {$invoice_number}";

        $message = "Payment received for invoice {$invoice_number}\n\n";
        $message .= "Client: {$org_name}\n";
        $message .= "Amount: $" . number_format($amount, 2) . "\n";
        $message .= "Method: {$method}\n";
        $message .= "New Status: {$new_status}\n\n";
        $message .= "Invoice: " . admin_url("post.php?post={$invoice_id}&action=edit");

        wp_mail($to, $subject, $message);
    }

    /**
     * AJAX handler: Create checkout session.
     */
    public function handleCreateCheckout(): void {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bbab_payment_nonce')) {
            wp_send_json_error(['message' => 'Security check failed.']);
            return;
        }

        $invoice_id = absint($_POST['invoice_id'] ?? 0);
        $payment_method = sanitize_text_field($_POST['payment_method'] ?? 'card');

        if (!$invoice_id) {
            wp_send_json_error(['message' => 'Invalid invoice.']);
            return;
        }

        // Verify user has access to this invoice
        $user_id = get_current_user_id();
        $user_org = get_user_meta($user_id, 'organization', true);
        $invoice_org = get_post_meta($invoice_id, 'organization', true);

        if (!current_user_can('manage_options') && $user_org != $invoice_org) {
            wp_send_json_error(['message' => 'Access denied.']);
            return;
        }

        $session = $this->createCheckoutSession($invoice_id, $payment_method);

        if (is_wp_error($session)) {
            wp_send_json_error(['message' => $session->get_error_message()]);
            return;
        }

        wp_send_json_success(['checkout_url' => $session['url'] ?? '']);
    }

    /**
     * AJAX handler: Zelle payment notification.
     */
    public function handleZelleNotification(): void {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bbab_payment_nonce')) {
            wp_send_json_error(['message' => 'Security check failed.']);
            return;
        }

        $invoice_id = absint($_POST['invoice_id'] ?? 0);

        if (!$invoice_id) {
            wp_send_json_error(['message' => 'Invalid invoice.']);
            return;
        }

        // Verify user has access to this invoice
        $user_id = get_current_user_id();
        $user_org = get_user_meta($user_id, 'organization', true);
        $invoice_org = get_post_meta($invoice_id, 'organization', true);

        if (!current_user_can('manage_options') && $user_org != $invoice_org) {
            wp_send_json_error(['message' => 'Access denied.']);
            return;
        }

        $invoice_number = get_post_meta($invoice_id, 'invoice_number', true);
        $org_name = $invoice_org ? get_the_title((int) $invoice_org) : 'Unknown';

        // Send notification email to Brad
        $to = get_option('admin_email');
        $subject = "Zelle Payment Notification - {$invoice_number}";

        $message = "Client {$org_name} has indicated they've sent a Zelle payment for invoice {$invoice_number}.\n\n";
        $message .= "Please verify the payment in your Zelle account and mark the invoice as paid.\n\n";
        $message .= "Invoice: " . admin_url("post.php?post={$invoice_id}&action=edit");

        $sent = wp_mail($to, $subject, $message);

        if ($sent) {
            Logger::info('Billing', 'Zelle notification sent', [
                'invoice_id' => $invoice_id,
                'org' => $org_name,
            ]);
            wp_send_json_success(['message' => 'Notification sent.']);
        } else {
            wp_send_json_error(['message' => 'Failed to send notification.']);
        }
    }
}
