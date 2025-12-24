<?php
/**
 * Transient caching helper class.
 *
 * @package BBAB\Core\Admin
 * @since   1.0.0
 */

namespace BBAB\Core\Admin;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Cache
 *
 * Helper class for managing WordPress transients with consistent prefixing
 * and cache invalidation hooks.
 *
 * @since 1.0.0
 */
class Cache {

    /**
     * Cache prefix for all transients.
     *
     * @var string
     */
    const PREFIX = 'bbab_';

    /**
     * Default cache duration in seconds.
     *
     * @var int
     */
    const DEFAULT_EXPIRATION = HOUR_IN_SECONDS;

    /**
     * Constructor.
     *
     * Registers cache invalidation hooks.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->register_invalidation_hooks();
    }

    /**
     * Get a cached value.
     *
     * @since 1.0.0
     * @param string $key Cache key (without prefix).
     * @return mixed Cached value or false if not found.
     */
    public function get( $key ) {
        return get_transient( $this->prefix_key( $key ) );
    }

    /**
     * Set a cached value.
     *
     * @since 1.0.0
     * @param string $key        Cache key (without prefix).
     * @param mixed  $value      Value to cache.
     * @param int    $expiration Optional. Time until expiration in seconds. Default HOUR_IN_SECONDS.
     * @return bool True if value was set, false otherwise.
     */
    public function set( $key, $value, $expiration = self::DEFAULT_EXPIRATION ) {
        return set_transient( $this->prefix_key( $key ), $value, $expiration );
    }

    /**
     * Delete a cached value.
     *
     * @since 1.0.0
     * @param string $key Cache key (without prefix).
     * @return bool True if deleted, false otherwise.
     */
    public function delete( $key ) {
        return delete_transient( $this->prefix_key( $key ) );
    }

    /**
     * Delete all cached values matching a pattern.
     *
     * @since 1.0.0
     * @param string $pattern Pattern to match (e.g., 'open_srs' matches 'bbab_open_srs_*').
     * @return int Number of transients deleted.
     */
    public function delete_pattern( $pattern ) {
        global $wpdb;

        $full_pattern = '_transient_' . self::PREFIX . $pattern . '%';

        // Find matching transients.
        $transients = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $full_pattern
            )
        );

        $count = 0;
        foreach ( $transients as $transient ) {
            // Remove the '_transient_' prefix to get the actual transient name.
            $key = str_replace( '_transient_', '', $transient );
            if ( delete_transient( $key ) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Clear all plugin transients.
     *
     * @since 1.0.0
     * @return int Number of transients deleted.
     */
    public function clear_all() {
        global $wpdb;

        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_" . self::PREFIX . "%'
             OR option_name LIKE '_transient_timeout_" . self::PREFIX . "%'"
        );

        return $deleted;
    }

    /**
     * Register hooks to invalidate cache when data changes.
     *
     * @since 1.0.0
     * @return void
     */
    private function register_invalidation_hooks() {
        // Invalidate service request caches.
        add_action( 'save_post_service_request', array( $this, 'invalidate_service_request_cache' ) );
        add_action( 'delete_post', array( $this, 'invalidate_on_delete' ), 10, 2 );

        // Invalidate project caches.
        add_action( 'save_post_project', array( $this, 'invalidate_project_cache' ) );

        // Invalidate invoice caches.
        add_action( 'save_post_invoice', array( $this, 'invalidate_invoice_cache' ) );

        // Invalidate milestone caches.
        add_action( 'save_post_milestone', array( $this, 'invalidate_milestone_cache' ) );

        // Invalidate time entry caches.
        add_action( 'save_post_time_entry', array( $this, 'invalidate_time_entry_cache' ) );
    }

    /**
     * Invalidate service request related caches.
     *
     * @since 1.0.0
     * @param int $post_id Post ID.
     * @return void
     */
    public function invalidate_service_request_cache( $post_id ) {
        // Skip autosaves and revisions.
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        $this->delete_pattern( 'open_srs' );
        $this->delete_pattern( 'sr_' );
    }

    /**
     * Invalidate project related caches.
     *
     * @since 1.0.0
     * @param int $post_id Post ID.
     * @return void
     */
    public function invalidate_project_cache( $post_id ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        $this->delete_pattern( 'active_projects' );
        $this->delete_pattern( 'project_' );
    }

    /**
     * Invalidate invoice related caches.
     *
     * @since 1.0.0
     * @param int $post_id Post ID.
     * @return void
     */
    public function invalidate_invoice_cache( $post_id ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        $this->delete_pattern( 'pending_invoices' );
        $this->delete_pattern( 'invoice_' );
    }

    /**
     * Invalidate milestone related caches.
     *
     * @since 1.0.0
     * @param int $post_id Post ID.
     * @return void
     */
    public function invalidate_milestone_cache( $post_id ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Milestones affect project data too.
        $this->delete_pattern( 'active_projects' );
        $this->delete_pattern( 'milestone_' );
        $this->delete_pattern( 'project_' );
    }

    /**
     * Invalidate time entry related caches.
     *
     * @since 1.0.0
     * @param int $post_id Post ID.
     * @return void
     */
    public function invalidate_time_entry_cache( $post_id ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Time entries affect SRs, projects, and milestones.
        $this->delete_pattern( 'open_srs' );
        $this->delete_pattern( 'active_projects' );
        $this->delete_pattern( 'te_' );
        $this->delete_pattern( 'sr_' );
        $this->delete_pattern( 'project_' );
        $this->delete_pattern( 'milestone_' );
    }

    /**
     * Handle cache invalidation on post deletion.
     *
     * @since 1.0.0
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @return void
     */
    public function invalidate_on_delete( $post_id, $post ) {
        if ( ! $post ) {
            return;
        }

        switch ( $post->post_type ) {
            case 'service_request':
                $this->invalidate_service_request_cache( $post_id );
                break;
            case 'project':
                $this->invalidate_project_cache( $post_id );
                break;
            case 'invoice':
                $this->invalidate_invoice_cache( $post_id );
                break;
            case 'milestone':
                $this->invalidate_milestone_cache( $post_id );
                break;
            case 'time_entry':
                $this->invalidate_time_entry_cache( $post_id );
                break;
        }
    }

    /**
     * Add prefix to cache key.
     *
     * @since 1.0.0
     * @param string $key Cache key.
     * @return string Prefixed cache key.
     */
    private function prefix_key( $key ) {
        // Don't double-prefix.
        if ( strpos( $key, self::PREFIX ) === 0 ) {
            return $key;
        }
        return self::PREFIX . $key;
    }
}
