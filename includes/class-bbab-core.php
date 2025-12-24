<?php
/**
 * The core plugin class.
 *
 * This is used to define admin-specific hooks and public-facing hooks.
 *
 * @package BBAB\Core
 * @since   1.0.0
 */

namespace BBAB\Core;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class BBAB_Core
 *
 * The main plugin class that orchestrates all functionality.
 *
 * @since 1.0.0
 */
class BBAB_Core {

    /**
     * The admin instance.
     *
     * @var Admin\Admin
     */
    protected $admin;

    /**
     * Constructor.
     *
     * Sets up the plugin components.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->load_dependencies();
    }

    /**
     * Load required dependencies.
     *
     * @since 1.0.0
     * @return void
     */
    private function load_dependencies() {
        // Admin-specific functionality.
        if ( is_admin() ) {
            $this->admin = new Admin\Admin();
        }
    }

    /**
     * Run the plugin.
     *
     * Registers all hooks with WordPress.
     *
     * @since 1.0.0
     * @return void
     */
    public function run() {
        // Register admin hooks.
        if ( is_admin() && $this->admin ) {
            $this->admin->register_hooks();
        }
    }
}
