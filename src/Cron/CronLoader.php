<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Cron;

use BBAB\ServiceCenter\Modules\Analytics\GA4Service;
use BBAB\ServiceCenter\Modules\Analytics\PageSpeedService;
use BBAB\ServiceCenter\Modules\Hosting\UptimeService;
use BBAB\ServiceCenter\Modules\Hosting\SSLService;
use BBAB\ServiceCenter\Modules\Hosting\BackupService;
use BBAB\ServiceCenter\Utils\Cache;
use BBAB\ServiceCenter\Utils\Logger;
use BBAB\ServiceCenter\Cron\ForgottenTimerHandler;

/**
 * Registers and handles all cron jobs.
 *
 * Uses Action Scheduler for staggered processing to avoid timeouts.
 * Each client org is processed 1 minute apart.
 */
class CronLoader {

    /**
     * Register cron hooks.
     */
    public function register(): void {
        // Register custom cron schedules
        add_filter('cron_schedules', [$this, 'addCronSchedules']);

        // Analytics dispatcher - runs at scheduled time, queues individual org jobs
        add_action('bbab_sc_analytics_cron', [$this, 'dispatchAnalyticsJobs']);

        // Analytics worker - processes ONE org at a time
        add_action('bbab_sc_analytics_worker', [$this, 'processOrgAnalytics']);

        // Hosting health - runs at scheduled time, processes all orgs inline
        add_action('bbab_sc_hosting_cron', [$this, 'dispatchHostingHealthJobs']);

        // Cleanup cron
        add_action('bbab_sc_cleanup_cron', [$this, 'runCleanup']);

        // Forgotten timer check - runs every 30 minutes
        add_action('bbab_sc_forgotten_timer_check', [ForgottenTimerHandler::class, 'check']);

        Logger::debug('CronLoader', 'Cron hooks registered');
    }

    /**
     * Add custom cron schedules.
     *
     * @param array $schedules Existing schedules.
     * @return array Modified schedules.
     */
    public function addCronSchedules(array $schedules): array {
        $schedules['thirty_minutes'] = [
            'interval' => 1800, // 30 minutes in seconds
            'display' => 'Every 30 Minutes',
        ];

        return $schedules;
    }

