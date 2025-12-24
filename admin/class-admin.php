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

        // Register workbench hooks.
        $this->workbench->register_hooks();
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
