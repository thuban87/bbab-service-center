<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Hosting;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Modules\Hosting\UptimeService;
use BBAB\ServiceCenter\Modules\Hosting\SSLService;
use BBAB\ServiceCenter\Modules\Hosting\BackupService;
use BBAB\ServiceCenter\Utils\Cache;

/**
 * Hosting Health Shortcode.
 *
 * Displays hosting health metrics (uptime, SSL, backups) for the current organization.
 * All data is read from cache - no API calls are made during rendering.
 *
 * Migrated from: WPCode Snippet #2240
 *
 * Usage:
 *   [hosting_health]              - Full section with all 3 metrics
 *   [hosting_health mode="card"]  - Compact single card (for Quick Looks)
 */
class HostingHealth extends BaseShortcode {

    protected string $tag = 'hosting_health';

    /**
     * Render the shortcode output.
     *
     * @param array $atts   Shortcode attributes
     * @param int   $org_id Organization ID
     * @return string Rendered HTML
     */
    protected function output(array $atts, int $org_id): string {
        $atts = $this->parseAtts($atts, [
            'mode' => 'section'  // 'section' or 'card'
        ]);

        // Check if ANY metrics are configured
        $has_uptime = !empty(get_post_meta($org_id, 'uptimerobot_monitor_id', true));
        $has_ssl = !empty(get_post_meta($org_id, 'site_url', true));
        $has_backup = !empty(get_post_meta($org_id, 'backup_folder_id', true)) &&
                      !empty(get_post_meta($org_id, 'backup_filename_match', true));

        // If nothing is configured, show nothing (per Brad's requirement)
        if (!$has_uptime && !$has_ssl && !$has_backup) {
            return '';
        }

        // Get cached health data
        $health = $this->getCachedHealthData($org_id);

        if ($atts['mode'] === 'card') {
            return $this->renderCard($health, $has_uptime, $has_ssl, $has_backup);
        }

        return $this->renderSection($health, $has_uptime, $has_ssl, $has_backup);
    }

    /**
     * Get cached health data from the combined cache key.
     *
     * @param int $org_id Organization ID
     * @return array|null Combined health data or null
     */
    private function getCachedHealthData(int $org_id): ?array {
        return Cache::get('health_data_' . $org_id);
    }

