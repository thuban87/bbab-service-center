<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\ServiceRequests;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Service Request reference number generator.
 *
 * Generates sequential reference numbers in SR-0001 format.
 * Auto-generates on new service request creation and updates post title.
 *
 * Migrated from: WPCode Snippet #1715
 */
class ReferenceGenerator {

    /**
     * Reference number prefix.
     */
    public const PREFIX = 'SR-';

    /**
     * Number of digits to pad (e.g., 4 = SR-0001).
     */
    public const PAD_LENGTH = 4;

    /**
     * Register hooks for reference number generation.
     */
    public static function register(): void {
        // Generate reference number on new SR creation
        add_action('save_post_service_request', [self::class, 'handleSave'], 10, 3);

        Logger::debug('ReferenceGenerator', 'Registered reference generator hooks');
    }

    /**
     * Handle service request save - generate reference number if new.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update.
     */
    public static function handleSave(int $post_id, \WP_Post $post, bool $update): void {
        // Skip autosaves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Only run on new posts, not updates
        if ($update) {
            return;
        }

        // Check if reference number already exists
        $existing = get_post_meta($post_id, 'reference_number', true);
        if (!empty($existing)) {
            return;
        }

        // Generate and save reference number
        $ref = self::generate();
        update_post_meta($post_id, 'reference_number', $ref);

        Logger::debug('ReferenceGenerator', "Generated reference {$ref} for SR {$post_id}");

        // Update post title to include reference number
        self::updatePostTitle($post_id, $ref);
    }

    /**
     * Generate the next reference number.
     *
     * @return string Reference number in SR-0001 format.
     */
    public static function generate(): string {
        $next_number = self::getNextNumber();

        return self::PREFIX . str_pad((string) $next_number, self::PAD_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Get the next sequential number.
     *
     * Queries the database for the highest existing SR number and returns +1.
     *
     * @return int Next number to use.
     */
    public static function getNextNumber(): int {
        global $wpdb;

        // Query for highest existing SR number
        // Uses SUBSTRING to extract the numeric portion after "SR-"
        $last = $wpdb->get_var("
            SELECT meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = 'reference_number'
            AND meta_value LIKE 'SR-%'
            ORDER BY CAST(SUBSTRING(meta_value, 4) AS UNSIGNED) DESC
            LIMIT 1
        ");

        if ($last) {
            // Extract number portion (after "SR-")
            $num = intval(substr($last, 3)) + 1;
        } else {
            // First SR in system
            $num = 1;
        }

        return $num;
    }

    /**
     * Update post title to include reference number and subject.
     *
     * Format: "SR-0001 - Subject Line"
     *
     * @param int    $post_id Post ID.
     * @param string $ref     Reference number.
     */
    private static function updatePostTitle(int $post_id, string $ref): void {
        $subject = get_post_meta($post_id, 'subject', true);

        if (empty($subject)) {
            // No subject yet - might be set by form processor after
            // We'll update with just the ref for now
            $new_title = $ref;
        } else {
            $new_title = $ref . ' - ' . $subject;
        }

        // Unhook to prevent infinite loop
        remove_action('save_post_service_request', [self::class, 'handleSave'], 10);

        wp_update_post([
            'ID' => $post_id,
            'post_title' => $new_title,
        ]);

        // Re-hook
        add_action('save_post_service_request', [self::class, 'handleSave'], 10, 3);

        Logger::debug('ReferenceGenerator', "Updated post title to: {$new_title}");
    }

    /**
     * Regenerate title for an existing SR (utility method).
     *
     * Useful if subject was updated after initial creation.
     *
     * @param int $post_id Post ID.
     * @return bool True on success.
     */
    public static function regenerateTitle(int $post_id): bool {
        $ref = get_post_meta($post_id, 'reference_number', true);
        $subject = get_post_meta($post_id, 'subject', true);

        if (empty($ref)) {
            return false;
        }

        $new_title = !empty($subject) ? $ref . ' - ' . $subject : $ref;

        $result = wp_update_post([
            'ID' => $post_id,
            'post_title' => $new_title,
        ]);

        return !is_wp_error($result);
    }

    /**
     * Parse a reference number to extract the numeric portion.
     *
     * @param string $ref Reference number (e.g., "SR-0042").
     * @return int|null The number, or null if invalid format.
     */
    public static function parseNumber(string $ref): ?int {
        if (strpos($ref, self::PREFIX) !== 0) {
            return null;
        }

        $num_str = substr($ref, strlen(self::PREFIX));

        if (!is_numeric($num_str)) {
            return null;
        }

        return intval($num_str);
    }
}
