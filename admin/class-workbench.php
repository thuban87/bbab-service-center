<?php
/**
 * Brad's Workbench - Main Dashboard Page.
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
 * Class Workbench
 *
 * Handles the main Workbench dashboard page.
 *
 * @since 1.0.0
 */
class Workbench {

    /**
     * Cache instance.
     *
     * @var Cache
     */
    private $cache;

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->cache = new Cache();
    }

    /**
     * Register hooks for the workbench.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'register_menu_pages' ) );
    }

    /**
     * Register the admin menu pages.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_menu_pages() {
        // Main menu page.
        add_menu_page(
            __( "Brad's Workbench", 'bbab-core' ),
            __( "Brad's Workbench", 'bbab-core' ),
            'manage_options',
            'bbab-workbench',
            array( $this, 'render_main_page' ),
            'dashicons-desktop',
            2 // Position: right below Dashboard.
        );

        // Sub-pages will be added in Phase 2/3.
        // Placeholder comments for future sub-pages:
        // - Projects (bbab-projects)
        // - Service Requests (bbab-requests)
        // - Invoices (bbab-invoices)
    }

    /**
     * Render the main workbench page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_main_page() {
        // Security check.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bbab-core' ) );
        }

        // Load the template.
        include BBAB_CORE_PATH . 'admin/partials/workbench-main.php';
    }

    /**
     * Get open service requests for display.
     *
     * @since 1.0.0
     * @param int $limit Number of items to return.
     * @return array
     */
    public function get_open_service_requests( $limit = 10 ) {
        $cache_key = 'bbab_open_srs_' . $limit;
        $cached    = $this->cache->get( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $results = get_posts( array(
            'post_type'      => 'service_request',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'meta_query'     => array(
                array(
                    'key'     => 'request_status',
                    'value'   => array( 'Completed', 'Cancelled' ),
                    'compare' => 'NOT IN',
                ),
            ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $this->cache->set( $cache_key, $results, HOUR_IN_SECONDS );

        return $results;
    }

    /**
     * Get active projects for display.
     *
     * @since 1.0.0
     * @param int $limit Number of items to return.
     * @return array
     */
    public function get_active_projects( $limit = 10 ) {
        $cache_key = 'bbab_active_projects_' . $limit;
        $cached    = $this->cache->get( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $results = get_posts( array(
            'post_type'      => 'project',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'meta_query'     => array(
                array(
                    'key'     => 'project_status',
                    'value'   => array( 'Active', 'Waiting on Client', 'On Hold' ),
                    'compare' => 'IN',
                ),
            ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $this->cache->set( $cache_key, $results, HOUR_IN_SECONDS );

        return $results;
    }

    /**
     * Get pending invoices for display.
     *
     * @since 1.0.0
     * @param int $limit Number of items to return.
     * @return array
     */
    public function get_pending_invoices( $limit = 10 ) {
        $cache_key = 'bbab_pending_invoices_' . $limit;
        $cached    = $this->cache->get( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $results = get_posts( array(
            'post_type'      => 'invoice',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'meta_query'     => array(
                array(
                    'key'     => 'invoice_status',
                    'value'   => array( 'Paid', 'Cancelled' ),
                    'compare' => 'NOT IN',
                ),
            ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $this->cache->set( $cache_key, $results, HOUR_IN_SECONDS );

        return $results;
    }
}
