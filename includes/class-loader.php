<?php
/**
 * Autoloader for BBAB Core plugin.
 *
 * PSR-4-ish autoloading without Composer.
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
 * Class Loader
 *
 * Handles autoloading of plugin classes.
 *
 * @since 1.0.0
 */
class Loader {

    /**
     * Namespace prefix for the plugin.
     *
     * @var string
     */
    private $namespace_prefix = 'BBAB\\Core\\';

    /**
     * Base directory for the namespace prefix.
     *
     * @var string
     */
    private $base_dir;

    /**
     * Constructor.
     *
     * Sets up the base directory for autoloading.
     */
    public function __construct() {
        $this->base_dir = BBAB_CORE_PATH;
    }

    /**
     * Register the autoloader with SPL.
     *
     * @return void
     */
    public function register() {
        spl_autoload_register( array( $this, 'autoload' ) );
    }

    /**
     * Autoload callback.
     *
     * Maps class names to file paths and loads them.
     *
     * Mapping:
     * - BBAB\Core\Admin\Workbench     → admin/class-workbench.php
     * - BBAB\Core\Admin\Cache         → admin/class-cache.php
     * - BBAB\Core\Activator           → includes/class-activator.php
     *
     * @param string $class The fully-qualified class name.
     * @return void
     */
    public function autoload( $class ) {
        // Check if the class uses our namespace prefix.
        $len = strlen( $this->namespace_prefix );
        if ( strncmp( $this->namespace_prefix, $class, $len ) !== 0 ) {
            // Not our class, bail.
            return;
        }

        // Get the relative class name (without the namespace prefix).
        $relative_class = substr( $class, $len );

        // Convert namespace separators to directory separators.
        // BBAB\Core\Admin\Workbench → Admin\Workbench
        $relative_path = str_replace( '\\', '/', $relative_class );

        // Split into parts to get directory and class name.
        $parts = explode( '/', $relative_path );
        $class_name = array_pop( $parts );

        // Convert class name to file name (CamelCase to kebab-case).
        // Workbench → workbench, BBAB_Core → bbab-core
        $file_name = 'class-' . strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', str_replace( '_', '-', $class_name ) ) ) . '.php';

        // Determine the directory.
        if ( empty( $parts ) ) {
            // Root namespace classes go in includes/
            $directory = 'includes/';
        } else {
            // Nested namespace classes go in their namespace directory (lowercased).
            $directory = strtolower( implode( '/', $parts ) ) . '/';
        }

        // Build the full file path.
        $file = $this->base_dir . $directory . $file_name;

        // If the file exists, require it.
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}
