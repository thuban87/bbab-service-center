<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Billing;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Modules\Billing\MonthlyReportService;

/**
 * Monthly Report Time Entries Table shortcode.
 *
 * Displays a table of time entries for a monthly report.
 *
 * Shortcode: [report_time_entries]
 *
 * Attributes:
 * - report_id: Monthly report post ID (default: current post)
 *
 * Migrated from snippet: 1068
 */
class MonthlyReportEntries extends BaseShortcode {

    protected string $tag = 'report_time_entries';
    protected bool $requires_org = false; // Uses report_id instead

    /**
     * Render the time entries table.
     *
     * @param array $atts   Shortcode attributes.
     * @param int   $org_id Organization ID (not used, we use report_id).
     * @return string HTML output.
     */
    protected function output(array $atts, int $org_id): string {
        $atts = $this->parseAtts($atts, [
            'report_id' => get_the_ID(),
        ]);

        $report_id = (int) $atts['report_id'];

        if (empty($report_id)) {
            return '<p>No report specified.</p>';
        }

        // Get time entries using the service
        $entries = MonthlyReportService::getTimeEntries($report_id);

        if (empty($entries)) {
            return '<p>No time entries recorded for this period.</p>';
        }

        // Build the table
        $output = '<table class="bbab-time-entries" style="width:100%; border-collapse: collapse;">';
        $output .= '<thead>';
        $output .= '<tr style="background-color: #f5f5f5;">';
        $output .= '<th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Date</th>';
        $output .= '<th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Description</th>';
        $output .= '<th style="padding: 10px; text-align: center; border-bottom: 2px solid #ddd;">Hours</th>';
        $output .= '<th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Type</th>';
        $output .= '</tr>';
        $output .= '</thead>';
        $output .= '<tbody>';

        foreach ($entries as $entry) {
            $date = get_post_meta($entry->ID, 'entry_date', true);
            $description = get_post_meta($entry->ID, 'description', true);
            $hours = get_post_meta($entry->ID, 'hours', true);
            $billable = get_post_meta($entry->ID, 'billable', true);

            // Get work type term
            $work_type_id = get_post_meta($entry->ID, 'work_type', true);
            $work_type = '';
            if (!empty($work_type_id)) {
                if (is_array($work_type_id)) {
                    $work_type_id = reset($work_type_id);
                }
                $term = get_term($work_type_id, 'work_type');
                if ($term && !is_wp_error($term)) {
                    $work_type = $term->name;
                }
            }

            // Format date nicely
            $formatted_date = '';
            if (!empty($date)) {
                $formatted_date = date('M j, Y', strtotime($date));
            }

            // Build hours display with non-billable badge if applicable
            $hours_display = esc_html($hours);
            if ($billable === '0' || $billable === 0 || $billable === false) {
                $hours_display .= ' <span style="display: inline-block; background: #d5f5e3; color: #1e8449; font-size: 11px; padding: 2px 6px; border-radius: 4px; margin-left: 4px;">No charge</span>';
            }

            $output .= '<tr>';
            $output .= '<td style="padding: 10px; border-bottom: 1px solid #eee;">' . esc_html($formatted_date) . '</td>';
            $output .= '<td style="padding: 10px; border-bottom: 1px solid #eee;">' . esc_html($description) . '</td>';
            $output .= '<td style="padding: 10px; text-align: center; border-bottom: 1px solid #eee;">' . $hours_display . '</td>';
            $output .= '<td style="padding: 10px; border-bottom: 1px solid #eee;">' . esc_html($work_type) . '</td>';
            $output .= '</tr>';
        }

        $output .= '</tbody>';
        $output .= '</table>';

        return $output;
    }
}
