<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Pages;

use BBAB\ServiceCenter\Utils\Cache;
use BBAB\ServiceCenter\Utils\Logger;
use BBAB\ServiceCenter\Modules\Analytics\GA4Service;
use BBAB\ServiceCenter\Modules\Analytics\PageSpeedService;

/**
 * Client Health Dashboard Admin Page.
 *
 * Combines Hosting Health checks with Client Configuration Audit.
 * Located under Tools menu.
 *
 * Migrated from: WPCode Snippet #2310
 *
 * Note: Section 1 (Hosting Health) requires Phase 3 hosting services.
 * Until then, it will show "Pending Phase 3 migration" message.
 */
class ClientHealthDashboard {

    /**
     * Register the admin page.
     */
    public function register(): void {
        add_action('admin_menu', [$this, 'addMenuPage']);
    }

    /**
     * Add the submenu page under Tools.
     */
    public function addMenuPage(): void {
        add_submenu_page(
            'tools.php',
            'Client Health Dashboard',
            'Client Health Dashboard',
            'manage_options',
            'client-health-dashboard',
            [$this, 'renderPage']
        );
    }

    /**
     * Render the admin page.
     */
    public function renderPage(): void {
        // Handle manual run (Section 1 only) - requires Phase 3
        if (isset($_POST['run_health_check']) && check_admin_referer('bbab_run_health_check')) {
            if (function_exists('bbab_run_hosting_health_checks')) {
                $start = microtime(true);
                bbab_run_hosting_health_checks();
                $elapsed = round(microtime(true) - $start, 2);
                echo '<div class="notice notice-success"><p>Hosting health check completed in ' . esc_html($elapsed) . ' seconds!</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p>Hosting health checks not yet available. Requires Phase 3 migration.</p></div>';
            }
        }

        // Handle clear cache
        if (isset($_POST['clear_health_cache']) && check_admin_referer('bbab_clear_health_cache')) {
            Cache::flushPattern('health_data_');
            echo '<div class="notice notice-success"><p>Hosting health cache cleared!</p></div>';
        }

        // Handle clear analytics cache
        if (isset($_POST['clear_analytics_cache']) && check_admin_referer('bbab_clear_analytics_cache')) {
            Cache::flushPattern('ga4_');
            Cache::flushPattern('cwv_');
            echo '<div class="notice notice-success"><p>Analytics cache cleared!</p></div>';
        }

        // Handle refresh analytics now
        if (isset($_POST['refresh_analytics_now']) && check_admin_referer('bbab_refresh_analytics')) {
            $this->runAnalyticsRefresh();
        }

        $orgs = get_posts([
            'post_type' => 'client_organization',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        ]);

        // Get next scheduled runs
        $next_health_run = wp_next_scheduled('bbab_hosting_health_cron');
        $next_analytics_run = wp_next_scheduled('bbab_analytics_cron');

        // Current month for report check
        $current_month = date('F Y');

        $this->renderStyles();
        ?>
        <div class="wrap bbab-dashboard-wrap">
            <h1>Client Health Dashboard</h1>

            <!-- SECTION 1: HOSTING HEALTH -->
            <?php $this->renderHostingHealthSection($orgs, $next_health_run); ?>

            <!-- SECTION 2: CLIENT CONFIGURATION AUDIT -->
            <?php $this->renderConfigAuditSection($orgs, $next_analytics_run, $current_month); ?>

            <!-- Color Key -->
            <div class="bbab-color-key">
                <strong>Legend:</strong>
                <span class="bbab-legend-item"><span class="bbab-status-good">&#9679;</span> Good / Set</span>
                <span class="bbab-legend-item"><span class="bbab-status-warning">&#9679;</span> Warning / Partial</span>
                <span class="bbab-legend-item"><span class="bbab-status-critical">&#9679;</span> Critical / Missing</span>
                <span class="bbab-legend-item"><span class="bbab-status-na">&#9679;</span> Not Applicable</span>
                &nbsp;&nbsp;|&nbsp;&nbsp;
                <strong>Report:</strong> Checks for <?php echo esc_html($current_month); ?> report
                &nbsp;&nbsp;|&nbsp;&nbsp;
                <strong>GA4/PSI Fetch:</strong> Hours since last successful cache refresh
            </div>
        </div>
        <?php
    }

