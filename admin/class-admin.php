<?php
/**
 * Admin-specific functionality orchestrator.
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
 * Class Admin
 *
 * Orchestrates all admin-specific functionality.
 *
 * @since 1.0.0
 */
class Admin {

    /**
     * The workbench instance.
     *
     * @var Workbench
     */
    private $workbench;

    /**
     * Constructor.
     *
     * Initializes admin components.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->workbench = new Workbench();
    }

    /**
     * Register all admin hooks.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_hooks() {
        // Enqueue admin styles and scripts.
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Filter admin list queries for our custom filter parameters.
        add_action( 'pre_get_posts', array( $this, 'filter_admin_list_queries' ) );

        // Register workbench hooks.
        $this->workbench->register_hooks();
    }

    /**
     * Filter admin list queries based on our custom parameters.
     *
     * Handles bbab_project_id and bbab_sr_id query parameters to filter
     * milestones and time entries in the admin list.
     *
     * @since 1.0.0
     * @param \WP_Query $query The query object.
     * @return void
     */
    public function filter_admin_list_queries( $query ) {
        // Only run on admin, main query.
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $post_type = $query->get( 'post_type' );

        // Filter milestones by project.
        if ( 'milestone' === $post_type && ! empty( $_GET['bbab_project_id'] ) ) {
            $project_id = absint( $_GET['bbab_project_id'] );

            $meta_query = $query->get( 'meta_query' ) ?: array();
            $meta_query[] = array(
                'key'     => 'related_project',
                'value'   => $project_id,
                'compare' => '=',
            );
            $query->set( 'meta_query', $meta_query );
        }

        // Filter time entries by service request.
        if ( 'time_entry' === $post_type && ! empty( $_GET['bbab_sr_id'] ) ) {
            $sr_id = absint( $_GET['bbab_sr_id'] );

            $meta_query = $query->get( 'meta_query' ) ?: array();
            $meta_query[] = array(
                'key'     => 'related_service_request',
                'value'   => $sr_id,
                'compare' => '=',
            );
            $query->set( 'meta_query', $meta_query );
        }

        // Filter time entries by project (includes direct project TEs and milestone TEs).
        if ( 'time_entry' === $post_type && ! empty( $_GET['bbab_project_id'] ) ) {
            $project_id = absint( $_GET['bbab_project_id'] );

            // Get milestone IDs for this project.
            $milestones = get_posts( array(
                'post_type'      => 'milestone',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => 'related_project',
                        'value'   => $project_id,
                        'compare' => '=',
                    ),
                ),
            ) );

            // Build OR query: TEs linked to project OR linked to any milestone of project.
            $meta_query = array(
                'relation' => 'OR',
                array(
                    'key'     => 'related_project',
                    'value'   => $project_id,
                    'compare' => '=',
                ),
            );

            if ( ! empty( $milestones ) ) {
                $meta_query[] = array(
                    'key'     => 'related_milestone',
                    'value'   => $milestones,
                    'compare' => 'IN',
                );
            }

            $query->set( 'meta_query', $meta_query );
        }
    }

    /**
     * Enqueue admin styles.
     *
     * @since 1.0.0
     * @param string $hook_suffix The current admin page hook suffix.
     * @return void
     */
    public function enqueue_styles( $hook_suffix ) {
        // Only load on our plugin pages.
        if ( ! $this->is_plugin_page( $hook_suffix ) ) {
            return;
        }

        wp_enqueue_style(
            'bbab-workbench',
            BBAB_CORE_URL . 'admin/css/workbench.css',
            array(),
            BBAB_CORE_VERSION
        );
    }

    /**
     * Enqueue admin scripts.
     *
     * @since 1.0.0
     * @param string $hook_suffix The current admin page hook suffix.
     * @return void
     */
    public function enqueue_scripts( $hook_suffix ) {
        // Only load on our plugin pages.
        if ( ! $this->is_plugin_page( $hook_suffix ) ) {
            return;
        }

        wp_enqueue_script(
            'bbab-workbench',
            BBAB_CORE_URL . 'admin/js/workbench.js',
            array( 'jquery' ),
            BBAB_CORE_VERSION,
            true
        );

        // Localize script with data we'll need.
        wp_localize_script(
            'bbab-workbench',
            'bbabWorkbench',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'bbab_workbench_nonce' ),
            )
        );
    }

    /**
     * Check if current page is a plugin page.
     *
     * @since 1.0.0
     * @param string $hook_suffix The current admin page hook suffix.
     * @return bool
     */
    private function is_plugin_page( $hook_suffix ) {
        $plugin_pages = array(
            'toplevel_page_bbab-workbench',
            'brads-workbench_page_bbab-projects',
            'brads-workbench_page_bbab-requests',
            'brads-workbench_page_bbab-invoices',
        );

        return in_array( $hook_suffix, $plugin_pages, true );
    }
}
