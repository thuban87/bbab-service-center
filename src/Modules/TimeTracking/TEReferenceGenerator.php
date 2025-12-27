<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\TimeTracking;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Time Entry reference number generator.
 *
 * Generates sequential reference numbers in TE-0001 format.
 * Auto-generates on new time entry creation and updates post title.
 *
 * Migrated from: WPCode Snippet #1109 (Time Entry Reference Number section)
 */
class TEReferenceGenerator {

    /**
     * Reference number prefix.
     */
    public const PREFIX = 'TE-';

    /**
     * Number of digits to pad (e.g., 4 = TE-0001).
     */
    public const PAD_LENGTH = 4;

    /**
     * Register hooks for reference number generation.
     */
    public static function register(): void {
        // Generate reference number on new TE creation
        add_action('save_post_time_entry', [self::class, 'handleSave'], 5, 3);

        // AJAX handler for backfill
        add_action('wp_ajax_bbab_sc_backfill_te_refs', [self::class, 'handleBackfillAjax']);

        // AJAX handler for cleanup of orphaned te_reference values
        add_action('wp_ajax_bbab_sc_cleanup_te_refs', [self::class, 'handleCleanupAjax']);

        Logger::debug('TEReferenceGenerator', 'Registered TE reference generator hooks');
    }

