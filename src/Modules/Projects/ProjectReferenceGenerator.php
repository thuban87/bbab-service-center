<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Projects;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Generates unique reference numbers for Projects.
 * Format: PR-0001
 *
 * Migrated from: WPCode Snippet #2320
 */
class ProjectReferenceGenerator {

    private const PREFIX = 'PR-';
    private const META_KEY = 'reference_number';

    /**
     * Register hooks.
     */
    public static function register(): void {
        // Early hook - fires when WordPress creates the post (including auto-draft)
        add_action('wp_insert_post', [self::class, 'handleInsert'], 10, 3);

        // Pods hook as backup (fires after Pods saves)
        add_action('pods_api_post_save_pod_item_project', [self::class, 'maybeGenerateFromPods'], 10, 3);

        Logger::debug('ProjectReferenceGenerator', 'Registered project reference hooks');
    }

    /**
     * Handle WordPress post insert - generate ref for new projects immediately.
     *
     * This fires when the editor first loads (creating auto-draft), so the
     * reference number is visible before the user saves.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update.
     */
    public static function handleInsert(int $post_id, \WP_Post $post, bool $update): void {
        // Only for projects
        if ($post->post_type !== 'project') {
            return;
        }

        // Only for new posts, not updates
        if ($update) {
            return;
        }

        // Skip if already has a reference
        $current_ref = get_post_meta($post_id, self::META_KEY, true);
        if (!empty($current_ref)) {
            return;
        }

        // Generate and save reference number
        $new_ref = self::generate();
        update_post_meta($post_id, self::META_KEY, $new_ref);

        Logger::debug('ProjectReferenceGenerator', "Generated {$new_ref} for project {$post_id} (on insert)");
    }

    /**
     * Generate reference number from Pods hook (backup).
     *
     * @param mixed $pieces     Pods save pieces (can be null).
     * @param mixed $is_new_item Whether this is a new item.
     * @param int   $id         Post ID.
     */
    public static function maybeGenerateFromPods($pieces, $is_new_item, int $id): void {
        $current_ref = get_post_meta($id, self::META_KEY, true);

        if (!empty($current_ref)) {
            return;
        }

        $new_ref = self::generate();
        update_post_meta($id, self::META_KEY, $new_ref);

        Logger::debug('ProjectReferenceGenerator', "Generated {$new_ref} for project {$id} (from Pods)");
    }

    /**
     * Generate the next available reference number.
     *
     * @return string Reference number (e.g., PR-0001).
     */
    public static function generate(): string {
        global $wpdb;

        $last = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = %s
             AND meta_value LIKE %s
             ORDER BY CAST(SUBSTRING(meta_value, 4) AS UNSIGNED) DESC
             LIMIT 1",
            self::META_KEY,
            self::PREFIX . '%'
        ));

        $num = $last ? (int) substr($last, strlen(self::PREFIX)) + 1 : 1;

        return self::PREFIX . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get the next reference number without saving it.
     * Useful for previewing what the next number would be.
     *
     * @return string Next reference number.
     */
    public static function getNextReference(): string {
        return self::generate();
    }
}
