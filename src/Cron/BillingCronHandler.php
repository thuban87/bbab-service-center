<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Cron;

use BBAB\ServiceCenter\Utils\Logger;
use BBAB\ServiceCenter\Utils\Settings;
use WP_Error;

/**
 * Billing Cron Handler.
 *
 * Handles daily billing tasks:
 * - Marking invoices as overdue
 * - Applying late fees after grace period
 * - Regenerating PDFs when late fees are applied
 *
 * Scheduled to run daily at 4:00 AM Chicago time.
 *
 * Migrated from snippet: 2207
 */
class BillingCronHandler {

    /**
     * Cron event hook name.
     */
    public const CRON_HOOK = 'bbab_sc_billing_cron';

    /**
     * Grace period days before late fee applies.
     */
    private const LATE_FEE_GRACE_DAYS = 7;

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action(self::CRON_HOOK, [$this, 'run']);

        // Manual trigger for testing (admin only)
        add_action('admin_init', [$this, 'handleManualTrigger']);
    }

    /**
     * Run the daily billing cron job.
     */
    public function run(): void {
        Logger::info('Cron', 'Billing cron started');

        // 1. Mark overdue invoices
        $overdue_count = $this->markOverdueInvoices();

        // 2. Apply late fees to invoices 7+ days overdue
        $late_fee_count = $this->applyLateFees();

        // 3. Log completion
        update_option('bbab_billing_cron_last_run', current_time('mysql'));

        Logger::info('Cron', 'Billing cron completed', [
            'overdue_marked' => $overdue_count,
            'late_fees_applied' => $late_fee_count,
        ]);
    }

    /**
     * Mark invoices as Overdue if past due date.
     *
     * @return int Number of invoices marked overdue.
     */
    public function markOverdueInvoices(): int {
        $today = current_time('Y-m-d');

        // Query invoices that should be marked overdue
        $invoices = get_posts([
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'invoice_status',
                    'value' => ['Pending', 'Partial'],
                    'compare' => 'IN',
                ],
                [
                    'key' => 'due_date',
                    'value' => $today,
                    'compare' => '<',
                    'type' => 'DATE',
                ],
            ],
        ]);

        foreach ($invoices as $invoice) {
            update_post_meta($invoice->ID, 'invoice_status', 'Overdue');

            Logger::debug('Billing', 'Invoice marked overdue', [
                'invoice_id' => $invoice->ID,
            ]);
        }

        return count($invoices);
    }

    /**
     * Apply late fees to invoices that are 7+ days overdue.
     *
     * @return int Number of late fees applied.
     */
    public function applyLateFees(): int {
        $grace_period_date = date('Y-m-d', strtotime('-' . self::LATE_FEE_GRACE_DAYS . ' days'));

        // Query overdue invoices that are 7+ days past due
        $invoices = get_posts([
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'invoice_status',
                    'value' => 'Overdue',
                    'compare' => '=',
                ],
                [
                    'key' => 'due_date',
                    'value' => $grace_period_date,
                    'compare' => '<=',
                    'type' => 'DATE',
                ],
            ],
        ]);

        $fees_applied = 0;

        foreach ($invoices as $invoice) {
            // Check if late fee already exists for this invoice
            $existing_late_fee = get_posts([
                'post_type' => 'invoice_line_item',
                'posts_per_page' => 1,
                'post_status' => 'publish',
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => 'related_invoice',
                        'value' => $invoice->ID,
                        'compare' => '=',
                    ],
                    [
                        'key' => 'line_type',
                        'value' => 'Late Fee',
                        'compare' => '=',
                    ],
                ],
            ]);

            if (!empty($existing_late_fee)) {
                continue; // Already has late fee, skip
            }

            // Apply late fee
            $result = $this->addLateFee($invoice->ID);

            if ($result && !is_wp_error($result)) {
                $fees_applied++;
                Logger::info('Billing', 'Late fee applied', [
                    'invoice_id' => $invoice->ID,
                ]);
            }
        }

        return $fees_applied;
    }

    /**
     * Add a late fee line item to an invoice.
     *
     * @param int $invoice_id Invoice post ID.
     * @return int|WP_Error Line item ID or error.
     */
    public function addLateFee(int $invoice_id) {
        // Get invoice data
        $amount = floatval(get_post_meta($invoice_id, 'amount', true));
        $amount_paid = floatval(get_post_meta($invoice_id, 'amount_paid', true));
        $invoice_number = get_post_meta($invoice_id, 'invoice_number', true);

        // Get org's late fee percentage (default 5%)
        $org_id = get_post_meta($invoice_id, 'organization', true);
        $late_fee_percentage = 5.0; // Default

        if ($org_id) {
            $org_percentage = get_post_meta((int) $org_id, 'late_fee_percentage', true);
            if ($org_percentage !== '' && $org_percentage !== false) {
                $late_fee_percentage = floatval($org_percentage);
            }
        }

        // Calculate outstanding balance and late fee
        $outstanding = $amount - $amount_paid;
        $late_fee_amount = round($outstanding * ($late_fee_percentage / 100), 2);

        if ($late_fee_amount <= 0) {
            return new WP_Error('no_fee', 'No late fee to apply (balance is zero or negative)');
        }

        // Get next display order
        $existing_items = get_posts([
            'post_type' => 'invoice_line_item',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'related_invoice',
                'value' => $invoice_id,
                'compare' => '=',
            ]],
        ]);
        $next_order = count($existing_items) + 1;

        // Create late fee line item
        $line_item_id = wp_insert_post([
            'post_type' => 'invoice_line_item',
            'post_status' => 'publish',
            'post_title' => $invoice_number . ' - Late Fee - $' . number_format($late_fee_amount, 2),
        ]);

        if (is_wp_error($line_item_id)) {
            return $line_item_id;
        }

        // Set line item meta
        update_post_meta($line_item_id, 'related_invoice', $invoice_id);
        update_post_meta($line_item_id, 'line_type', 'Late Fee');
        update_post_meta($line_item_id, 'description', $late_fee_percentage . '% late fee on $' . number_format($outstanding, 2) . ' outstanding');
        update_post_meta($line_item_id, 'quantity', 1);
        update_post_meta($line_item_id, 'rate', $late_fee_amount);
        update_post_meta($line_item_id, 'amount', $late_fee_amount);
        update_post_meta($line_item_id, 'display_order', $next_order);

        // Update invoice total
        $new_amount = $amount + $late_fee_amount;
        update_post_meta($invoice_id, 'amount', $new_amount);
        update_post_meta($invoice_id, 'late_fee_amount', $late_fee_amount);

        // Clear all caches to ensure fresh data for PDF
        clean_post_cache($invoice_id);
        clean_post_cache($line_item_id);
        wp_cache_flush();

        // Regenerate PDF with late fee included
        if (function_exists('bbab_generate_invoice_pdf')) {
            // Delay to ensure database commits are complete
            sleep(1);

            $pdf_result = bbab_generate_invoice_pdf($invoice_id);

            if (is_wp_error($pdf_result)) {
                Logger::error('Billing', 'PDF regeneration failed after late fee', [
                    'invoice_id' => $invoice_id,
                    'error' => $pdf_result->get_error_message(),
                ]);
            } else {
                Logger::debug('Billing', 'PDF regenerated with late fee', [
                    'invoice_id' => $invoice_id,
                ]);
            }
        }

        return $line_item_id;
    }

    /**
     * Handle manual trigger for testing.
     */
    public function handleManualTrigger(): void {
        if (isset($_GET['bbab_run_billing_cron']) && current_user_can('manage_options')) {
            $this->run();
            wp_die('Billing cron executed. Check your invoices and debug log.');
        }
    }

    /**
     * Get the last run timestamp.
     *
     * @return string|null Last run timestamp or null.
     */
    public static function getLastRun(): ?string {
        return get_option('bbab_billing_cron_last_run', null);
    }
}