    /**
     * Dispatch analytics jobs - one per org, staggered 1 minute apart.
     *
     * Uses Action Scheduler if available, falls back to transient queue.
     */
    public function dispatchAnalyticsJobs(): void {
        $orgs = get_posts([
            'post_type' => 'client_organization',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        if (empty($orgs)) {
            Logger::debug('CronLoader', 'Analytics dispatch: No organizations found');
            return;
        }

        $scheduled_count = 0;

        // Check if Action Scheduler is available (from WooCommerce or standalone)
        if (function_exists('as_schedule_single_action')) {
            // Use Action Scheduler - preferred method
            foreach ($orgs as $index => $org) {
                $delay = $index * 60; // 0, 60, 120, 180 seconds...

                as_schedule_single_action(
                    time() + $delay,
                    'bbab_sc_analytics_worker',
                    ['org_id' => $org->ID],
                    'bbab-service-center'
                );

                $scheduled_count++;
            }

            Logger::debug('CronLoader', "Analytics dispatch: Scheduled $scheduled_count org jobs via Action Scheduler");
        } else {
            // Fallback: Process with delays in single execution
            // Less ideal but works without Action Scheduler
            Logger::debug('CronLoader', 'Analytics dispatch: Action Scheduler not available, processing inline');

            foreach ($orgs as $index => $org) {
                if ($index > 0) {
                    sleep(30); // 30 second delay between orgs in fallback mode
                }

                $this->processOrgAnalytics($org->ID);
                $scheduled_count++;

                // Check if we're running out of time (50 second safety margin)
                if ($index > 0 && (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) > 50) {
                    Logger::warning('CronLoader', "Analytics dispatch: Stopping early due to time limit after $scheduled_count orgs");
                    break;
                }
            }

            Logger::debug('CronLoader', "Analytics dispatch: Processed $scheduled_count orgs inline");
        }
    }

    /**
     * Process analytics for a single organization.
     *
     * Called by Action Scheduler (one org at a time) or inline fallback.
     *
     * @param int $org_id Organization post ID
     */
    public function processOrgAnalytics(int $org_id): void {
        $org = get_post($org_id);

        if (!$org) {
            Logger::error('CronLoader', "Analytics worker: Org ID $org_id not found");
            return;
        }

        $org_name = $org->post_title;
        Logger::debug('CronLoader', "Analytics worker: Starting $org_name (ID: $org_id)");

        $results = [
            'org' => $org_name,
            'ga4_core' => 'skip',
            'ga4_pages' => 'skip',
            'ga4_sources' => 'skip',
            'ga4_devices' => 'skip',
            'pagespeed' => 'skip',
        ];

        // Get field values
        $ga4_property_id = get_post_meta($org_id, 'ga4_property_id', true);
        $site_url = get_post_meta($org_id, 'site_url', true);

        // ---- GA4 DATA ----
        if (!empty($ga4_property_id)) {
            try {
                $result = GA4Service::fetchData($org_id);
                $results['ga4_core'] = $result ? 'ok' : 'error';
            } catch (\Exception $e) {
                $results['ga4_core'] = 'error';
                Logger::error('CronLoader', "GA4 core fetch failed for $org_name: " . $e->getMessage());
            }

            usleep(500000); // 0.5s delay

            try {
                $result = GA4Service::fetchTopPages($org_id, 5);
                $results['ga4_pages'] = $result ? 'ok' : 'error';
                usleep(300000);
                GA4Service::fetchTopPages($org_id, 10); // Also fetch top 10
            } catch (\Exception $e) {
                $results['ga4_pages'] = 'error';
                Logger::error('CronLoader', "GA4 pages fetch failed for $org_name: " . $e->getMessage());
            }

            usleep(500000);

            try {
                $result = GA4Service::fetchTrafficSources($org_id, 6);
                $results['ga4_sources'] = $result ? 'ok' : 'error';
            } catch (\Exception $e) {
                $results['ga4_sources'] = 'error';
                Logger::error('CronLoader', "GA4 sources fetch failed for $org_name: " . $e->getMessage());
            }

            usleep(500000);

            try {
                $result = GA4Service::fetchDevices($org_id);
                $results['ga4_devices'] = $result ? 'ok' : 'error';
            } catch (\Exception $e) {
                $results['ga4_devices'] = 'error';
                Logger::error('CronLoader', "GA4 devices fetch failed for $org_name: " . $e->getMessage());
            }
        }

        // ---- PAGESPEED DATA ----
        if (!empty($site_url)) {
            usleep(500000);

            try {
                $start = microtime(true);
                $result = PageSpeedService::fetchData($org_id);
                $duration = round(microtime(true) - $start, 2);
                $results['pagespeed'] = $result ? 'ok' : 'error';
                Logger::debug('CronLoader', "PageSpeed for $org_name completed in {$duration}s");
            } catch (\Exception $e) {
                $results['pagespeed'] = 'error';
                Logger::error('CronLoader', "PageSpeed fetch failed for $org_name: " . $e->getMessage());
            }
        }

        Logger::debug('CronLoader', "Analytics worker: Completed $org_name - " . wp_json_encode($results));
    }

    /**
     * Run hosting health checks for all organizations.
     *
     * Unlike analytics (which uses staggered Action Scheduler due to slow PageSpeed API),
     * hosting health checks are fast enough to run all orgs in sequence.
     */
    public function dispatchHostingHealthJobs(): void {
        $orgs = get_posts([
            'post_type' => 'client_organization',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        if (empty($orgs)) {
            Logger::debug('CronLoader', 'Hosting health: No organizations found');
            return;
        }

        $processed_count = 0;

        foreach ($orgs as $index => $org) {
            // Small delay between orgs to be nice to external APIs
            if ($index > 0) {
                usleep(500000); // 0.5 second
            }

            $this->processOrgHostingHealth($org->ID);
            $processed_count++;
        }

        Logger::debug('CronLoader', "Hosting health: Processed $processed_count orgs");
    }

    /**
     * Process hosting health for a single organization.
     *
     * Called by Action Scheduler (one org at a time) or inline fallback.
     * Fetches uptime, SSL, and backup data, then stores combined result.
     *
     * @param int $org_id Organization post ID
     */
    public function processOrgHostingHealth(int $org_id): void {
        $org = get_post($org_id);

        if (!$org) {
            Logger::error('CronLoader', "Hosting health worker: Org ID $org_id not found");
            return;
        }

        $org_name = $org->post_title;
        Logger::debug('CronLoader', "Hosting health worker: Starting $org_name (ID: $org_id)");

        $results = [
            'org' => $org_name,
            'uptime' => 'skip',
            'ssl' => 'skip',
            'backup' => 'skip',
        ];

        // Get field values to check what's configured
        $has_uptime = !empty(get_post_meta($org_id, 'uptimerobot_monitor_id', true));
        $has_ssl = !empty(get_post_meta($org_id, 'site_url', true));
        $has_backup = !empty(get_post_meta($org_id, 'backup_folder_id', true)) &&
                      !empty(get_post_meta($org_id, 'backup_filename_match', true));

        // Initialize health data array
        $health_data = [
            'org_id' => $org_id,
            'org_name' => $org_name,
            'generated_at' => time(), // Unix timestamp for proper timezone handling
            'uptime' => null,
            'ssl' => null,
            'backup' => null,
        ];

        // ---- UPTIME DATA ----
        if ($has_uptime) {
            try {
                $uptime_data = UptimeService::fetchData($org_id);
                $health_data['uptime'] = $uptime_data;
                $results['uptime'] = ($uptime_data && !isset($uptime_data['error'])) ? 'ok' : 'error';
            } catch (\Exception $e) {
                $results['uptime'] = 'error';
                $health_data['uptime'] = ['error' => $e->getMessage()];
                Logger::error('CronLoader', "Uptime fetch failed for $org_name: " . $e->getMessage());
            }

            usleep(300000); // 0.3s delay between API calls
        }

        // ---- SSL DATA ----
        if ($has_ssl) {
            try {
                $ssl_data = SSLService::fetchData($org_id);
                $health_data['ssl'] = $ssl_data;
                $results['ssl'] = ($ssl_data && !isset($ssl_data['error'])) ? 'ok' : 'error';
            } catch (\Exception $e) {
                $results['ssl'] = 'error';
                $health_data['ssl'] = ['error' => $e->getMessage()];
                Logger::error('CronLoader', "SSL check failed for $org_name: " . $e->getMessage());
            }

            usleep(300000); // 0.3s delay
        }

        // ---- BACKUP DATA ----
        if ($has_backup) {
            try {
                $backup_data = BackupService::fetchData($org_id);
                $health_data['backup'] = $backup_data;
                $results['backup'] = ($backup_data && !isset($backup_data['error'])) ? 'ok' : 'error';
            } catch (\Exception $e) {
                $results['backup'] = 'error';
                $health_data['backup'] = ['error' => $e->getMessage()];
                Logger::error('CronLoader', "Backup check failed for $org_name: " . $e->getMessage());
            }
        }

        // Store combined health data in a single cache key
        // Using 36 hour expiry as safety net in case cron fails one day
        Cache::set('health_data_' . $org_id, $health_data, 36 * HOUR_IN_SECONDS);

        Logger::debug('CronLoader', "Hosting health worker: Completed $org_name - " . wp_json_encode($results));
    }

    /**
     * Run hosting health checks for all organizations.
     *
     * Legacy method - calls dispatchHostingHealthJobs for backwards compatibility.
     */
    public function runHostingHealthChecks(): void {
        $this->dispatchHostingHealthJobs();
    }

    /**
     * Run cleanup tasks.
     */
    public function runCleanup(): void {
        Logger::debug('CronLoader', 'Cleanup cron: Starting');

        // Clean up old transients, logs, etc.
        // TODO: Implement cleanup logic

        Logger::debug('CronLoader', 'Cleanup cron: Completed');
    }
}
