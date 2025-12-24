<?php
/**
 * Plugin Name: BBAB Core
 * Plugin URI: https://bradsbitsandbytes.com
 * Description: Admin Command Center for Brad's Bits and Bytes Service Center
 * Version: 1.0.0
 * Author: Brad Wales
 * Author URI: https://bradsbitsandbytes.com
 * Text Domain: bbab-core
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package BBAB\Core
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Plugin version.
 */
define( 'BBAB_CORE_VERSION', '1.0.0' );

/**
 * Plugin base path.
 */
define( 'BBAB_CORE_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin base URL.
 */
define( 'BBAB_CORE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 */
define( 'BBAB_CORE_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load the autoloader.
 */
require_once BBAB_CORE_PATH . 'includes/class-loader.php';

/**
 * Code that runs during plugin activation.
 */
function bbab_core_activate() {
    require_once BBAB_CORE_PATH . 'includes/class-activator.php';
    BBAB\Core\Activator::activate();
}

/**
 * Code that runs during plugin deactivation.
 */
function bbab_core_deactivate() {
    require_once BBAB_CORE_PATH . 'includes/class-deactivator.php';
    BBAB\Core\Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'bbab_core_activate' );
register_deactivation_hook( __FILE__, 'bbab_core_deactivate' );

/**
 * Initialize the autoloader.
 */
$bbab_loader = new BBAB\Core\Loader();
$bbab_loader->register();

/**
 * Load the main plugin class and fire it up.
 */
require_once BBAB_CORE_PATH . 'includes/class-bbab-core.php';

/**
 * Begins execution of the plugin.
 *
 * @since 1.0.0
 */
function bbab_core_run() {
    $plugin = new BBAB\Core\BBAB_Core();
    $plugin->run();
}

bbab_core_run();
