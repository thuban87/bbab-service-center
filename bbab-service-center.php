<?php
/**
 * Plugin Name: BBAB Service Center
 * Plugin URI: https://bradsbitsandbytes.com
 * Description: Complete client portal and service center for Brad's Bits and Bytes
 * Version: 2.0.0
 * Author: Brad Wales
 * Author URI: https://bradsbitsandbytes.com
 * Text Domain: bbab-service-center
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package BBAB\ServiceCenter
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('BBAB_SC_VERSION', '2.0.0');
define('BBAB_SC_PATH', plugin_dir_path(__FILE__));
define('BBAB_SC_URL', plugin_dir_url(__FILE__));
define('BBAB_SC_BASENAME', plugin_basename(__FILE__));

// Load Composer autoloader
if (file_exists(BBAB_SC_PATH . 'vendor/autoload.php')) {
    require_once BBAB_SC_PATH . 'vendor/autoload.php';
} else {
    // Composer not installed - show admin notice and bail
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BBAB Service Center:</strong> Composer dependencies not installed. ';
        echo 'Run <code>composer install</code> in the plugin directory.';
        echo '</p></div>';
    });
    return;
}

/**
 * Compatibility layer for snippets that haven't been migrated yet.
 *
 * These global functions wrap the namespaced service methods so old snippets
 * can continue to call them during the migration process.
 *
 * TODO: Remove these after all dependent snippets are migrated.
 */

if (!function_exists('bbab_get_sr_total_hours')) {
    /**
     * Get total hours for a service request.
     *
     * Compatibility wrapper for snippet 1716 (SR columns) until it's migrated in Session 4.3.
     *
     * @param int $sr_id Service request ID.
     * @return float Total billable hours.
     */
    function bbab_get_sr_total_hours(int $sr_id): float {
        return \BBAB\ServiceCenter\Modules\ServiceRequests\ServiceRequestService::getTotalHours($sr_id);
    }
}

if (!function_exists('bbab_generate_sr_reference')) {
    /**
     * Generate a new SR reference number.
     *
     * Compatibility wrapper in case any other code calls this function.
     *
     * @return string Reference number in SR-0001 format.
     */
    function bbab_generate_sr_reference(): string {
        return \BBAB\ServiceCenter\Modules\ServiceRequests\ReferenceGenerator::generate();
    }
}

if (!function_exists('bbab_generate_invoice_number')) {
    /**
     * Generate next invoice number in BBB-YYMM-NNN format.
     *
     * Compatibility wrapper - snippet 1995 deactivated in Phase 5.3.
     *
     * @param string|null $invoice_date Date string (any format parseable by strtotime).
     * @return string Next invoice number.
     */
    function bbab_generate_invoice_number(?string $invoice_date = null): string {
        return \BBAB\ServiceCenter\Modules\Billing\InvoiceReferenceGenerator::generateNumber($invoice_date);
    }
}

if (!function_exists('bbab_create_invoice_line_item')) {
    /**
     * Create a single invoice line item.
     *
     * Compatibility wrapper - snippet 1996 partially deactivated in Phase 5.3.
     *
     * @param int   $invoice_id Invoice post ID.
     * @param array $data       Line item data.
     * @return int|WP_Error Line item post ID or error.
     */
    function bbab_create_invoice_line_item(int $invoice_id, array $data): int|\WP_Error {
        return \BBAB\ServiceCenter\Modules\Billing\LineItemService::create($invoice_id, $data);
    }
}

if (!function_exists('bbab_generate_invoice_from_milestone')) {
    /**
     * Generate invoice from milestone.
     *
     * Compatibility wrapper - snippet 2398 deactivated in Phase 5.4.
     *
     * @param int $milestone_id Milestone post ID.
     * @return int|WP_Error Invoice ID or error.
     */
    function bbab_generate_invoice_from_milestone(int $milestone_id): int|\WP_Error {
        return \BBAB\ServiceCenter\Modules\Billing\InvoiceGenerator::fromMilestone($milestone_id);
    }
}

if (!function_exists('bbab_generate_closeout_invoice')) {
    /**
     * Generate closeout invoice from project.
     *
     * Compatibility wrapper - snippet 2463 deactivated in Phase 5.4.
     *
     * @param int $project_id Project post ID.
     * @return int|WP_Error Invoice ID or error.
     */
    function bbab_generate_closeout_invoice(int $project_id): int|\WP_Error {
        return \BBAB\ServiceCenter\Modules\Billing\InvoiceGenerator::closeoutFromProject($project_id);
    }
}

if (!function_exists('bbab_get_milestone_total_hours')) {
    /**
     * Get milestone total billable hours.
     *
     * Compatibility wrapper - snippet 2052 deactivated in Phase 5.4.
     *
     * @param int $milestone_id Milestone post ID.
     * @return float Total billable hours.
     */
    function bbab_get_milestone_total_hours(int $milestone_id): float {
        return \BBAB\ServiceCenter\Modules\Billing\InvoiceGenerator::getMilestoneTotalHours($milestone_id);
    }
}

if (!function_exists('bbab_get_report_total_hours')) {
    /**
     * Get total billable hours for a monthly report.
     *
     * Compatibility wrapper - snippet 1062 deactivated in Phase 6.1.
     * Called by [hours_progress_bar] shortcode.
     *
     * @param int $report_id Monthly report post ID.
     * @return float Total billable hours.
     */
    function bbab_get_report_total_hours(int $report_id): float {
        return \BBAB\ServiceCenter\Modules\Billing\MonthlyReportService::getTotalHours($report_id);
    }
}

if (!function_exists('bbab_get_report_free_hours_limit')) {
    /**
     * Get free hours limit for a monthly report.
     *
     * Compatibility wrapper - snippet 1062 deactivated in Phase 6.1.
     * Called by [hours_progress_bar] shortcode.
     *
     * @param int $report_id Monthly report post ID.
     * @return float Free hours limit.
     */
    function bbab_get_report_free_hours_limit(int $report_id): float {
        return \BBAB\ServiceCenter\Modules\Billing\MonthlyReportService::getFreeHoursLimit($report_id);
    }
}

if (!function_exists('bbab_generate_invoice_pdf')) {
    /**
     * Generate PDF for an invoice.
     *
     * Compatibility wrapper - snippet 2113 deactivated in Phase 6.2.
     *
     * @param int $invoice_id Invoice post ID.
     * @return string|WP_Error Path to generated PDF or error.
     */
    function bbab_generate_invoice_pdf(int $invoice_id): string|\WP_Error {
        return \BBAB\ServiceCenter\Modules\Billing\PDFService::generateInvoicePDF($invoice_id);
    }
}

// CRITICAL: Bootstrap simulation EARLY (before any other plugin code)
// This runs on plugins_loaded priority 1, before anything else queries data
add_action('plugins_loaded', function() {
    \BBAB\ServiceCenter\Core\SimulationBootstrap::init();
}, 1);

// Activation hook
register_activation_hook(__FILE__, function() {
    \BBAB\ServiceCenter\Core\Activator::activate();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    \BBAB\ServiceCenter\Core\Deactivator::deactivate();
});

// Initialize the plugin on plugins_loaded (normal priority)
add_action('plugins_loaded', function() {
    $plugin = new \BBAB\ServiceCenter\Core\Plugin();
    $plugin->run();
}, 10);
