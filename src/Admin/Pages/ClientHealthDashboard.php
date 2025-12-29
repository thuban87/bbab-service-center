<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Pages;

use BBAB\ServiceCenter\Utils\Cache;
use BBAB\ServiceCenter\Utils\Logger;
use BBAB\ServiceCenter\Modules\Analytics\GA4Service;
use BBAB\ServiceCenter\Modules\Analytics\PageSpeedService;
use BBAB\ServiceCenter\Cron\CronLoader;

/**
 * Client Health Dashboard Admin Page.
 *
 * Combines Hosting Health checks with Client Configuration Audit.
 * Located under Tools menu.
 *
 * Migrated from: WPCode Snippet #2310
 *
 * Section 1 uses the Hosting module services (UptimeService, SSLService, BackupService)
 * Section 2 checks client configuration and Analytics cache status
 */
class ClientHealthDashboard {

    /**
     * Register the admin page.
     */
    public function register(): void {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('wp_ajax_bbab_sc_fetch_single_analytics', [$this, 'handleSingleAnalyticsFetch']);
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
        // Check if viewing a specific organization detail
        if (isset($_GET['org']) && absint($_GET['org']) > 0) {
            $this->renderOrgDetailPage(absint($_GET['org']));
            return;
        }

        // Handle manual run (Section 1 only)
        if (isset($_POST['run_health_check']) && check_admin_referer('bbab_run_health_check')) {
            $start = microtime(true);
            $cron_loader = new CronLoader();
            $cron_loader->runHostingHealthChecks();
            $elapsed = round(microtime(true) - $start, 2);
            echo '<div class="notice notice-success"><p>Hosting health check completed in ' . esc_html($elapsed) . ' seconds!</p></div>';
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
        $next_health_run = wp_next_scheduled('bbab_sc_hosting_cron');
        $next_analytics_run = wp_next_scheduled('bbab_sc_analytics_cron');

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
                            echo '<span style="color: red;">Not scheduled!</span> Ensure the Hosting Health Cron is active.';
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
                            $health = Cache::get('health_data_' . $org->ID);
                            $detail_url = admin_url('tools.php?page=client-health-dashboard&org=' . $org->ID);
                        ?>
                        <tr class="bbab-clickable-row" data-href="<?php echo esc_url($detail_url); ?>">
                            <td class="bbab-org-name">
                                <a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($org->post_title); ?></a>
                            </td>
                            <td><?php echo $this->renderUptimeCell($health); ?></td>
                            <td><?php echo $this->renderSSLCell($health); ?></td>
                            <td><?php echo $this->renderBackupCell($health); ?></td>
                            <td><?php echo $this->renderCacheTimeCell($health); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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

                <div style="margin-bottom: 20px; padding: 10px 15px; background: #f0fff4; border: 1px solid #9ae6b4; border-radius: 4px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
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
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <form method="post" style="display: inline-block;">
                                <?php wp_nonce_field('bbab_refresh_analytics'); ?>
                                <button type="submit" name="refresh_analytics_now" class="button button-primary" onclick="return confirm('This will fetch fresh data from GA4 and PageSpeed APIs for all clients. This may take a minute. Continue?');">
                                    Refresh All
                                </button>
                            </form>
                            <form method="post" style="display: inline-block;">
                                <?php wp_nonce_field('bbab_clear_analytics_cache'); ?>
                                <button type="submit" name="clear_analytics_cache" class="button" onclick="return confirm('Clear all cached analytics data?');">
                                    Clear Cache
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Per-client Analytics Fetch -->
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #9ae6b4; display: flex; align-items: center; gap: 10px;">
                        <span style="font-weight: 500;">Fetch Single Client:</span>
                        <select id="bbab-single-analytics-org" style="min-width: 200px;">
                            <option value="">-- Select Client --</option>
                            <?php foreach ($orgs as $org):
                                $shortcode = get_post_meta($org->ID, 'organization_shortcode', true);
                                $label = $shortcode ? $shortcode . ' - ' . $org->post_title : $org->post_title;
                            ?>
                                <option value="<?php echo esc_attr($org->ID); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="bbab-fetch-single-analytics" class="button">
                            Fetch Analytics
                        </button>
                        <span id="bbab-single-analytics-status" style="font-size: 13px;"></span>
                    </div>
                </div>

                <script>
                jQuery(document).ready(function($) {
                    $('#bbab-fetch-single-analytics').on('click', function() {
                        var $btn = $(this);
                        var $status = $('#bbab-single-analytics-status');
                        var $select = $('#bbab-single-analytics-org');
                        var orgId = $select.val();

                        if (!orgId) {
                            $status.html('<span style="color: #c53030;">Please select a client.</span>');
                            return;
                        }

                        var orgName = $select.find('option:selected').text();
                        $btn.prop('disabled', true);
                        $select.prop('disabled', true);
                        $status.html('<span style="color: #666;">Fetching analytics for ' + orgName + '... (30-60 sec)</span>');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            timeout: 120000,
                            data: {
                                action: 'bbab_sc_fetch_single_analytics',
                                org_id: orgId,
                                nonce: '<?php echo wp_create_nonce('bbab_sc_fetch_single_analytics'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $status.html('<span style="color: #38a169; font-weight: 500;">' + response.data.message + '</span>');
                                    // Reload page after 2 seconds to show updated cache times
                                    setTimeout(function() {
                                        location.reload();
                                    }, 2000);
                                } else {
                                    $status.html('<span style="color: #c53030;">Error: ' + (response.data.message || response.data) + '</span>');
                                    $btn.prop('disabled', false);
                                    $select.prop('disabled', false);
                                }
                            },
                            error: function(xhr, status, error) {
                                var msg = status === 'timeout' ? 'Request timed out. The fetch may still be running.' : 'Request failed.';
                                $status.html('<span style="color: #c53030;">' + msg + '</span>');
                                $btn.prop('disabled', false);
                                $select.prop('disabled', false);
                            }
                        });
                    });
                });
                </script>

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

        $detail_url = admin_url('tools.php?page=client-health-dashboard&org=' . $org_id);

        ob_start();
        ?>
        <tr class="bbab-clickable-row" data-href="<?php echo esc_url($detail_url); ?>">
            <td class="bbab-org-name">
                <a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($org->post_title); ?></a>
            </td>
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
            $timestamp = $health['generated_at'];
            return esc_html(wp_date('M j, Y @ g:ia', $timestamp)) .
                '<span class="bbab-subtext">' . esc_html(human_time_diff($timestamp)) . ' ago</span>';
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
                background: linear-gradient(135deg, #1d2327 0%, #2c3338 100%);
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
            /* Clickable rows */
            .bbab-clickable-row {
                cursor: pointer;
            }
            .bbab-clickable-row:hover {
                background: #e8f4fc !important;
            }
            .bbab-org-name a {
                color: #2271b1;
                text-decoration: none;
                font-weight: 600;
            }
            .bbab-org-name a:hover {
                text-decoration: underline;
            }
            /* Detail page styles */
            .bbab-detail-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }
            .bbab-detail-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
                gap: 20px;
            }
            .bbab-detail-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                overflow: hidden;
            }
            .bbab-detail-card h3 {
                background: #f8f9fa;
                margin: 0;
                padding: 12px 15px;
                font-size: 14px;
                border-bottom: 1px solid #e2e4e7;
            }
            .bbab-detail-card-content {
                padding: 15px;
            }
            .bbab-detail-row {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #f0f0f0;
            }
            .bbab-detail-row:last-child {
                border-bottom: none;
            }
            .bbab-detail-label {
                color: #666;
                font-size: 13px;
            }
            .bbab-detail-value {
                font-weight: 500;
                text-align: right;
            }
        </style>
        <script>
        jQuery(document).ready(function($) {
            // Make entire row clickable
            $('.bbab-clickable-row').on('click', function(e) {
                // Don't navigate if clicking on a link
                if ($(e.target).is('a')) return;
                window.location.href = $(this).data('href');
            });
        });
        </script>
        <?php
    }

    /**
     * Handle AJAX request to fetch analytics for a single organization.
     */
    public function handleSingleAnalyticsFetch(): void {
        if (!check_ajax_referer('bbab_sc_fetch_single_analytics', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }

        $org_id = isset($_POST['org_id']) ? absint($_POST['org_id']) : 0;
        if (!$org_id) {
            wp_send_json_error(['message' => 'No organization specified']);
            return;
        }

        // Verify org exists
        $org = get_post($org_id);
        if (!$org || $org->post_type !== 'client_organization') {
            wp_send_json_error(['message' => 'Invalid organization']);
            return;
        }

        $org_name = $org->post_title;
        $results = [];

        // Get org meta
        $ga4_property_id = get_post_meta($org_id, 'ga4_property_id', true);
        $site_url = get_post_meta($org_id, 'site_url', true);

        // Increase time limit for API calls
        set_time_limit(120);

        // Fetch GA4 data
        if (!empty($ga4_property_id)) {
            try {
                $result = GA4Service::fetchData($org_id);
                $results['ga4_core'] = $result ? 'ok' : 'failed';
            } catch (\Exception $e) {
                $results['ga4_core'] = 'error: ' . $e->getMessage();
            }

            usleep(500000); // 0.5s delay

            try {
                $result = GA4Service::fetchTopPages($org_id, 5);
                $results['ga4_pages'] = $result ? 'ok' : 'failed';
                usleep(300000);
                GA4Service::fetchTopPages($org_id, 10);
            } catch (\Exception $e) {
                $results['ga4_pages'] = 'error';
            }

            usleep(500000);

            try {
                $result = GA4Service::fetchTrafficSources($org_id, 6);
                $results['ga4_sources'] = $result ? 'ok' : 'failed';
            } catch (\Exception $e) {
                $results['ga4_sources'] = 'error';
            }

            usleep(500000);

            try {
                $result = GA4Service::fetchDevices($org_id);
                $results['ga4_devices'] = $result ? 'ok' : 'failed';
            } catch (\Exception $e) {
                $results['ga4_devices'] = 'error';
            }
        } else {
            $results['ga4'] = 'skipped (no property ID)';
        }

        // Fetch PageSpeed data
        if (!empty($site_url)) {
            usleep(500000);

            try {
                $result = PageSpeedService::fetchData($org_id);
                $results['pagespeed'] = $result ? 'ok' : 'failed';
            } catch (\Exception $e) {
                $results['pagespeed'] = 'error: ' . $e->getMessage();
            }
        } else {
            $results['pagespeed'] = 'skipped (no site URL)';
        }

        // Build summary message
        $ok_count = count(array_filter($results, fn($v) => $v === 'ok'));
        $total = count($results);
        $message = "Completed for {$org_name}: {$ok_count}/{$total} successful.";

        if (in_array('failed', $results) || count(array_filter($results, fn($v) => str_starts_with((string) $v, 'error'))) > 0) {
            $message .= ' Check debug.log for details.';
        }

        Logger::debug('ClientHealthDashboard', 'Single client analytics fetch completed', [
            'org_id' => $org_id,
            'org_name' => $org_name,
            'results' => $results,
        ]);

        wp_send_json_success([
            'message' => $message,
            'results' => $results,
        ]);
    }

    /**
     * Render detail page for a single organization.
     *
     * @param int $org_id Organization post ID.
     */
    private function renderOrgDetailPage(int $org_id): void {
        $org = get_post($org_id);

        if (!$org || $org->post_type !== 'client_organization') {
            echo '<div class="wrap"><h1>Organization Not Found</h1><p>Invalid organization ID.</p></div>';
            return;
        }

        // Get all org data
        $site_url = get_post_meta($org_id, 'site_url', true);
        $ga4_id = get_post_meta($org_id, 'ga4_property_id', true);
        $stripe_id = get_post_meta($org_id, 'stripe_customer_id', true);
        $uptime_id = get_post_meta($org_id, 'uptimerobot_monitor_id', true);
        $backup_folder = get_post_meta($org_id, 'backup_folder_id', true);
        $backup_match = get_post_meta($org_id, 'backup_filename_match', true);
        $free_hours = get_post_meta($org_id, 'free_hours_limit', true);
        $hourly_rate = get_post_meta($org_id, 'hourly_rate', true);
        $contact_email = get_post_meta($org_id, 'contact_email', true);
        $contact_phone = get_post_meta($org_id, 'contact_phone', true);
        $address = get_post_meta($org_id, 'address', true);
        $city = get_post_meta($org_id, 'city', true);
        $state = get_post_meta($org_id, 'state', true);
        $zip = get_post_meta($org_id, 'zip_code', true);
        $contract_type = get_post_meta($org_id, 'contract_type', true);
        $renewal_date = get_post_meta($org_id, 'contract_renewal_date', true);
        $shortcode = get_post_meta($org_id, 'organization_shortcode', true);

        // Get cached health data
        $health = Cache::get('health_data_' . $org_id);

        // Get analytics cache status
        $ga4_cache = !empty($ga4_id) ? Cache::get('ga4_data_' . $ga4_id) : null;
        $psi_cache = !empty($site_url) ? Cache::get('cwv_' . md5($site_url)) : null;

        // Get assigned users
        $assigned_users = get_users([
            'meta_key' => 'organization',
            'meta_value' => $org_id,
        ]);

        // Count related items
        $project_count = count(get_posts([
            'post_type' => 'project',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [['key' => 'organization', 'value' => $org_id]],
            'fields' => 'ids',
        ]));

        $sr_count = count(get_posts([
            'post_type' => 'service_request',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [['key' => 'organization', 'value' => $org_id]],
            'fields' => 'ids',
        ]));

        $invoice_count = count(get_posts([
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [['key' => 'organization', 'value' => $org_id]],
            'fields' => 'ids',
        ]));

        $back_url = admin_url('tools.php?page=client-health-dashboard');
        $edit_url = get_edit_post_link($org_id);

        $this->renderStyles();
        ?>
        <div class="wrap bbab-dashboard-wrap">
            <div class="bbab-detail-header">
                <h1>
                    <a href="<?php echo esc_url($back_url); ?>" style="text-decoration: none; color: #999;">&larr;</a>
                    <?php echo esc_html($org->post_title); ?>
                    <?php if ($shortcode): ?>
                        <span style="font-size: 14px; font-weight: normal; color: #666; margin-left: 10px;">(<?php echo esc_html($shortcode); ?>)</span>
                    <?php endif; ?>
                </h1>
                <div>
                    <a href="<?php echo esc_url($edit_url); ?>" class="button">Edit Organization</a>
                    <a href="<?php echo esc_url($back_url); ?>" class="button">Back to Dashboard</a>
                </div>
            </div>

            <div class="bbab-detail-grid">
                <!-- Basic Info Card -->
                <div class="bbab-detail-card">
                    <h3>Basic Information</h3>
                    <div class="bbab-detail-card-content">
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Site URL</span>
                            <span class="bbab-detail-value">
                                <?php if ($site_url): ?>
                                    <a href="<?php echo esc_url($site_url); ?>" target="_blank"><?php echo esc_html($site_url); ?></a>
                                <?php else: ?>
                                    <span class="bbab-status-critical">Not set</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Contact Email</span>
                            <span class="bbab-detail-value">
                                <?php if ($contact_email): ?>
                                    <a href="mailto:<?php echo esc_attr($contact_email); ?>"><?php echo esc_html($contact_email); ?></a>
                                <?php else: ?>
                                    <span class="bbab-status-warning">Not set</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Phone</span>
                            <span class="bbab-detail-value"><?php echo $contact_phone ? esc_html($contact_phone) : '<span class="bbab-status-na">—</span>'; ?></span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Address</span>
                            <span class="bbab-detail-value" style="text-align: right; max-width: 200px;">
                                <?php if ($address): ?>
                                    <?php echo esc_html($address); ?><br>
                                    <?php echo esc_html("$city, $state $zip"); ?>
                                <?php else: ?>
                                    <span class="bbab-status-warning">Not set</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Billing Card -->
                <div class="bbab-detail-card">
                    <h3>Billing Configuration</h3>
                    <div class="bbab-detail-card-content">
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Free Hours</span>
                            <span class="bbab-detail-value"><?php echo $free_hours !== '' ? esc_html($free_hours) . ' hrs' : '<span class="bbab-status-warning">Not set</span>'; ?></span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Hourly Rate</span>
                            <span class="bbab-detail-value"><?php echo $hourly_rate !== '' ? '$' . esc_html($hourly_rate) : '<span class="bbab-status-warning">Not set</span>'; ?></span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Stripe Customer ID</span>
                            <span class="bbab-detail-value"><?php echo $stripe_id ? '<span class="bbab-status-good">&#10003; Configured</span>' : '<span class="bbab-status-critical">Not set</span>'; ?></span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Contract Type</span>
                            <span class="bbab-detail-value"><?php echo $contract_type ? esc_html($contract_type) : '<span class="bbab-status-na">—</span>'; ?></span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Contract Renewal</span>
                            <span class="bbab-detail-value"><?php echo $this->getContractRenewalHtml($renewal_date); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Integrations Card -->
                <div class="bbab-detail-card">
                    <h3>Integrations</h3>
                    <div class="bbab-detail-card-content">
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">GA4 Property ID</span>
                            <span class="bbab-detail-value"><?php echo $ga4_id ? '<code>' . esc_html($ga4_id) . '</code>' : '<span class="bbab-status-critical">Not set</span>'; ?></span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">UptimeRobot Monitor ID</span>
                            <span class="bbab-detail-value"><?php echo $uptime_id ? '<code>' . esc_html($uptime_id) . '</code>' : '<span class="bbab-status-critical">Not set</span>'; ?></span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Backup Folder ID</span>
                            <span class="bbab-detail-value"><?php echo $backup_folder ? '<span class="bbab-status-good">&#10003; Configured</span>' : '<span class="bbab-status-critical">Not set</span>'; ?></span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Backup Filename Match</span>
                            <span class="bbab-detail-value"><?php echo $backup_match ? '<code>' . esc_html($backup_match) . '</code>' : '<span class="bbab-status-na">—</span>'; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Health Status Card -->
                <div class="bbab-detail-card">
                    <h3>Health Status</h3>
                    <div class="bbab-detail-card-content">
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Uptime (30 days)</span>
                            <span class="bbab-detail-value"><?php echo $this->renderUptimeCell($health); ?></span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">SSL Certificate</span>
                            <span class="bbab-detail-value"><?php echo $this->renderSSLCell($health); ?></span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Last Backup</span>
                            <span class="bbab-detail-value"><?php echo $this->renderBackupCell($health); ?></span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Health Data Generated</span>
                            <span class="bbab-detail-value"><?php echo $this->renderCacheTimeCell($health); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Analytics Cache Card -->
                <div class="bbab-detail-card">
                    <h3>Analytics Cache</h3>
                    <div class="bbab-detail-card-content">
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">GA4 Data Last Fetched</span>
                            <span class="bbab-detail-value">
                                <?php
                                if (!$ga4_id) {
                                    echo '<span class="bbab-status-na">Not configured</span>';
                                } elseif ($ga4_cache && isset($ga4_cache['fetched_at'])) {
                                    $hours = (time() - $ga4_cache['fetched_at']) / 3600;
                                    $class = $hours <= 26 ? 'good' : ($hours <= 48 ? 'warning' : 'critical');
                                    echo '<span class="bbab-status-' . $class . '">' . round($hours) . ' hours ago</span>';
                                } else {
                                    echo '<span class="bbab-status-critical">Never</span>';
                                }
                                ?>
                            </span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">PageSpeed Data Last Fetched</span>
                            <span class="bbab-detail-value">
                                <?php
                                if (!$site_url) {
                                    echo '<span class="bbab-status-na">Not configured</span>';
                                } elseif ($psi_cache && isset($psi_cache['fetched_at'])) {
                                    $hours = (time() - $psi_cache['fetched_at']) / 3600;
                                    $class = $hours <= 26 ? 'good' : ($hours <= 48 ? 'warning' : 'critical');
                                    echo '<span class="bbab-status-' . $class . '">' . round($hours) . ' hours ago</span>';
                                } else {
                                    echo '<span class="bbab-status-critical">Never</span>';
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Activity Summary Card -->
                <div class="bbab-detail-card">
                    <h3>Activity Summary</h3>
                    <div class="bbab-detail-card-content">
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Assigned Users</span>
                            <span class="bbab-detail-value">
                                <?php if (count($assigned_users) > 0): ?>
                                    <span class="bbab-status-good"><?php echo count($assigned_users); ?></span>
                                    <br><small style="color: #666;"><?php echo esc_html(implode(', ', wp_list_pluck($assigned_users, 'display_name'))); ?></small>
                                <?php else: ?>
                                    <span class="bbab-status-critical">None</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Total Projects</span>
                            <span class="bbab-detail-value">
                                <a href="<?php echo esc_url(admin_url('edit.php?post_type=project&org_filter=' . $org_id)); ?>"><?php echo esc_html($project_count); ?></a>
                            </span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Total Service Requests</span>
                            <span class="bbab-detail-value">
                                <a href="<?php echo esc_url(admin_url('edit.php?post_type=service_request&org_filter=' . $org_id)); ?>"><?php echo esc_html($sr_count); ?></a>
                            </span>
                        </div>
                        <div class="bbab-detail-row">
                            <span class="bbab-detail-label">Total Invoices</span>
                            <span class="bbab-detail-value">
                                <a href="<?php echo esc_url(admin_url('edit.php?post_type=invoice&org_filter=' . $org_id)); ?>"><?php echo esc_html($invoice_count); ?></a>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
