<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Billing;

use BBAB\ServiceCenter\Utils\Settings;
use BBAB\ServiceCenter\Utils\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Stripe Webhook Handler.
 *
 * Handles incoming Stripe webhooks for payment processing.
 * Registers a REST API endpoint at /wp-json/bbab/v1/stripe-webhook
 *
 * Migrated from snippet: 2136
 */
class StripeWebhook {

    /**
     * Stripe service instance.
     */
    private StripeService $stripe_service;

    /**
     * Constructor.
     *
     * @param StripeService $stripe_service Stripe service instance.
     */
    public function __construct(StripeService $stripe_service) {
        $this->stripe_service = $stripe_service;
    }

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action('rest_api_init', [$this, 'registerEndpoint']);
    }

    /**
     * Register the webhook REST endpoint.
     */
    public function registerEndpoint(): void {
        register_rest_route('bbab/v1', '/stripe-webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handleWebhook'],
            'permission_callback' => '__return_true', // Stripe needs unauthenticated access
        ]);
    }

    /**
     * Handle incoming Stripe webhook.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response Response object.
     */
    public function handleWebhook(WP_REST_Request $request): WP_REST_Response {
        $payload = $request->get_body();
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        Logger::debug('Stripe', 'Webhook received', [
            'payload_length' => strlen($payload),
        ]);

        // Parse the event
        $event = json_decode($payload, true);

        if (!$event || !isset($event['type'])) {
            Logger::error('Stripe', 'Webhook invalid payload');
            return new WP_REST_Response(['error' => 'Invalid payload'], 400);
        }

        // Verify webhook signature if secret is configured
        $webhook_secret = Settings::getStripeWebhookSecret();

        if (!empty($webhook_secret)) {
            $verification = $this->verifySignature($payload, $sig_header, $webhook_secret);

            if (is_wp_error($verification)) {
                Logger::error('Stripe', 'Webhook signature verification failed', [
                    'error' => $verification->get_error_message(),
                ]);
                return new WP_REST_Response(['error' => $verification->get_error_message()], 400);
            }
        } else {
            Logger::warning('Stripe', 'Webhook secret not configured - skipping signature verification');
        }

        // Handle the event
        switch ($event['type']) {
            case 'checkout.session.completed':
                $this->handleCheckoutCompleted($event['data']['object']);
                break;

            case 'payment_intent.succeeded':
                $this->handlePaymentSucceeded($event['data']['object']);
                break;

            default:
                Logger::debug('Stripe', 'Unhandled webhook event type', [
                    'type' => $event['type'],
                ]);
        }

        return new WP_REST_Response(['received' => true], 200);
    }

    /**
     * Verify the Stripe webhook signature.
     *
     * @param string $payload       Raw request body.
     * @param string $sig_header    Stripe-Signature header value.
     * @param string $webhook_secret Webhook signing secret.
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    private function verifySignature(string $payload, string $sig_header, string $webhook_secret) {
        if (empty($sig_header)) {
            return new \WP_Error('missing_signature', 'Missing Stripe signature header');
        }

        $timestamp = '';
        $signature = '';

        // Parse signature header
        foreach (explode(',', $sig_header) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                if ($pair[0] === 't') {
                    $timestamp = $pair[1];
                }
                if ($pair[0] === 'v1') {
                    $signature = $pair[1];
                }
            }
        }

        if (empty($timestamp) || empty($signature)) {
            return new \WP_Error('invalid_signature', 'Invalid signature format');
        }

        // Verify signature
        $signed_payload = $timestamp . '.' . $payload;
        $expected_sig = hash_hmac('sha256', $signed_payload, $webhook_secret);

        if (!hash_equals($expected_sig, $signature)) {
            return new \WP_Error('invalid_signature', 'Signature verification failed');
        }

        // Check timestamp (reject if older than 5 minutes)
        if (abs(time() - intval($timestamp)) > 300) {
            return new \WP_Error('timestamp_expired', 'Webhook timestamp too old');
        }

        return true;
    }

    /**
     * Handle checkout.session.completed event.
     *
     * @param array $session Checkout session data.
     */
    private function handleCheckoutCompleted(array $session): void {
        Logger::info('Stripe', 'Processing checkout.session.completed', [
            'session_id' => $session['id'] ?? 'unknown',
        ]);

        $invoice_id = (int) ($session['metadata']['invoice_id'] ?? 0);
        $cc_fee = floatval($session['metadata']['cc_fee'] ?? 0);
        $payment_intent = $session['payment_intent'] ?? '';

        if (!$invoice_id) {
            Logger::error('Stripe', 'No invoice_id in session metadata');
            return;
        }

        // Prevent duplicate processing with transient lock
        $lock_key = 'bbab_stripe_payment_' . $invoice_id . '_' . $payment_intent;
        if (get_transient($lock_key)) {
            Logger::debug('Stripe', 'Payment already being processed (lock exists)', [
                'invoice_id' => $invoice_id,
            ]);
            return;
        }
        set_transient($lock_key, true, 300); // 5 minute lock

        // Get payment amount (convert from cents, subtract CC fee to get invoice amount)
        $amount_total = floatval($session['amount_total'] ?? 0) / 100;
        $amount_paid = $amount_total - $cc_fee;

        $this->stripe_service->recordPayment(
            $invoice_id,
            $amount_paid,
            'stripe',
            $payment_intent,
            $cc_fee
        );
    }

    /**
     * Handle payment_intent.succeeded event (backup handler).
     *
     * @param array $payment_intent Payment intent data.
     */
    private function handlePaymentSucceeded(array $payment_intent): void {
        Logger::info('Stripe', 'Processing payment_intent.succeeded', [
            'payment_intent_id' => $payment_intent['id'] ?? 'unknown',
        ]);

        $invoice_id = (int) ($payment_intent['metadata']['invoice_id'] ?? 0);
        $intent_id = $payment_intent['id'] ?? '';

        if (!$invoice_id) {
            Logger::debug('Stripe', 'No invoice_id in payment_intent metadata');
            return;
        }

        // Check transient lock first (faster than DB lookup)
        $lock_key = 'bbab_stripe_payment_' . $invoice_id . '_' . $intent_id;
        if (get_transient($lock_key)) {
            Logger::debug('Stripe', 'Payment already processed (lock exists)', [
                'invoice_id' => $invoice_id,
            ]);
            return;
        }

        // Also check if already processed via checkout.session.completed
        $existing_intent = get_post_meta($invoice_id, 'stripe_payment_intent', true);
        if ($existing_intent === $intent_id) {
            Logger::debug('Stripe', 'Payment already processed via checkout.session.completed');
            return;
        }

        // Set lock before processing
        set_transient($lock_key, true, 300); // 5 minute lock

        // Get payment amount (convert from cents)
        $amount_paid = floatval($payment_intent['amount_received'] ?? 0) / 100;

        $this->stripe_service->recordPayment(
            $invoice_id,
            $amount_paid,
            'stripe',
            $intent_id,
            0
        );
    }
}
