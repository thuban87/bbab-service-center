<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin;

use BBAB\ServiceCenter\Admin\Workbench\WorkbenchPage;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Registers all admin functionality.
 *
 * - Workbench pages
 * - Custom columns
 * - Metaboxes
 * - Admin assets
 */
class AdminLoader {

    /**
     * Workbench page instance.
     */
    private WorkbenchPage $workbench;

    /**
     * Register all admin hooks.
     */
    public function register(): void {
        // Initialize workbench
        $this->workbench = new WorkbenchPage();
        $this->workbench->register();

        // Register assets
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // Admin notices for missing configuration
        add_action('admin_notices', [$this, 'showConfigurationNotices']);

        Logger::debug('AdminLoader', 'Admin loader initialized');
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook
     */
    public function enqueueAssets(string $hook): void {
        // Only load on our admin pages
        if (strpos($hook, 'bbab-') === false && strpos($hook, 'bbab_') === false) {
            // Check if it's a workbench page
            $screen = get_current_screen();
            if (!$screen || strpos($screen->id, 'bbab') === false) {
                return;
            }
        }

        wp_enqueue_style(
            'bbab-sc-admin',
            BBAB_SC_URL . 'assets/css/admin-workbench.css',
            [],
            BBAB_SC_VERSION
        );

        wp_enqueue_script(
            'bbab-sc-admin',
            BBAB_SC_URL . 'assets/js/admin-workbench.js',
            ['jquery'],
            BBAB_SC_VERSION,
            true
        );

        // Localize script with AJAX data
        wp_localize_script('bbab-sc-admin', 'bbabScAdmin', [
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bbab_sc_ajax_nonce'),
            'action' => 'bbab_sc_ajax',
        ]);
    }

    /**
     * Show admin notices for missing configuration.
     */
    public function showConfigurationNotices(): void {
        // Only show to admins
        if (!current_user_can('manage_options')) {
            return;
        }

        // Only show on our pages or the main dashboard
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Check for missing settings
        $missing = \BBAB\ServiceCenter\Utils\Settings::getMissingConfiguration();

        if (!empty($missing) && ($screen->id === 'dashboard' || strpos($screen->id, 'bbab') !== false)) {
            $missing_list = implode(', ', $missing);
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>BBAB Service Center:</strong> Some settings need to be configured: ' . esc_html($missing_list) . '</p>';
            echo '</div>';
        }
    }

    /**
     * Get the workbench instance.
     */
    public function getWorkbench(): WorkbenchPage {
        return $this->workbench;
    }
}