    /**
     * Render Section 1: Hosting Health.
     */
    private function renderHostingHealthSection(array $orgs, $next_health_run): void {
        ?>
        <div class="bbab-section-box">
            <h2 class="bbab-section-header">Section 1: Hosting Health <span style="font-weight: normal; font-size: 13px; opacity: 0.9;">(Live API Checks)</span></h2>
            <div class="bbab-section-content">

                <div class="bbab-cron-box">
                    <div class="bbab-cron-info">
                        <strong>Next Hosting Health Run:</strong>
                        <?php
                        if ($next_health_run) {
                            echo esc_html(wp_date('M j, Y @ g:ia', $next_health_run));
                            echo ' &mdash; <em>in ' . esc_html(human_time_diff(time(), $next_health_run)) . '</em>';
                        } else {
                            echo '<span style="color: red;">Not scheduled!</span> Requires Phase 3 hosting cron.';
                        }
                        ?>
                    </div>

                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('bbab_run_health_check'); ?>
                        <button type="submit" name="run_health_check" class="button button-primary">
                            Run Health Check Now
                        </button>
                    </form>

                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('bbab_clear_health_cache'); ?>
                        <button type="submit" name="clear_health_cache" class="button" onclick="return confirm('Clear all cached hosting health data?');">
                            Clear Cache
                        </button>
                    </form>
                </div>

                <?php if (!function_exists('bbab_get_cached_health_data')): ?>
                    <div style="margin-top: 20px; padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">
                        <strong>Hosting Health data not yet available.</strong><br>
                        This section requires Phase 3 (Hosting Health Module) to be migrated.
                        Once complete, uptime, SSL, and backup status will appear here.
                    </div>
                <?php else: ?>
                    <table class="bbab-health-table" style="margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>Organization</th>
                                <th>Uptime (30d)</th>
                                <th>SSL Expiry</th>
                                <th>Last Backup</th>
                                <th>Cache Generated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orgs as $org):
                                $health = bbab_get_cached_health_data($org->ID);
                            ?>
                            <tr>
                                <td class="bbab-org-name"><?php echo esc_html($org->post_title); ?></td>
                                <td><?php echo $this->renderUptimeCell($health); ?></td>
                                <td><?php echo $this->renderSSLCell($health); ?></td>
                                <td><?php echo $this->renderBackupCell($health); ?></td>
                                <td><?php echo $this->renderCacheTimeCell($health); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Section 2: Client Configuration Audit.
     */
    private function renderConfigAuditSection(array $orgs, $next_analytics_run, string $current_month): void {
        ?>
        <div class="bbab-section-box">
            <h2 class="bbab-section-header section-2">Section 2: Client Configuration Audit <span style="font-weight: normal; font-size: 13px; opacity: 0.9;">(Instant DB Reads)</span></h2>
            <div class="bbab-section-content">

                <div style="margin-bottom: 20px; padding: 10px 15px; background: #f0fff4; border: 1px solid #9ae6b4; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong>Next Analytics Prefetch:</strong>
                        <?php
                        if ($next_analytics_run) {
                            echo esc_html(wp_date('M j, Y @ g:ia', $next_analytics_run));
                            echo ' &mdash; <em>in ' . esc_html(human_time_diff(time(), $next_analytics_run)) . '</em>';
                        } else {
                            echo '<span style="color: #c53030;">Not scheduled!</span> Ensure the Analytics Cron is active.';
                        }
                        ?>
                        <span style="color: #666; font-size: 12px; margin-left: 10px;">(GA4 + PageSpeed data refreshed nightly)</span>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <form method="post" style="display: inline-block;">
                            <?php wp_nonce_field('bbab_refresh_analytics'); ?>
                            <button type="submit" name="refresh_analytics_now" class="button button-primary" onclick="return confirm('This will fetch fresh data from GA4 and PageSpeed APIs for all clients. This may take a minute. Continue?');">
                                Refresh Analytics Now
                            </button>
                        </form>
                        <form method="post" style="display: inline-block;">
                            <?php wp_nonce_field('bbab_clear_analytics_cache'); ?>
                            <button type="submit" name="clear_analytics_cache" class="button" onclick="return confirm('Clear all cached analytics data?');">
                                Clear Analytics Cache
                            </button>
                        </form>
                    </div>
                </div>

                <table class="bbab-health-table">
                    <thead>
                        <tr>
                            <th style="width: 140px;">Organization</th>
                            <th>Site URL</th>
                            <th>GA4 ID</th>
                            <th>Stripe ID</th>
                            <th>Uptime ID</th>
                            <th>Backup Folder</th>
                            <th>Free Hrs</th>
                            <th>Rate</th>
                            <th>Address</th>
                            <th>Contract</th>
                            <th>Report</th>
                            <th>GA4 Fetch</th>
                            <th>PSI Fetch</th>
                            <th>Users</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orgs as $org):
                            echo $this->renderConfigRow($org, $current_month);
                        endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single config audit row.
     */
    private function renderConfigRow(\WP_Post $org, string $current_month): string {
        $org_id = $org->ID;

        // Core Setup
        $site_url = get_post_meta($org_id, 'site_url', true);
        $ga4_id = get_post_meta($org_id, 'ga4_property_id', true);
        $stripe_id = get_post_meta($org_id, 'stripe_customer_id', true);
        $uptime_id = get_post_meta($org_id, 'uptimerobot_monitor_id', true);
        $backup_folder = get_post_meta($org_id, 'backup_folder_id', true);

        // Billing Setup
        $free_hours = get_post_meta($org_id, 'free_hours_limit', true);
        $hourly_rate = get_post_meta($org_id, 'hourly_rate', true);

        // Address
        $address = get_post_meta($org_id, 'address', true);
        $city = get_post_meta($org_id, 'city', true);
        $state = get_post_meta($org_id, 'state', true);
        $zip = get_post_meta($org_id, 'zip_code', true);
        $address_complete = !empty($address) && !empty($city) && !empty($state) && !empty($zip);
        $address_partial = !empty($address) || !empty($city) || !empty($state) || !empty($zip);

        // Contract
        $renewal_date = get_post_meta($org_id, 'contract_renewal_date', true);
        $renewal_html = $this->getContractRenewalHtml($renewal_date);

        // Monthly Report Check
        $report_check = get_posts([
            'post_type' => 'monthly_report',
            'posts_per_page' => 1,
            'post_status' => 'publish',
            'meta_query' => [
                ['key' => 'organization', 'value' => $org_id],
                ['key' => 'report_month', 'value' => $current_month]
            ]
        ]);
        $has_report = !empty($report_check);

        // Analytics cache status - use our Cache utility keys
        $ga4_cache = !empty($ga4_id) ? Cache::get('ga4_data_' . $ga4_id) : null;
        $ga4_last_fetch = ($ga4_cache && isset($ga4_cache['fetched_at'])) ? $ga4_cache['fetched_at'] : false;

        $psi_cache = !empty($site_url) ? Cache::get('cwv_' . md5($site_url)) : null;
        $psi_last_fetch = ($psi_cache && isset($psi_cache['fetched_at'])) ? $psi_cache['fetched_at'] : false;

        // Users Assigned
        $assigned_users = get_users([
            'meta_key' => 'organization',
            'meta_value' => $org_id,
            'fields' => 'ID'
        ]);
        $user_count = count($assigned_users);

        ob_start();
        ?>
        <tr>
            <td class="bbab-org-name"><?php echo esc_html($org->post_title); ?></td>
            <td><?php echo $this->checkIcon($site_url); ?></td>
            <td><?php echo $this->checkIcon($ga4_id); ?></td>
            <td><?php echo $this->checkIcon($stripe_id); ?></td>
            <td><?php echo $this->checkIcon($uptime_id); ?></td>
            <td><?php echo $this->checkIcon($backup_folder); ?></td>
            <td>
                <?php if ($free_hours !== '' && $free_hours !== false): ?>
                    <span class="bbab-status-good"><?php echo esc_html($free_hours); ?>h</span>
                <?php else: ?>
                    <span class="bbab-status-warning">&mdash;</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($hourly_rate !== '' && $hourly_rate !== false): ?>
                    <span class="bbab-status-good">$<?php echo esc_html($hourly_rate); ?></span>
                <?php else: ?>
                    <span class="bbab-status-warning">&mdash;</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($address_complete): ?>
                    <span class="bbab-check-icon bbab-status-good">&#10003;</span>
                <?php elseif ($address_partial): ?>
                    <span class="bbab-check-icon bbab-status-warning">!</span>
                    <span class="bbab-subtext">Partial</span>
                <?php else: ?>
                    <span class="bbab-check-icon bbab-status-critical">&#10007;</span>
                <?php endif; ?>
            </td>
            <td><?php echo $renewal_html; ?></td>
            <td>
                <?php if ($has_report): ?>
                    <span class="bbab-check-icon bbab-status-good">&#10003;</span>
                <?php else: ?>
                    <span class="bbab-check-icon bbab-status-warning">&#10007;</span>
                    <span class="bbab-subtext"><?php echo esc_html(date('M')); ?></span>
                <?php endif; ?>
            </td>
            <td><?php echo $this->renderFetchAge($ga4_id, $ga4_last_fetch); ?></td>
            <td><?php echo $this->renderFetchAge($site_url, $psi_last_fetch); ?></td>
            <td>
                <?php if ($user_count > 0): ?>
                    <span class="bbab-status-good"><?php echo esc_html($user_count); ?></span>
                <?php else: ?>
                    <span class="bbab-status-critical">0</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Render check/x icon for config fields.
     */
    private function checkIcon($value): string {
        if (!empty($value)) {
            return '<span class="bbab-check-icon bbab-status-good">&#10003;</span>';
        }
        return '<span class="bbab-check-icon bbab-status-critical">&#10007;</span>';
    }

    /**
     * Get contract renewal HTML.
     */
    private function getContractRenewalHtml(?string $renewal_date): string {
        if (empty($renewal_date)) {
            return '<span class="bbab-status-na">&mdash;</span>';
        }

        $renewal_ts = strtotime($renewal_date);
        $days_until = floor(($renewal_ts - time()) / DAY_IN_SECONDS);

        if ($days_until < 0) {
            return '<span class="bbab-status-badge bbab-badge-critical">' . abs($days_until) . 'd overdue</span>';
        } elseif ($days_until <= 30) {
            return '<span class="bbab-status-badge bbab-badge-warning">' . $days_until . 'd left</span>';
        } else {
            return '<span class="bbab-status-badge bbab-badge-good">' . date('n/j/y', $renewal_ts) . '</span>';
        }
    }

    /**
     * Render fetch age display.
     */
    private function renderFetchAge($config_value, $last_fetch): string {
        if (empty($config_value)) {
            return '<span class="bbab-status-na">&mdash;</span>';
        }

        if (!$last_fetch) {
            return '<span class="bbab-status-critical">Never</span>';
        }

        $hours_ago = (time() - $last_fetch) / 3600;
        $class = $hours_ago <= 26 ? 'good' : ($hours_ago <= 48 ? 'warning' : 'critical');

        return '<span class="bbab-status-' . $class . '">' . round($hours_ago) . 'h</span>';
    }

    /**
     * Render uptime cell (for Phase 3).
     */
    private function renderUptimeCell($health): string {
        if (!$health) {
            return '<span class="bbab-status-na">No data</span>';
        }
        if ($health['uptime'] === null) {
            return '<span class="bbab-status-na">Not configured</span>';
        }
        if (isset($health['uptime']['error'])) {
            return '<span class="bbab-status-critical">' . esc_html($health['uptime']['error']) . '</span>';
        }

        $pct = $health['uptime']['uptime_percentage'];
        $class = $pct >= 99.5 ? 'good' : ($pct >= 99 ? 'warning' : 'critical');

        return '<span class="bbab-status-' . $class . '">' . number_format($pct, 2) . '%</span>';
    }

    /**
     * Render SSL cell (for Phase 3).
     */
    private function renderSSLCell($health): string {
        if (!$health) {
            return '<span class="bbab-status-na">No data</span>';
        }
        if ($health['ssl'] === null) {
            return '<span class="bbab-status-na">Not configured</span>';
        }
        if (isset($health['ssl']['error'])) {
            return '<span class="bbab-status-critical">' . esc_html($health['ssl']['error']) . '</span>';
        }

        $days = $health['ssl']['days_remaining'];
        $class = $days > 30 ? 'good' : ($days > 14 ? 'warning' : 'critical');

        return '<span class="bbab-status-' . $class . '">' . $days . ' days</span>
            <span class="bbab-subtext">Expires: ' . esc_html($health['ssl']['expiry_date']) . '</span>';
    }

    /**
     * Render backup cell (for Phase 3).
     */
    private function renderBackupCell($health): string {
        if (!$health) {
            return '<span class="bbab-status-na">No data</span>';
        }
        if ($health['backup'] === null) {
            return '<span class="bbab-status-na">Not configured</span>';
        }
        if (isset($health['backup']['error'])) {
            return '<span class="bbab-status-critical">' . esc_html($health['backup']['error']) . '</span>';
        }

        $hours = $health['backup']['age_hours'];
        $class = $hours <= 24 ? 'good' : ($hours <= 48 ? 'warning' : 'critical');

        return '<span class="bbab-status-' . $class . '">' . round($hours, 1) . 'h ago</span>
            <span class="bbab-subtext">' . esc_html($health['backup']['filename']) . '</span>';
    }

    /**
     * Render cache time cell (for Phase 3).
     */
    private function renderCacheTimeCell($health): string {
        if ($health && isset($health['generated_at'])) {
            return esc_html(wp_date('M j, Y @ g:ia', strtotime($health['generated_at']))) .
                '<span class="bbab-subtext">' . esc_html(human_time_diff(strtotime($health['generated_at']))) . ' ago</span>';
        }
        return '<span class="bbab-status-na">&mdash;</span>';
    }

    /**
     * Run analytics refresh for all organizations.
     */
    private function runAnalyticsRefresh(): void {
        $start = microtime(true);
        $results = [
            'ga4_success' => 0,
            'ga4_failed' => 0,
            'psi_success' => 0,
            'psi_failed' => 0,
        ];

        $orgs = get_posts([
            'post_type' => 'client_organization',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);

        foreach ($orgs as $org) {
            $org_id = $org->ID;

            // GA4 Data
            $ga4_property = get_post_meta($org_id, 'ga4_property_id', true);
            if (!empty($ga4_property)) {
                $ga4_data = GA4Service::fetchData($org_id);
                if ($ga4_data) {
                    GA4Service::fetchTopPages($org_id);
                    GA4Service::fetchTrafficSources($org_id);
                    GA4Service::fetchDevices($org_id);
                    $results['ga4_success']++;
                } else {
                    $results['ga4_failed']++;
                }
            }

            // PageSpeed Data
            $site_url = get_post_meta($org_id, 'site_url', true);
            if (!empty($site_url)) {
                $psi_data = PageSpeedService::fetchData($org_id);
                if ($psi_data) {
                    $results['psi_success']++;
                } else {
                    $results['psi_failed']++;
                }
            }
        }

        $elapsed = round(microtime(true) - $start, 2);

        echo '<div class="notice notice-success"><p>';
        echo '<strong>Analytics refresh completed in ' . esc_html($elapsed) . ' seconds.</strong><br>';
        echo 'GA4: ' . esc_html($results['ga4_success']) . ' success, ' . esc_html($results['ga4_failed']) . ' failed<br>';
        echo 'PageSpeed: ' . esc_html($results['psi_success']) . ' success, ' . esc_html($results['psi_failed']) . ' failed';
        echo '</p></div>';

        Logger::debug('ClientHealthDashboard', 'Manual analytics refresh completed', $results);
    }

    /**
     * Render page styles.
     */
    private function renderStyles(): void {
        ?>
        <style>
            .bbab-dashboard-wrap {
                max-width: 1400px;
            }
            .bbab-section-box {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                margin-bottom: 20px;
                overflow: hidden;
            }
            .bbab-section-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                padding: 15px 20px;
                margin: 0;
                font-size: 16px;
                font-weight: 600;
            }
            .bbab-section-header.section-2 {
                background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            }
            .bbab-section-content {
                padding: 20px;
            }
            .bbab-health-table {
                width: 100%;
                border-collapse: collapse;
            }
            .bbab-health-table th {
                background: #f8f9fa;
                padding: 12px 10px;
                text-align: left;
                font-weight: 600;
                border-bottom: 2px solid #e2e4e7;
                font-size: 12px;
                text-transform: uppercase;
                color: #555;
            }
            .bbab-health-table td {
                padding: 12px 10px;
                border-bottom: 1px solid #f0f0f0;
                vertical-align: middle;
            }
            .bbab-health-table tr:hover {
                background: #f8f9fa;
            }
            .bbab-org-name {
                font-weight: 600;
                color: #1d2327;
            }
            .bbab-status-good { color: #46b450; font-weight: 600; }
            .bbab-status-warning { color: #ffb900; font-weight: 600; }
            .bbab-status-critical { color: #dc3232; font-weight: 600; }
            .bbab-status-na { color: #999; font-style: italic; }
            .bbab-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
            }
            .bbab-badge-good { background: #d4edda; color: #155724; }
            .bbab-badge-warning { background: #fff3cd; color: #856404; }
            .bbab-badge-critical { background: #f8d7da; color: #721c24; }
            .bbab-badge-na { background: #e9ecef; color: #6c757d; }
            .bbab-check-icon { font-size: 14px; }
            .bbab-subtext {
                display: block;
                font-size: 11px;
                color: #666;
                margin-top: 2px;
            }
            .bbab-cron-box {
                display: flex;
                align-items: center;
                gap: 20px;
                flex-wrap: wrap;
            }
            .bbab-cron-info { flex: 1; }
            .bbab-color-key {
                margin-top: 15px;
                padding: 10px 15px;
                background: #f8f9fa;
                border-radius: 4px;
                font-size: 13px;
            }
            .bbab-legend-item {
                display: inline-block;
                margin-right: 20px;
            }
        </style>
        <?php
    }
}
