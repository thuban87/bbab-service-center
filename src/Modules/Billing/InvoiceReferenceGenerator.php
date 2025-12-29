<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Billing;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Generates invoice reference numbers in BBB-YYMM-XXX format.
 *
 * Format: BBB-YYMM-XXX
 * - BBB: Brad's Bits and Bytes prefix
 * - YYMM: Year and month (e.g., 2412 for December 2024)
 * - XXX: Sequential number within that month (001, 002, etc.)
 *
 * Example: BBB-2412-001, BBB-2412-002
 *
 * Migrated from: WPCode Snippet #1995
 */
class InvoiceReferenceGenerator {

    /**
     * Register hooks.
     */
    public static function register(): void {
        // Early hook - fires when WordPress creates the post (including auto-draft)
        add_action('wp_insert_post', [self::class, 'handleInsert'], 10, 3);

        // Generate reference on Pods save (backup - runs after meta is saved)
        add_action('pods_api_post_save_pod_item_invoice', [self::class, 'maybeGenerateReference'], 10, 3);

        // AJAX handler to preview next invoice number
        add_action('wp_ajax_bbab_preview_invoice_number', [self::class, 'ajaxPreviewNumber']);

        Logger::debug('InvoiceReferenceGenerator', 'Registered invoice reference hooks');
    }

    /**
     * Handle WordPress post insert - generate ref for new invoices immediately.
     *
     * This fires when the editor first loads (creating auto-draft), so the
     * reference number is visible before the user saves.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update.
     */
    public static function handleInsert(int $post_id, \WP_Post $post, bool $update): void {
        // Only for invoices
        if ($post->post_type !== 'invoice') {
            return;
        }

        // Only for new posts, not updates
        if ($update) {
            return;
        }

        // Skip if already has a reference
        $existing = get_post_meta($post_id, 'invoice_number', true);
        if (!empty($existing)) {
            return;
        }

        // Generate using current date (invoice_date not available yet)
        $reference = self::generateNumber(current_time('Y-m-d'));
        update_post_meta($post_id, 'invoice_number', $reference);

        Logger::debug('InvoiceReferenceGenerator', 'Generated invoice reference (on insert)', [
            'post_id' => $post_id,
            'reference' => $reference,
        ]);
    }

    /**
     * Generate reference number if not already set (from Pods hook - backup).
     *
     * Uses Pods hook which runs AFTER meta fields are saved.
     *
     * @param mixed $pieces      Pods save pieces (can be null).
     * @param mixed $is_new_item Whether this is a new item.
     * @param mixed $id          Post ID (may come as string from Pods).
     */
    public static function maybeGenerateReference($pieces, $is_new_item, $id): void {
        $id = (int) $id;
        if ($id <= 0) {
            return;
        }
        // Check if already has reference number
        $existing = get_post_meta($id, 'invoice_number', true);
        if (!empty($existing)) {
            return;
        }

        // Get invoice date (for YYMM portion) - now available since Pods saved it
        $invoice_date = get_post_meta($id, 'invoice_date', true);
        if (empty($invoice_date)) {
            $invoice_date = current_time('Y-m-d');
        }

        // Generate and save reference
        $reference = self::generateNumber($invoice_date);
        update_post_meta($id, 'invoice_number', $reference);

        Logger::debug('InvoiceReferenceGenerator', 'Generated invoice reference (from Pods)', [
            'post_id' => $id,
            'reference' => $reference,
        ]);
    }

    /**
     * Generate next invoice number in BBB-YYMM-NNN format.
     *
     * @param string|null $invoice_date Date string (any format parseable by strtotime).
     * @return string Next invoice number.
     */
    public static function generateNumber(?string $invoice_date = null): string {
        // Default to today if no date provided
        if (empty($invoice_date)) {
            $invoice_date = current_time('Y-m-d');
        }

        // Parse the date to get YYMM
        $timestamp = strtotime($invoice_date);
        if ($timestamp === false) {
            $timestamp = current_time('timestamp');
        }

        $yymm = date('ym', $timestamp); // e.g., "2512" for December 2025
        $prefix = 'BBB-' . $yymm . '-';

        // Find highest existing number for this month
        global $wpdb;

        $highest = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = 'invoice_number'
            AND pm.meta_value LIKE %s
            AND p.post_type = 'invoice'
            AND p.post_status != 'trash'
            ORDER BY pm.meta_value DESC
            LIMIT 1",
            $prefix . '%'
        ));

        // Extract the sequence number and increment
        if ($highest) {
            // Get the NNN part (last 3 characters after final dash)
            $parts = explode('-', $highest);
            $last_num = (int) end($parts);
            $next_num = $last_num + 1;
        } else {
            $next_num = 1;
        }

        // Format with leading zeros (001, 002, etc.)
        return $prefix . str_pad((string) $next_num, 3, '0', STR_PAD_LEFT);
    }

    /**
     * AJAX handler to preview next invoice number.
     */
    public static function ajaxPreviewNumber(): void {
        check_ajax_referer('bbab_invoice_nonce', 'nonce');

        $date = isset($_POST['invoice_date']) ? sanitize_text_field($_POST['invoice_date']) : null;
        $number = self::generateNumber($date);

        wp_send_json_success(['invoice_number' => $number]);
    }

    /**
     * Parse an existing invoice number to get components.
     *
     * @param string $invoice_number Full invoice number.
     * @return array|null Array with 'prefix', 'yymm', 'sequence' or null if invalid.
     */
    public static function parseNumber(string $invoice_number): ?array {
        // Expected format: BBB-YYMM-XXX
        if (!preg_match('/^(BBB)-(\d{4})-(\d{3})$/', $invoice_number, $matches)) {
            return null;
        }

        return [
            'prefix' => $matches[1],
            'yymm' => $matches[2],
            'sequence' => (int) $matches[3],
        ];
    }

    /**
     * Get the month/year display from an invoice number.
     *
     * @param string $invoice_number Full invoice number.
     * @return string Month/Year display (e.g., "December 2024") or empty string.
     */
    public static function getMonthYearFromNumber(string $invoice_number): string {
        $parts = self::parseNumber($invoice_number);
        if (!$parts) {
            return '';
        }

        $yymm = $parts['yymm'];
        $year = '20' . substr($yymm, 0, 2);
        $month = substr($yymm, 2, 2);

        $timestamp = mktime(0, 0, 0, (int) $month, 1, (int) $year);
        if ($timestamp === false) {
            return '';
        }

        return date('F Y', $timestamp);
    }
}
