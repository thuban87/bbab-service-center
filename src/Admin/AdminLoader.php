<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin;

use BBAB\ServiceCenter\Admin\Workbench\WorkbenchPage;
use BBAB\ServiceCenter\Admin\Pages\ClientHealthDashboard;
use BBAB\ServiceCenter\Admin\Pages\SettingsPage;
use BBAB\ServiceCenter\Admin\ClientPortalMenu;
use BBAB\ServiceCenter\Admin\Columns\ServiceRequestColumns;
use BBAB\ServiceCenter\Admin\Columns\TimeEntryColumns;
use BBAB\ServiceCenter\Admin\Columns\ProjectMilestoneRefColumns;
use BBAB\ServiceCenter\Admin\Columns\ProjectColumns;
use BBAB\ServiceCenter\Admin\Columns\MilestoneColumns;
use BBAB\ServiceCenter\Admin\Columns\InvoiceColumns;
use BBAB\ServiceCenter\Admin\Columns\LineItemColumns;
use BBAB\ServiceCenter\Admin\Columns\ProjectReportColumns;
use BBAB\ServiceCenter\Admin\Columns\KBColumns;
use BBAB\ServiceCenter\Admin\Columns\RoadmapColumns;
use BBAB\ServiceCenter\Admin\Columns\OrganizationColumns;
use BBAB\ServiceCenter\Admin\Columns\ClientTaskColumns;
use BBAB\ServiceCenter\Admin\Columns\MonthlyReportColumns;
use BBAB\ServiceCenter\Admin\RowActions\LogTimeAction;
use BBAB\ServiceCenter\Admin\RowActions\RoadmapActions;
use BBAB\ServiceCenter\Admin\RowActions\MonthlyReportActions;
use BBAB\ServiceCenter\Admin\RowActions\ClientTaskActions;
use BBAB\ServiceCenter\Admin\Metaboxes\ServiceRequestMetabox;
use BBAB\ServiceCenter\Admin\Metaboxes\TimerMetabox;
use BBAB\ServiceCenter\Admin\Metaboxes\TimeEntryReassignMetabox;
use BBAB\ServiceCenter\Admin\Metaboxes\ProjectMetabox;
use BBAB\ServiceCenter\Admin\Metaboxes\MilestoneMetabox;
use BBAB\ServiceCenter\Admin\Metaboxes\InvoiceMetabox;
use BBAB\ServiceCenter\Admin\Metaboxes\LineItemMetabox;
use BBAB\ServiceCenter\Admin\Metaboxes\ProjectReportMetabox;
use BBAB\ServiceCenter\Admin\Metaboxes\MonthlyReportMetabox;
use BBAB\ServiceCenter\Admin\Metaboxes\RoadmapMetabox;
use BBAB\ServiceCenter\Admin\GlobalTimerIndicator;
use BBAB\ServiceCenter\Admin\AdminBarHealth;
use BBAB\ServiceCenter\Admin\ProjectReportFieldFilter;
use BBAB\ServiceCenter\Admin\CascadingDropdowns;
use BBAB\ServiceCenter\Admin\LineItemLinker;
use BBAB\ServiceCenter\Modules\TimeTracking\TimeEntryService;
use BBAB\ServiceCenter\Modules\TimeTracking\TimerService;
use BBAB\ServiceCenter\Modules\TimeTracking\TEReferenceGenerator;
use BBAB\ServiceCenter\Modules\ServiceRequests\ReferenceGenerator;
use BBAB\ServiceCenter\Modules\ServiceRequests\FormProcessor;
use BBAB\ServiceCenter\Modules\Projects\ProjectReferenceGenerator;
use BBAB\ServiceCenter\Modules\Projects\MilestoneReferenceGenerator;
use BBAB\ServiceCenter\Modules\Projects\TitleSync;
use BBAB\ServiceCenter\Modules\Projects\ProjectReportReferenceGenerator;
use BBAB\ServiceCenter\Modules\Projects\ProjectReportTitleSync;
use BBAB\ServiceCenter\Modules\Billing\InvoiceReferenceGenerator;
use BBAB\ServiceCenter\Modules\Billing\InvoiceTitleSync;
use BBAB\ServiceCenter\Modules\Billing\InvoiceGenerator;
use BBAB\ServiceCenter\Modules\Billing\LineItemService;
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
     * Client Portal Menu instance.
     */
    private ?ClientPortalMenu $portal_menu = null;

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

        // Initialize Client Portal Menu (consolidates all CPT menus) - Phase 7.2
        $this->portal_menu = new ClientPortalMenu();
        $this->portal_menu->register();

        // Initialize Time Entry Linker (pre-populates from SR/Project links)
        $time_entry_linker = new TimeEntryLinker();
        $time_entry_linker->register();

        // Initialize Line Item Linker (pre-populates from invoice links)
        $line_item_linker = new LineItemLinker();
        $line_item_linker->register();

        // Initialize Time Entry Service (hours calculation, orphan prevention, transient linking)
        TimeEntryService::register();

        // Initialize Timer Service (timer start/stop, kill on trash)
        TimerService::register();

        // Initialize TE Reference Generator (TE-0001 format)
        TEReferenceGenerator::register();

        // Initialize Service Request services
        ReferenceGenerator::register();
        FormProcessor::register();

        // Initialize Project/Milestone services (Phase 5.1)
        ProjectReferenceGenerator::register();
        MilestoneReferenceGenerator::register();
        TitleSync::register();

        // Initialize Project Report services (Phase 7.3)
        ProjectReportReferenceGenerator::register();
        ProjectReportTitleSync::register();

        // Initialize Invoice/Billing services (Phase 5.3)
        InvoiceReferenceGenerator::register();
        InvoiceTitleSync::register();
        LineItemService::register();

        // Initialize Invoice Generator (Phase 5.4)
        InvoiceGenerator::register();

        // Initialize admin columns and filters (Phase 4.3)
        ServiceRequestColumns::register();
        TimeEntryColumns::register();

        // Initialize Project/Milestone columns (Phase 5.2)
        ProjectColumns::register();
        MilestoneColumns::register();

        // Initialize Invoice columns (Phase 5.3)
        InvoiceColumns::register();

        // Initialize Line Item columns (Phase 6.1)
        LineItemColumns::register();

        // Initialize Project Report columns (Phase 7.3)
        ProjectReportColumns::register();

        // Initialize KB Article columns (Phase 7.4)
        KBColumns::register();

        // Initialize Roadmap columns and filters (Phase 7.5)
        RoadmapColumns::register();

        // Initialize Client Organization columns (Phase 7.7)
        OrganizationColumns::register();

        // Initialize Client Task columns and filters (Phase 7.7)
        ClientTaskColumns::register();

        // Initialize Monthly Report columns and filters (Phase 8.3)
        MonthlyReportColumns::register();

        // Initialize Project/Milestone reference metaboxes (Phase 5.1)
        ProjectMilestoneRefColumns::register();

        // Initialize row actions (Phase 4.3)
        LogTimeAction::register();

        // Initialize Monthly Report row actions (Phase 6.1)
        MonthlyReportActions::register();

        // Initialize Roadmap row actions (Phase 7.5)
        RoadmapActions::register();

        // Initialize Client Task row actions (Phase 7.7)
        ClientTaskActions::register();

        // Initialize metaboxes (Phase 4.3)
        ServiceRequestMetabox::register();
        TimerMetabox::register();
        TimeEntryReassignMetabox::register();

        // Initialize Project/Milestone metaboxes (Phase 5.2)
        ProjectMetabox::register();
        MilestoneMetabox::register();

        // Initialize Invoice metaboxes (Phase 5.3)
        InvoiceMetabox::register();

        // Initialize Line Item metaboxes (Phase 5.4)
        LineItemMetabox::register();

        // Initialize Project Report metaboxes (Phase 7.3)
        ProjectReportMetabox::register();

        // Initialize Monthly Report metaboxes (Phase 7.6)
        MonthlyReportMetabox::register();

        // Initialize Roadmap metaboxes (Phase 7 staging fixes)
        RoadmapMetabox::register();

        // Initialize Project Report field filtering (Phase 7.3)
        ProjectReportFieldFilter::register();

        // Initialize global timer indicator (Phase 4.3)
        GlobalTimerIndicator::register();

        // Initialize admin simulation bar (shows when simulating an org)
        AdminSimulationBar::register();

        // Initialize admin bar health indicator (Phase 7 Review)
        AdminBarHealth::register();

        // Initialize cascading dropdown filters (Phase 8.4b)
        CascadingDropdowns::register();

        // Register assets
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // Admin notices for missing configuration
        add_action('admin_notices', [$this, 'showConfigurationNotices']);

        // Remove SEO columns from our CPTs (Phase 8.3)
        $this->removeSeoColumns();

        Logger::debug('AdminLoader', 'Admin loader initialized');
    }

    /**
     * Remove SEO plugin columns from our custom post types.
     *
     * Phase 8.3 - Clean up CPT admin screens.
     * Handles both Rank Math and Yoast SEO.
     */
    private function removeSeoColumns(): void {
        $cpt_list = [
            'service_request',
            'time_entry',
            'project',
            'milestone',
            'invoice',
            'invoice_line_item',
            'monthly_report',
            'project_report',
            'kb_article',
            'roadmap_item',
            'client_organization',
            'client_task',
        ];

        foreach ($cpt_list as $cpt) {
            add_filter("manage_{$cpt}_posts_columns", function ($columns) {
                // Remove Rank Math SEO columns
                unset($columns['rank_math_seo_details']);
                unset($columns['rank_math_title']);
                unset($columns['rank_math_description']);
                unset($columns['rank_math_schema']);

                // Remove Yoast SEO columns (in case it's ever installed)
                unset($columns['wpseo-score']);
                unset($columns['wpseo-score-readability']);
                unset($columns['wpseo-title']);
                unset($columns['wpseo-metadesc']);
                unset($columns['wpseo-focuskw']);
                unset($columns['wpseo-links']);
                unset($columns['wpseo-linked']);
                return $columns;
            }, 99); // Late priority to run after SEO plugins add them
        }
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
