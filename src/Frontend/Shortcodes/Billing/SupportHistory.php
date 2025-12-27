<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Billing;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Modules\Billing\MonthlyReportService;

/**
 * Support History shortcode.
 *
 * Displays a list of monthly reports for the user's organization
 * with PDF badges, hours usage, and overage indicators.
 *
 * Shortcode: [org_report_history]
 *
 * Attributes:
 * - limit: Number of reports to show (default: 12)
 *
 * Migrated from snippet: 1143
 */
class SupportHistory extends BaseShortcode {

    protected string $tag = 'org_report_history';
    protected bool $requires_org = true;

    /**
     * Render the support history list.
     *
     * @param array $atts   Shortcode attributes.
     * @param int   $org_id Organization ID.
     * @return string HTML output.
     */
    protected function output(array $atts, int $org_id): string {
        $atts = $this->parseAtts($atts, [
            'limit' => 12,
        ]);

        // Get monthly reports for this organization
        $reports = get_posts([
            'post_type' => 'monthly_report',
            'posts_per_page' => (int) $atts['limit'],
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'organization',
                'value' => $org_id,
                'compare' => '=',
            ]],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (empty($reports)) {
            return '<p>No reports found yet.</p>';
        }

        $hourly_rate = MonthlyReportService::getHourlyRate();

        // Start with CSS for mobile
        $output = '<style>
            @media (max-width: 767px) {
                .bbab-pdf-badge {
                    display: none !important;
                }
            }
        </style>';

        $output .= '<div class="bbab-report-history" style="font-family: Poppins, sans-serif;">';

        foreach ($reports as $report) {
            $report_month = get_post_meta($report->ID, 'report_month', true);
            $total_hours = MonthlyReportService::getTotalHours($report->ID);
            $limit = MonthlyReportService::getFreeHoursLimit($report->ID);
            $has_pdf = !empty(get_post_meta($report->ID, 'site_health_pdf', true));
            $report_url = get_permalink($report->ID);

            // Calculate overage
            $overage_html = '';
            if ($total_hours > $limit) {
                $overage_cost = ($total_hours - $limit) * $hourly_rate;
                $overage_html = '<span style="color: #e74c3c; font-weight: 600; margin-left: 10px;">+$' . number_format($overage_cost, 2) . '</span>';
            }

            // PDF badge (hidden on mobile via CSS above)
            $pdf_badge = $has_pdf
                ? '<span class="bbab-pdf-badge" style="background-color: #27ae60; color: white; font-size: 11px; padding: 2px 8px; border-radius: 3px; margin-left: 10px;">PDF</span>'
                : '';

            $output .= '<div style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; margin-bottom: 10px; background-color: #F3F5F8; border-radius: 6px;">';
            $output .= '<div style="display: flex; align-items: center; gap: 15px;">';
            $output .= '<a href="' . esc_url($report_url) . '" style="font-size: 18px; font-weight: 600; color: #1C244B; text-decoration: none;">' . esc_html($report_month) . '</a>';
            $output .= $pdf_badge;
            $output .= '<span style="font-size: 14px; color: #324A6D;">' . esc_html($total_hours) . ' / ' . esc_html($limit) . ' hrs</span>';
            $output .= $overage_html;
            $output .= '</div>';
            $output .= '<a href="' . esc_url($report_url) . '" style="color: #467FF7; text-decoration: none; font-weight: 600;">View &rarr;</a>';
            $output .= '</div>';
        }

        $output .= '</div>';

        return $output;
    }
}