    /**
     * Handle time entry save - generate reference number if new.
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

        Logger::debug('TEReferenceGenerator', "Generated reference {$ref} for TE {$post_id}");

        // Update post title to include reference number
        self::updatePostTitle($post_id, $ref);
    }

    /**
     * Generate the next reference number.
     *
     * @return string Reference number in TE-0001 format.
     */
    public static function generate(): string {
        $next_number = self::getNextNumber();

        return self::PREFIX . str_pad((string) $next_number, self::PAD_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Get the next sequential number.
     *
     * Queries the database for the highest existing TE number and returns +1.
     *
     * @return int Next number to use.
     */
    public static function getNextNumber(): int {
        global $wpdb;

        // Query for highest existing TE number
        // Uses SUBSTRING to extract the numeric portion after "TE-"
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $last = $wpdb->get_var("
            SELECT meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = 'reference_number'
            AND meta_value LIKE 'TE-%'
            ORDER BY CAST(SUBSTRING(meta_value, 4) AS UNSIGNED) DESC
            LIMIT 1
        ");
        // phpcs:enable

        if ($last) {
            // Extract number portion (after "TE-")
            $num = intval(substr($last, 3)) + 1;
        } else {
            // First TE in system
            $num = 1;
        }

        return $num;
    }

    /**
     * Update post title to include reference number and description.
     *
     * Format: "TE-0001 - Description"
     *
     * @param int    $post_id Post ID.
     * @param string $ref     Reference number.
     */
    private static function updatePostTitle(int $post_id, string $ref): void {
        $description = get_post_meta($post_id, 'description', true);

        if (empty($description)) {
            // No description yet - use just the ref
            $new_title = $ref;
        } else {
            // Truncate description for title
            $desc_short = strlen($description) > 50 ? substr($description, 0, 50) . '...' : $description;
            $new_title = $ref . ' - ' . $desc_short;
        }

        // Unhook to prevent infinite loop
        remove_action('save_post_time_entry', [self::class, 'handleSave'], 5);

        wp_update_post([
            'ID' => $post_id,
            'post_title' => $new_title,
        ]);

        // Re-hook
        add_action('save_post_time_entry', [self::class, 'handleSave'], 5, 3);

        Logger::debug('TEReferenceGenerator', "Updated post title to: {$new_title}");
    }

    /**
     * Regenerate title for an existing TE (utility method).
     *
     * @param int $post_id Post ID.
     * @return bool True on success.
     */
    public static function regenerateTitle(int $post_id): bool {
        $ref = get_post_meta($post_id, 'reference_number', true);
        $description = get_post_meta($post_id, 'description', true);

        if (empty($ref)) {
            return false;
        }

        if (!empty($description)) {
            $desc_short = strlen($description) > 50 ? substr($description, 0, 50) . '...' : $description;
            $new_title = $ref . ' - ' . $desc_short;
        } else {
            $new_title = $ref;
        }

        $result = wp_update_post([
            'ID' => $post_id,
            'post_title' => $new_title,
        ]);

        return !is_wp_error($result);
    }

    /**
     * Parse a reference number to extract the numeric portion.
     *
     * @param string $ref Reference number (e.g., "TE-0042").
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

    /**
     * AJAX handler for backfill action.
     */
    public static function handleBackfillAjax(): void {
        // Verify nonce
        if (!check_ajax_referer('bbab_sc_backfill_te_refs', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token.']);
        }

        // Verify permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $result = self::backfillAll();

        wp_send_json_success($result);
    }

    /**
     * Backfill reference numbers for all TEs that don't have one.
     *
     * Assigns numbers in chronological order (oldest TE first).
     *
     * @return array Result with counts and details.
     */
    public static function backfillAll(): array {
        $entries_without_ref = self::getEntriesWithoutReference();
        $count = count($entries_without_ref);

        if ($count === 0) {
            Logger::debug('TEReferenceGenerator', 'Backfill: No TEs without references found');
            return [
                'processed' => 0,
                'message' => 'All time entries already have reference numbers.',
            ];
        }

        Logger::debug('TEReferenceGenerator', "Backfill: Found {$count} TEs without references");

        $processed = 0;
        $errors = [];

        foreach ($entries_without_ref as $entry) {
            $post_id = $entry->ID;

            // Generate next reference number
            $ref = self::generate();

            // Save reference
            $updated = update_post_meta($post_id, 'reference_number', $ref);

            if ($updated) {
                // Update post title
                self::updatePostTitleDirect($post_id, $ref);
                $processed++;
                Logger::debug('TEReferenceGenerator', "Backfill: Assigned {$ref} to TE {$post_id}");
            } else {
                $errors[] = "Failed to update TE {$post_id}";
                Logger::error('TEReferenceGenerator', "Backfill: Failed to assign ref to TE {$post_id}");
            }
        }

        $message = "Assigned reference numbers to {$processed} time entries.";
        if (!empty($errors)) {
            $message .= ' Errors: ' . count($errors);
        }

        Logger::debug('TEReferenceGenerator', "Backfill complete: {$processed}/{$count} processed");

        return [
            'processed' => $processed,
            'total' => $count,
            'errors' => $errors,
            'message' => $message,
        ];
    }

    /**
     * Get all time entries without a reference number, ordered by date (oldest first).
     *
     * @return array Array of WP_Post objects.
     */
    public static function getEntriesWithoutReference(): array {
        global $wpdb;

        // Get IDs of TEs that don't have reference_number meta or have empty value
        $ids = $wpdb->get_col("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'reference_number'
            WHERE p.post_type = 'time_entry'
            AND p.post_status IN ('publish', 'draft', 'private')
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
            ORDER BY p.post_date ASC
        ");

        if (empty($ids)) {
            return [];
        }

        return get_posts([
            'post_type' => 'time_entry',
            'post__in' => array_map('intval', $ids),
            'posts_per_page' => -1,
            'orderby' => 'post__in', // Preserve the chronological order from query
            'post_status' => ['publish', 'draft', 'private'],
        ]);
    }

    /**
     * Get count of TEs without references.
     *
     * @return int Count.
     */
    public static function getCountWithoutReference(): int {
        global $wpdb;

        return (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'reference_number'
            WHERE p.post_type = 'time_entry'
            AND p.post_status IN ('publish', 'draft', 'private')
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ");
    }

    /**
     * Update post title directly without triggering save hooks.
     *
     * @param int    $post_id Post ID.
     * @param string $ref     Reference number.
     */
    private static function updatePostTitleDirect(int $post_id, string $ref): void {
        global $wpdb;

        $description = get_post_meta($post_id, 'description', true);

        if (empty($description)) {
            $new_title = $ref;
        } else {
            $desc_short = strlen($description) > 50 ? substr($description, 0, 50) . '...' : $description;
            $new_title = $ref . ' - ' . $desc_short;
        }

        // Direct update to avoid triggering hooks
        $wpdb->update(
            $wpdb->posts,
            ['post_title' => $new_title],
            ['ID' => $post_id],
            ['%s'],
            ['%d']
        );

        // Clear cache for this post
        clean_post_cache($post_id);
    }

    /**
     * AJAX handler for cleanup of orphaned te_reference meta.
     */
    public static function handleCleanupAjax(): void {
        // Verify nonce
        if (!check_ajax_referer('bbab_sc_cleanup_te_refs', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token.']);
        }

        // Verify permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $result = self::cleanupOrphanedTeReference();

        wp_send_json_success($result);
    }

    /**
     * Delete orphaned te_reference meta values.
     *
     * These were incorrectly created and should be removed.
     *
     * @return array Result with count.
     */
    public static function cleanupOrphanedTeReference(): array {
        global $wpdb;

        // Count how many we're about to delete
        $count = (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'te_reference'
        ");

        if ($count === 0) {
            return [
                'deleted' => 0,
                'message' => 'No orphaned te_reference values found.',
            ];
        }

        // Delete all te_reference meta (it was incorrectly created)
        $deleted = $wpdb->query("
            DELETE FROM {$wpdb->postmeta}
            WHERE meta_key = 'te_reference'
        ");

        Logger::debug('TEReferenceGenerator', "Cleanup: Deleted {$deleted} orphaned te_reference values");

        return [
            'deleted' => $deleted,
            'message' => "Deleted {$deleted} orphaned te_reference meta values.",
        ];
    }

    /**
     * Get count of orphaned te_reference values.
     *
     * @return int Count.
     */
    public static function getOrphanedTeReferenceCount(): int {
        global $wpdb;

        return (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'te_reference'
        ");
    }
}
