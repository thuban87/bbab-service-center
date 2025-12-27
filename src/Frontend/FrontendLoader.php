<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend;

use BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard\Overview;
use BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard\MonthProgress;
use BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard\RecentEntries;
use BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard\ActionItems;
use BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard\Billing;
use BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard\ActiveProjects;
use BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard\ServiceRequests;
use BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard\Roadmap;
use BBAB\ServiceCenter\Frontend\Shortcodes\Analytics\ClientAnalytics;
use BBAB\ServiceCenter\Frontend\Shortcodes\Hosting\HostingHealth;
use BBAB\ServiceCenter\Frontend\Shortcodes\ServiceRequests\Archive as SRArchive;
use BBAB\ServiceCenter\Frontend\Shortcodes\ServiceRequests\Detail as SRDetail;
use BBAB\ServiceCenter\Frontend\Shortcodes\ServiceRequests\Attachments as SRAttachments;
use BBAB\ServiceCenter\Frontend\Shortcodes\ServiceRequests\TimeEntries as SRTimeEntries;
use BBAB\ServiceCenter\Frontend\Shortcodes\ServiceRequests\AccessControl as SRAccessControl;
use BBAB\ServiceCenter\Frontend\Shortcodes\TimeTracking\EntriesDisplay as TEEntriesDisplay;
use BBAB\ServiceCenter\Frontend\Shortcodes\Projects\StatusBadge as ProjectStatusBadge;
use BBAB\ServiceCenter\Frontend\Shortcodes\Projects\MilestoneProgress;
use BBAB\ServiceCenter\Frontend\Shortcodes\Projects\SortControls as ProjectSortControls;
use BBAB\ServiceCenter\Frontend\Shortcodes\Projects\ArchiveFilter as ProjectArchiveFilter;
use BBAB\ServiceCenter\Frontend\Shortcodes\Projects\MilestoneLoopFilter;
use BBAB\ServiceCenter\Frontend\Shortcodes\Milestones\WorkBadge as MilestoneWorkBadge;
use BBAB\ServiceCenter\Frontend\Shortcodes\Milestones\BackToProject;
use BBAB\ServiceCenter\Frontend\Shortcodes\Milestones\DueDate as MilestoneDueDate;
use BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard\ProjectsLink;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Registers all frontend functionality.
 *
 * - Shortcodes
 * - Simulation bar
 * - Frontend assets (CSS/JS)
 */
class FrontendLoader {

    /**
     * Shortcode instances.
     *
     * @var array
     */
    private array $shortcodes = [];

    /**
     * Simulation bar instance.
     */
    private SimulationBar $simulation_bar;

    /**
     * Register all frontend hooks.
     */
    public function register(): void {
        // Initialize simulation bar
        $this->simulation_bar = new SimulationBar();
        $this->simulation_bar->register();

        // Register access control for single SR pages
        SRAccessControl::register();

        // Register project archive filter and milestone loop filter (Phase 5.2)
        ProjectArchiveFilter::register();
        MilestoneLoopFilter::register();

        // Register shortcodes
        $this->registerShortcodes();

        // Register assets
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);

        Logger::debug('FrontendLoader', 'Frontend loader initialized');
    }

    /**
     * Register all shortcodes.
     */
    private function registerShortcodes(): void {
        // Dashboard shortcodes
        $shortcode_classes = [
            Overview::class,
            MonthProgress::class,
            RecentEntries::class,
            ActionItems::class,
            Billing::class,
            ActiveProjects::class,
            ServiceRequests::class,
            Roadmap::class,
            // Analytics shortcodes
            ClientAnalytics::class,
            // Hosting shortcodes
            HostingHealth::class,
            // Service Request shortcodes
            SRArchive::class,
            SRDetail::class,
            SRAttachments::class,
            SRTimeEntries::class,
            // Time Tracking shortcodes
            TEEntriesDisplay::class,
            // Project shortcodes (Phase 5.2)
            ProjectStatusBadge::class,
            MilestoneProgress::class,
            ProjectSortControls::class,
            ProjectsLink::class,
            // Milestone shortcodes (Phase 5.2)
            MilestoneWorkBadge::class,
            BackToProject::class,
            MilestoneDueDate::class,
        ];

        foreach ($shortcode_classes as $class) {
            if (class_exists($class)) {
                $shortcode = new $class();
                $shortcode->register();
                $this->shortcodes[$shortcode->getTag()] = $shortcode;
            }
        }

        Logger::debug('FrontendLoader', 'Registered ' . count($this->shortcodes) . ' shortcodes');
    }

    /**
     * Enqueue frontend assets.
     */
    public function enqueueAssets(): void {
        // Only load on pages that might use our shortcodes
        // For now, load on all frontend pages - can optimize later

        wp_enqueue_style(
            'bbab-sc-frontend',
            BBAB_SC_URL . 'assets/css/frontend-dashboard.css',
            [],
            BBAB_SC_VERSION
        );

        wp_enqueue_script(
            'bbab-sc-frontend',
            BBAB_SC_URL . 'assets/js/frontend-dashboard.js',
            ['jquery'],
            BBAB_SC_VERSION,
            true
        );

        // Localize script with AJAX data
        wp_localize_script('bbab-sc-frontend', 'bbabScAjax', [
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bbab_sc_ajax_nonce'),
            'action' => 'bbab_sc_ajax',
        ]);
    }

    /**
     * Get a registered shortcode instance by tag.
     */
    public function getShortcode(string $tag) {
        return $this->shortcodes[$tag] ?? null;
    }
}
