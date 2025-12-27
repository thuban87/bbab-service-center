<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin;

use BBAB\ServiceCenter\Admin\Workbench\WorkbenchPage;
use BBAB\ServiceCenter\Admin\Pages\ClientHealthDashboard;
use BBAB\ServiceCenter\Admin\Pages\SettingsPage;
use BBAB\ServiceCenter\Admin\Columns\ServiceRequestColumns;
use BBAB\ServiceCenter\Admin\Columns\TimeEntryColumns;
use BBAB\ServiceCenter\Admin\RowActions\LogTimeAction;
use BBAB\ServiceCenter\Admin\Metaboxes\ServiceRequestMetabox;
use BBAB\ServiceCenter\Admin\Metaboxes\TimerMetabox;
use BBAB\ServiceCenter\Admin\Metaboxes\TimeEntryReassignMetabox;
use BBAB\ServiceCenter\Admin\GlobalTimerIndicator;
use BBAB\ServiceCenter\Modules\TimeTracking\TimeEntryService;
use BBAB\ServiceCenter\Modules\TimeTracking\TimerService;
use BBAB\ServiceCenter\Modules\TimeTracking\TEReferenceGenerator;
use BBAB\ServiceCenter\Modules\ServiceRequests\ReferenceGenerator;
use BBAB\ServiceCenter\Modules\ServiceRequests\FormProcessor;
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
     * Client Health Dashboard instance.
     */
    private ClientHealthDashboard $health_dashboard;

    /**
     * Settings page instance.
     */
    private SettingsPage $settings_page;

    /**
     * Register all admin hooks.
     */
    public function register(): void {
        // Initialize workbench
        $this->workbench = new WorkbenchPage();
        $this->workbench->register();

        // Initialize Client Health Dashboard
        $this->health_dashboard = new ClientHealthDashboard();
        $this->health_dashboard->register();

        // Initialize Settings Page
        $this->settings_page = new SettingsPage();
        $this->settings_page->register();

        // Initialize Time Entry Linker (pre-populates from SR/Project links)
        $time_entry_linker = new TimeEntryLinker();
        $time_entry_linker->register();

        // Initialize Time Entry Service (hours calculation, orphan prevention, transient linking)
        TimeEntryService::register();

        // Initialize Timer Service (timer start/stop, kill on trash)
        TimerService::register();

        // Initialize TE Reference Generator (TE-0001 format)
        TEReferenceGenerator::register();

        // Initialize Service Request services
        ReferenceGenerator::register();
        FormProcessor::register();

        // Initialize admin columns and filters (Phase 4.3)
        ServiceRequestColumns::register();
        TimeEntryColumns::register();

        // Initialize row actions (Phase 4.3)
        LogTimeAction::register();

        // Initialize metaboxes (Phase 4.3)
        ServiceRequestMetabox::register();
        TimerMetabox::register();
        TimeEntryReassignMetabox::register();

        // Initialize global timer indicator (Phase 4.3)
        GlobalTimerIndicator::register();

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
        $screen = get_current_screen();

        // Load service center CSS on SR and TE screens
        if ($screen && in_array($screen->post_type, ['service_request', 'time_entry'], true)) {
            wp_enqueue_style(
                'bbab-sc-service-center',
                BBAB_SC_URL . 'assets/css/admin-service-center.css',
                [],
                BBAB_SC_VERSION
            );
        }

        // Load workbench assets only on bbab pages
        if (strpos($hook, 'bbab-') === false && strpos($hook, 'bbab_') === false) {
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

        // Check for missing settings - but don't clutter the workbench with warnings
        $missing = \BBAB\ServiceCenter\Utils\Settings::getMissingConfiguration();

        // Skip warning on workbench pages - Brad knows what's up
        if (strpos($screen->id, 'bbab-workbench') !== false) {
            return;
        }

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