    /**
     * Render full health section.
     *
     * @param array|null $health     Health data
     * @param bool       $has_uptime Whether uptime is configured
     * @param bool       $has_ssl    Whether SSL is configured
     * @param bool       $has_backup Whether backup is configured
     * @return string HTML output
     */
    private function renderSection(?array $health, bool $has_uptime, bool $has_ssl, bool $has_backup): string {
        ob_start();
        ?>
        <div class="bbab-section bbab-health-section">
            <h3 class="bbab-section-title">Hosting Health</h3>

            <div class="bbab-health-grid">
                <?php
                // Uptime Card
                if ($has_uptime) {
                    echo $this->renderUptimeCard($health);
                }

                // SSL Card
                if ($has_ssl) {
                    echo $this->renderSSLCard($health);
                }

                // Backup Card
                if ($has_backup) {
                    echo $this->renderBackupCard($health);
                }
                ?>
            </div>

            <?php if ($health && isset($health['generated_at'])): ?>
                <div class="bbab-stats-updated">Updated <?php echo esc_html(wp_date('n/j/y @ g:ia', $health['generated_at'])); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render uptime metric card.
     */
    private function renderUptimeCard(?array $health): string {
        $uptime_data = $health['uptime'] ?? null;

        ob_start();
        ?>
        <div class="bbab-analytics-card bbab-health-metric-card">
            <div class="bbab-card-title">Uptime</div>
            <?php if (!$health): ?>
                <div class="bbab-health-pending">
                    <span class="bbab-health-pending-icon">...</span>
                    <span class="bbab-health-pending-text">Data pending</span>
                </div>
            <?php elseif (isset($uptime_data['error'])): ?>
                <div class="bbab-health-error">
                    <span class="bbab-health-error-icon">!</span>
                    <span class="bbab-health-error-text">The service used to track uptime, UptimeRobot, is experiencing technical difficulties at the moment.</span>
                </div>
            <?php elseif ($uptime_data):
                $pct = $uptime_data['uptime_percentage'];
                $rating = $pct >= 99.5 ? 'good' : ($pct >= 99 ? 'needs-improvement' : 'poor');
            ?>
                <div class="bbab-health-score bbab-cwv-<?php echo esc_attr($rating); ?>">
                    <span class="bbab-health-score-value"><?php echo esc_html(number_format($pct, 2)); ?>%</span>
                    <span class="bbab-health-score-label">Last 30 Days</span>
                </div>
                <div class="bbab-health-status-text bbab-status-<?php echo esc_attr($rating); ?>">
                    <?php
                    if ($rating === 'good') {
                        echo 'Excellent uptime';
                    } elseif ($rating === 'needs-improvement') {
                        echo 'Minor downtime detected';
                    } else {
                        echo 'Significant downtime';
                    }
                    ?>
                </div>
            <?php else: ?>
                <div class="bbab-health-pending">
                    <span class="bbab-health-pending-icon">...</span>
                    <span class="bbab-health-pending-text">Data pending</span>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render SSL metric card.
     */
    private function renderSSLCard(?array $health): string {
        $ssl_data = $health['ssl'] ?? null;

        ob_start();
        ?>
        <div class="bbab-analytics-card bbab-health-metric-card">
            <div class="bbab-card-title">SSL Certificate</div>
            <?php if (!$health): ?>
                <div class="bbab-health-pending">
                    <span class="bbab-health-pending-icon">...</span>
                    <span class="bbab-health-pending-text">Data pending</span>
                </div>
            <?php elseif (isset($ssl_data['error'])): ?>
                <div class="bbab-health-error">
                    <span class="bbab-health-error-icon">!</span>
                    <span class="bbab-health-error-text">Unable to check SSL certificate status.</span>
                </div>
            <?php elseif ($ssl_data):
                $days = $ssl_data['days_remaining'];
                $rating = $days > 30 ? 'good' : ($days > 14 ? 'needs-improvement' : 'poor');
            ?>
                <div class="bbab-health-score bbab-cwv-<?php echo esc_attr($rating); ?>">
                    <span class="bbab-health-score-value"><?php echo esc_html($days); ?></span>
                    <span class="bbab-health-score-label">Days Until Renewal</span>
                </div>
                <div class="bbab-health-status-text bbab-status-<?php echo esc_attr($rating); ?>">
                    <?php
                    if ($rating === 'good') {
                        echo 'Certificate valid';
                    } elseif ($rating === 'needs-improvement') {
                        echo 'Renewal approaching';
                    } else {
                        echo 'Renewal urgent';
                    }
                    ?>
                </div>
                <div class="bbab-health-detail">Expires: <?php echo esc_html(wp_date('M j, Y', strtotime($ssl_data['expiry_date']))); ?></div>
            <?php else: ?>
                <div class="bbab-health-pending">
                    <span class="bbab-health-pending-icon">...</span>
                    <span class="bbab-health-pending-text">Data pending</span>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render backup metric card.
     */
    private function renderBackupCard(?array $health): string {
        $backup_data = $health['backup'] ?? null;

        ob_start();
        ?>
        <div class="bbab-analytics-card bbab-health-metric-card">
            <div class="bbab-card-title">Cloud Backups</div>
            <?php if (!$health): ?>
                <div class="bbab-health-pending">
                    <span class="bbab-health-pending-icon">...</span>
                    <span class="bbab-health-pending-text">Data pending</span>
                </div>
            <?php elseif (isset($backup_data['error'])): ?>
                <div class="bbab-health-error">
                    <span class="bbab-health-error-icon">!</span>
                    <span class="bbab-health-error-text">Unable to verify backup status from cloud storage.</span>
                </div>
            <?php elseif ($backup_data):
                $hours = $backup_data['age_hours'];
                $rating = $hours <= 24 ? 'good' : ($hours <= 48 ? 'needs-improvement' : 'poor');

                // Format age display
                if ($hours < 24) {
                    $age_display = round($hours) . ' hours ago';
                } else {
                    $days_ago = floor($hours / 24);
                    $age_display = $days_ago . ' day' . ($days_ago > 1 ? 's' : '') . ' ago';
                }
            ?>
                <div class="bbab-health-score bbab-cwv-<?php echo esc_attr($rating); ?>">
                    <span class="bbab-health-score-value"><?php echo esc_html($age_display); ?></span>
                    <span class="bbab-health-score-label">Last Database Backup</span>
                </div>
                <div class="bbab-health-status-text bbab-status-<?php echo esc_attr($rating); ?>">
                    <?php
                    if ($rating === 'good') {
                        echo 'Backups current';
                    } elseif ($rating === 'needs-improvement') {
                        echo 'Backup slightly delayed';
                    } else {
                        echo 'Backup overdue';
                    }
                    ?>
                </div>
                <div class="bbab-health-detail"><?php echo esc_html(wp_date('M j, Y @ g:ia', strtotime($backup_data['created_at']))); ?></div>
            <?php else: ?>
                <div class="bbab-health-pending">
                    <span class="bbab-health-pending-icon">...</span>
                    <span class="bbab-health-pending-text">Data pending</span>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render compact health card (for Quick Looks tab).
     *
     * @param array|null $health     Health data
     * @param bool       $has_uptime Whether uptime is configured
     * @param bool       $has_ssl    Whether SSL is configured
     * @param bool       $has_backup Whether backup is configured
     * @return string HTML output
     */
    private function renderCard(?array $health, bool $has_uptime, bool $has_ssl, bool $has_backup): string {
        ob_start();
        ?>
        <div class="bbab-analytics-card bbab-health-compact-card">
            <div class="bbab-stat-label">Hosting Health <span class="bbab-stat-period">(Live Monitoring)</span></div>
            <div class="bbab-health-compact-grid">
                <?php if ($has_uptime):
                    $uptime_data = $health['uptime'] ?? null;
                    $pct = $uptime_data['uptime_percentage'] ?? null;
                    $rating = 'unknown';
                    $display = 'N/A';
                    if ($pct !== null && !isset($uptime_data['error'])) {
                        $rating = $pct >= 99.5 ? 'good' : ($pct >= 99 ? 'needs-improvement' : 'poor');
                        $display = number_format($pct, 1) . '%';
                    }
                ?>
                    <div class="bbab-health-compact-item bbab-cwv-<?php echo esc_attr($rating); ?>">
                        <span class="bbab-health-compact-value"><?php echo esc_html($display); ?></span>
                        <span class="bbab-health-compact-label">Uptime</span>
                    </div>
                <?php endif; ?>

                <?php if ($has_ssl):
                    $ssl_data = $health['ssl'] ?? null;
                    $days = $ssl_data['days_remaining'] ?? null;
                    $rating = 'unknown';
                    $display = 'N/A';
                    if ($days !== null && !isset($ssl_data['error'])) {
                        $rating = $days > 30 ? 'good' : ($days > 14 ? 'needs-improvement' : 'poor');
                        $display = $days . 'd';
                    }
                ?>
                    <div class="bbab-health-compact-item bbab-cwv-<?php echo esc_attr($rating); ?>">
                        <span class="bbab-health-compact-value"><?php echo esc_html($display); ?></span>
                        <span class="bbab-health-compact-label">SSL</span>
                    </div>
                <?php endif; ?>

                <?php if ($has_backup):
                    $backup_data = $health['backup'] ?? null;
                    $hours = $backup_data['age_hours'] ?? null;
                    $rating = 'unknown';
                    $display = 'N/A';
                    if ($hours !== null && !isset($backup_data['error'])) {
                        $rating = $hours <= 24 ? 'good' : ($hours <= 48 ? 'needs-improvement' : 'poor');
                        $display = $hours < 24 ? round($hours) . 'h' : floor($hours / 24) . 'd';
                    }
                ?>
                    <div class="bbab-health-compact-item bbab-cwv-<?php echo esc_attr($rating); ?>">
                        <span class="bbab-health-compact-value"><?php echo esc_html($display); ?></span>
                        <span class="bbab-health-compact-label">Backup</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
