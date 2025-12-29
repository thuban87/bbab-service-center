<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Projects;

use BBAB\ServiceCenter\Utils\Settings;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Project Report PDF generation service.
 *
 * Generates professional PDF reports with:
 * - Cover page (optional) with project overview, deliverables, credentials, notes
 * - Time entry breakdown page showing billable and non-billable hours
 *
 * Requires Dompdf to be installed (in mu-plugins folder).
 *
 * Migrated from: WPCode Snippet #2674
 */
class ProjectReportPDFService {

    /**
     * Generate PDF for a project report.
     *
     * @param int $report_id Project Report post ID.
     * @return string|\WP_Error Path to generated PDF or error.
     */
    public static function generate(int $report_id) {
        $report = get_post($report_id);
        if (!$report || $report->post_type !== 'project_report') {
            return new \WP_Error('invalid_report', 'Project Report not found');
        }

        // Get report fields
        $report_number = get_post_meta($report_id, 'report_number', true);
        $report_date = get_post_meta($report_id, 'report_date', true);
        $report_type = get_post_meta($report_id, 'report_type', true);
        $include_cover = get_post_meta($report_id, 'include_cover_page', true);

        // Cover page content
        $cover_overview = get_post_meta($report_id, 'cover_project_overview', true);
        $cover_deliverables = get_post_meta($report_id, 'cover_deliverables', true);
        $cover_credentials = get_post_meta($report_id, 'cover_credentials', true);
        $cover_notes = get_post_meta($report_id, 'cover_custom_notes', true);

        // Get organization data
        $org_id = get_post_meta($report_id, 'organization', true);
        $org_name = '';
        if ($org_id) {
            $org_name = get_post_meta($org_id, 'name', true);
            if (empty($org_name)) {
                $org = get_post($org_id);
                $org_name = $org ? $org->post_title : '';
            }
        }
        $hourly_rate = floatval(get_post_meta($org_id, 'hourly_rate', true) ?: Settings::get('hourly_rate', 30));

        // Get project data
        $project_id = get_post_meta($report_id, 'related_project', true);
        $project_name = '';
        $project_ref = '';
        if ($project_id) {
            $project_name = get_post_meta($project_id, 'project_name', true) ?: get_the_title($project_id);
            $project_ref = get_post_meta($project_id, 'reference_number', true);
        }

        // Get time entries for the project
        $time_entries = self::getProjectTimeEntries((int) $project_id);

        // Format date
        $report_date_display = $report_date ? date('F j, Y', strtotime($report_date)) : date('F j, Y');

        // Build HTML
        $html = self::buildPDFHTML([
            'report_id' => $report_id,
            'report_number' => $report_number,
            'report_date' => $report_date_display,
            'report_type' => $report_type,
            'include_cover' => $include_cover === '1' || $include_cover === 'Yes',
            'cover_overview' => $cover_overview,
            'cover_deliverables' => $cover_deliverables,
            'cover_credentials' => $cover_credentials,
            'cover_notes' => $cover_notes,
            'org_name' => $org_name,
            'project_name' => $project_name,
            'project_ref' => $project_ref,
            'hourly_rate' => $hourly_rate,
            'time_entries' => $time_entries,
        ]);

        // Check if Dompdf is available
        if (!class_exists('\Dompdf\Dompdf')) {
            Logger::error('ProjectReportPDFService', 'Dompdf class not found');
            return new \WP_Error('dompdf_missing', 'PDF library not available');
        }

        // Initialize Dompdf
        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => true,
            'defaultFont' => 'sans-serif',
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        // Create upload directory
        $upload_dir = wp_upload_dir();
        $report_year = date('Y', strtotime($report_date ?: 'now'));
        $report_month = date('m', strtotime($report_date ?: 'now'));
        $pdf_dir = $upload_dir['basedir'] . "/project-reports/{$report_year}/{$report_month}";

        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
        }

        // Save PDF
        $base_filename = sanitize_file_name($report_number . '.pdf');
        $pdf_path_check = $pdf_dir . '/' . $base_filename;

        if (file_exists($pdf_path_check)) {
            $filename = sanitize_file_name($report_number . '-' . time() . '.pdf');
        } else {
            $filename = $base_filename;
        }

        $pdf_path = $pdf_dir . '/' . $filename;
        $pdf_url = $upload_dir['baseurl'] . "/project-reports/{$report_year}/{$report_month}/{$filename}";

        $write_result = file_put_contents($pdf_path, $dompdf->output());

        if ($write_result === false) {
            Logger::error('ProjectReportPDFService', 'Failed to write PDF file', ['path' => $pdf_path]);
            return new \WP_Error('write_failed', 'Could not save PDF file');
        }

        Logger::debug('ProjectReportPDFService', 'PDF generated', [
            'report_id' => $report_id,
            'path' => $pdf_path,
            'bytes' => $write_result,
        ]);

        // Attach to report via media library
        $attachment_id = self::attachPDFToReport($pdf_path, $pdf_url, $report_id, $report_number);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        // Update report with PDF attachment
        update_post_meta($report_id, 'report_pdf', $attachment_id);

        return $pdf_path;
    }

    /**
     * Get all time entries for a project (including milestones).
     *
     * @param int $project_id Project post ID.
     * @return array Array of time entry data.
     */
    private static function getProjectTimeEntries(int $project_id): array {
        if (empty($project_id)) {
            return [];
        }

        $time_entries = [];
        $seen_te_ids = [];

        // Get TEs linked directly to the project
        $project_tes = get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'related_project',
                'value' => $project_id,
                'compare' => '=',
            ]],
        ]);

        $project_ref = get_post_meta($project_id, 'reference_number', true) ?: 'PR-????';
        $project_name = get_post_meta($project_id, 'project_name', true) ?: get_the_title($project_id);

        foreach ($project_tes as $te) {
            if (in_array($te->ID, $seen_te_ids)) {
                continue;
            }
            $seen_te_ids[] = $te->ID;

            $time_entries[] = [
                'id' => $te->ID,
                'date' => get_post_meta($te->ID, 'entry_date', true),
                'reference' => $project_ref,
                'reference_title' => $project_name,
                'title' => $te->post_title,
                'description' => get_post_meta($te->ID, 'description', true),
                'hours' => floatval(get_post_meta($te->ID, 'hours', true)),
                'billable' => get_post_meta($te->ID, 'billable', true) !== '0',
            ];
        }

        // Get all milestones for this project
        $milestones = get_posts([
            'post_type' => 'milestone',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'related_project',
                'value' => $project_id,
                'compare' => '=',
            ]],
        ]);

        foreach ($milestones as $milestone) {
            $milestone_ref = get_post_meta($milestone->ID, 'reference_number', true) ?: $project_ref . '-??';
            $milestone_name = get_post_meta($milestone->ID, 'milestone_name', true) ?: $milestone->post_title;

            $milestone_tes = get_posts([
                'post_type' => 'time_entry',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [[
                    'key' => 'related_milestone',
                    'value' => $milestone->ID,
                    'compare' => '=',
                ]],
            ]);

            foreach ($milestone_tes as $te) {
                if (in_array($te->ID, $seen_te_ids)) {
                    continue;
                }
                $seen_te_ids[] = $te->ID;

                $time_entries[] = [
                    'id' => $te->ID,
                    'date' => get_post_meta($te->ID, 'entry_date', true),
                    'reference' => $milestone_ref,
                    'reference_title' => $milestone_name,
                    'title' => $te->post_title,
                    'description' => get_post_meta($te->ID, 'description', true),
                    'hours' => floatval(get_post_meta($te->ID, 'hours', true)),
                    'billable' => get_post_meta($te->ID, 'billable', true) !== '0',
                ];
            }
        }

        // Sort by date
        usort($time_entries, function ($a, $b) {
            return strtotime($a['date'] ?: '1970-01-01') - strtotime($b['date'] ?: '1970-01-01');
        });

        return $time_entries;
    }

    /**
     * Attach PDF to media library.
     *
     * @param string $pdf_path    Path to PDF file.
     * @param string $pdf_url     URL of PDF file.
     * @param int    $report_id   Report post ID.
     * @param string $report_num  Report number.
     * @return int|\WP_Error Attachment ID or error.
     */
    private static function attachPDFToReport(string $pdf_path, string $pdf_url, int $report_id, string $report_num) {
        $filetype = wp_check_filetype(basename($pdf_path), null);

        $attachment = [
            'guid' => $pdf_url,
            'post_mime_type' => $filetype['type'],
            'post_title' => 'Report ' . $report_num,
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        $attachment_id = wp_insert_attachment($attachment, $pdf_path, $report_id);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $pdf_path);
        wp_update_attachment_metadata($attachment_id, $attachment_data);

        return $attachment_id;
    }

    /**
     * Build the HTML for the PDF.
     *
     * @param array $data Report data.
     * @return string HTML string.
     */
    private static function buildPDFHTML(array $data): string {
        // Get logo URL from settings, site icon, or theme logo
        $logo_url = Settings::get('pdf_logo_url', '');
        if (empty($logo_url)) {
            // Try site icon first
            $logo_url = get_site_icon_url(256);
        }
        if (empty($logo_url)) {
            // Try custom logo from theme
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');
            }
        }

        // Report type colors
        $type_colors = [
            'Summary' => '#3b82f6',
            'Handoff' => '#8b5cf6',
            'Welcome Package' => '#14b8a6',
        ];
        $type_color = $type_colors[$data['report_type']] ?? '#3b82f6';

        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset=\"UTF-8\">
            <style>
                body {
                    font-family: sans-serif;
                    font-size: 13px;
                    color: #1f2937;
                    line-height: 1.5;
                    margin: 0;
                    padding: 30px;
                }
                .content-section {
                    margin-bottom: 20px;
                }
                .content-section h3 {
                    font-size: 14px;
                    color: #374151;
                    margin: 0 0 8px 0;
                    padding-bottom: 5px;
                    border-bottom: 2px solid #e5e7eb;
                }
                .content-section .content {
                    font-size: 12px;
                    color: #4b5563;
                }
            </style>
        </head>
        <body>";

        // Page 1: Cover page (if enabled)
        if ($data['include_cover']) {
            $html .= self::buildCoverPage($data, $logo_url, $type_color);
        }

        // Page 2: Time entry breakdown
        $html .= self::buildTimeEntriesPage($data, $logo_url);

        $html .= "
        </body>
        </html>";

        return $html;
    }

    /**
     * Build cover page HTML.
     *
     * @param array  $data       Report data.
     * @param string $logo_url   Logo URL.
     * @param string $type_color Report type color.
     * @return string HTML string.
     */
    private static function buildCoverPage(array $data, string $logo_url, string $type_color): string {
        $html = "
        <!-- COVER PAGE -->
        <div style=\"display: table; width: 100%; margin-bottom: 30px;\">
            <div style=\"display: table-cell; width: 50%; vertical-align: top;\">
                <img src=\"{$logo_url}\" alt=\"Brad's Bits & Bytes\" style=\"max-width: 160px; margin-bottom: 8px;\">
                <div style=\"font-size: 11px; color: #6b7280;\">brad@bradsbitsandbytes.com</div>
            </div>
            <div style=\"display: table-cell; width: 50%; vertical-align: top; text-align: right;\">
                <div style=\"font-size: 22px; font-weight: bold; color: #111827;\">PROJECT REPORT</div>
                <div style=\"font-size: 14px; margin-bottom: 8px;\">" . esc_html($data['report_number']) . "</div>
                <span style=\"display: inline-block; padding: 4px 12px; border-radius: 12px; color: white; font-size: 11px; font-weight: bold; background: {$type_color};\">" . esc_html($data['report_type']) . "</span>
            </div>
        </div>

        <div style=\"display: table; width: 100%; margin-bottom: 25px;\">
            <div style=\"display: table-cell; width: 50%; padding: 15px; background: #f9fafb; vertical-align: top; border-radius: 4px 0 0 4px;\">
                <div style=\"font-size: 10px; text-transform: uppercase; color: #6b7280; margin-bottom: 3px;\">Prepared For</div>
                <strong style=\"font-size: 14px;\">" . esc_html($data['org_name']) . "</strong>
            </div>
            <div style=\"display: table-cell; width: 50%; padding: 15px; background: #f9fafb; vertical-align: top; border-radius: 0 4px 4px 0;\">
                <div style=\"font-size: 10px; text-transform: uppercase; color: #6b7280; margin-bottom: 3px;\">Project</div>
                <strong style=\"font-size: 14px;\">" . esc_html($data['project_ref'] ? $data['project_ref'] . ' - ' : '') . esc_html($data['project_name']) . "</strong>
                <br><br>
                <div style=\"font-size: 10px; text-transform: uppercase; color: #6b7280; margin-bottom: 3px;\">Report Date</div>
                <strong style=\"font-size: 12px;\">" . esc_html($data['report_date']) . "</strong>
            </div>
        </div>";

        // Project Overview
        if (!empty($data['cover_overview'])) {
            $html .= "
            <div class=\"content-section\">
                <h3>Project Overview</h3>
                <div class=\"content\">" . wp_kses_post($data['cover_overview']) . "</div>
            </div>";
        }

        // Deliverables
        if (!empty($data['cover_deliverables'])) {
            $html .= "
            <div class=\"content-section\">
                <h3>Key Deliverables</h3>
                <div class=\"content\">" . wp_kses_post($data['cover_deliverables']) . "</div>
            </div>";
        }

        // Credentials
        if (!empty($data['cover_credentials'])) {
            $html .= "
            <div class=\"content-section\">
                <h3>Credentials & Access</h3>
                <div class=\"content\">" . wp_kses_post($data['cover_credentials']) . "</div>
            </div>";
        }

        // Custom Notes
        if (!empty($data['cover_notes'])) {
            $html .= "
            <div class=\"content-section\">
                <h3>Additional Notes</h3>
                <div class=\"content\">" . wp_kses_post($data['cover_notes']) . "</div>
            </div>";
        }

        return $html;
    }

    /**
     * Build time entries page HTML.
     *
     * Shows both billable and non-billable hours.
     * Total shows credit for non-billable so only billable is charged.
     *
     * @param array  $data     Report data.
     * @param string $logo_url Logo URL.
     * @return string HTML string.
     */
    private static function buildTimeEntriesPage(array $data, string $logo_url): string {
        $page_break = $data['include_cover'] ? '<div style="page-break-before: always;"></div>' : '';

        $billable_hours = 0;
        $non_billable_hours = 0;

        // Build TE rows
        $te_rows = '';
        foreach ($data['time_entries'] as $te) {
            $te_date = !empty($te['date']) ? date('M j', strtotime($te['date'])) : '';
            $te_ref = esc_html($te['reference'] ?? '');
            $te_desc = esc_html($te['title'] ?: $te['description']);
            $te_hours = number_format($te['hours'], 2);

            if ($te['billable']) {
                $billable_hours += $te['hours'];
                $row_style = '';
                $hours_style = '';
            } else {
                $non_billable_hours += $te['hours'];
                $row_style = 'color: #9ca3af; font-style: italic;';
                $hours_style = '';
            }

            $billable_marker = !$te['billable'] ? ' <span style="color: #ef4444;">(NB)</span>' : '';

            $te_rows .= "
            <tr style=\"{$row_style}\">
                <td style=\"padding: 5px 8px; border-bottom: 1px solid #e5e7eb; white-space: nowrap; font-size: 11px;\">{$te_date}</td>
                <td style=\"padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 11px;\">{$te_ref}</td>
                <td style=\"padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 11px;\">{$te_desc}{$billable_marker}</td>
                <td style=\"padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: right; font-size: 11px; {$hours_style}\">{$te_hours}</td>
            </tr>";
        }

        $total_hours = $billable_hours + $non_billable_hours;
        $hourly_rate = $data['hourly_rate'];
        $billable_amount = $billable_hours * $hourly_rate;
        $non_billable_credit = $non_billable_hours * $hourly_rate;

        $html = "
        {$page_break}

        <div style=\"display: table; width: 100%; margin-bottom: 20px;\">
            <div style=\"display: table-cell; width: 50%; vertical-align: middle;\">
                <img src=\"{$logo_url}\" alt=\"Brad's Bits & Bytes\" style=\"max-width: 120px;\">
            </div>
            <div style=\"display: table-cell; width: 50%; vertical-align: middle; text-align: right;\">
                <div style=\"font-size: 16px; font-weight: bold;\">Time Entry Details</div>
                <div style=\"font-size: 12px; color: #6b7280;\">Report " . esc_html($data['report_number']) . "</div>
            </div>
        </div>

        <div style=\"background: #f3f4f6; padding: 8px 12px; border-radius: 4px 4px 0 0; border-bottom: 2px solid #e5e7eb; margin-bottom: 0;\">
            <strong style=\"font-size: 12px;\">" . esc_html($data['project_ref'] ? $data['project_ref'] . ' - ' : '') . esc_html($data['project_name']) . "</strong>
        </div>

        <table style=\"width: 100%; border-collapse: collapse; font-size: 10px;\">
            <thead>
                <tr style=\"background: #fafafa;\">
                    <th style=\"padding: 6px 8px; text-align: left; color: #6b7280; border-bottom: 1px solid #e5e7eb; width: 55px;\">Date</th>
                    <th style=\"padding: 6px 8px; text-align: left; color: #6b7280; border-bottom: 1px solid #e5e7eb; width: 80px;\">Ref</th>
                    <th style=\"padding: 6px 8px; text-align: left; color: #6b7280; border-bottom: 1px solid #e5e7eb;\">Description</th>
                    <th style=\"padding: 6px 8px; text-align: right; color: #6b7280; border-bottom: 1px solid #e5e7eb; width: 50px;\">Hours</th>
                </tr>
            </thead>
            <tbody>
                {$te_rows}
            </tbody>
        </table>

        <div style=\"margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 6px; border: 2px solid #e5e7eb;\">
            <table style=\"width: 100%; font-size: 12px;\">
                <tr>
                    <td style=\"padding: 4px 0;\"><strong>Total Hours Logged:</strong></td>
                    <td style=\"padding: 4px 0; text-align: right;\">" . number_format($total_hours, 2) . " hrs</td>
                </tr>
                <tr>
                    <td style=\"padding: 4px 0;\"><strong>Billable Hours:</strong></td>
                    <td style=\"padding: 4px 0; text-align: right;\">" . number_format($billable_hours, 2) . " hrs</td>
                </tr>";

        if ($non_billable_hours > 0) {
            $html .= "
                <tr>
                    <td style=\"padding: 4px 0; color: #059669;\">Non-Billable Hours (complimentary):</td>
                    <td style=\"padding: 4px 0; text-align: right; color: #059669;\">" . number_format($non_billable_hours, 2) . " hrs</td>
                </tr>
                <tr>
                    <td style=\"padding: 4px 0;\"><strong>Hourly Rate:</strong></td>
                    <td style=\"padding: 4px 0; text-align: right;\">\$" . number_format($hourly_rate, 2) . "/hr</td>
                </tr>
                <tr>
                    <td style=\"padding: 4px 0;\">Gross Value ({$total_hours} hrs x \$" . number_format($hourly_rate, 2) . "):</td>
                    <td style=\"padding: 4px 0; text-align: right;\">\$" . number_format($total_hours * $hourly_rate, 2) . "</td>
                </tr>
                <tr>
                    <td style=\"padding: 4px 0; color: #059669;\">Non-Billable Credit:</td>
                    <td style=\"padding: 4px 0; text-align: right; color: #059669;\">-\$" . number_format($non_billable_credit, 2) . "</td>
                </tr>
                <tr style=\"font-size: 14px; border-top: 2px solid #374151;\">
                    <td style=\"padding: 10px 0 0 0;\"><strong>Total Billable Value:</strong></td>
                    <td style=\"padding: 10px 0 0 0; text-align: right;\"><strong>\$" . number_format($billable_amount, 2) . "</strong></td>
                </tr>";
        } else {
            $html .= "
                <tr>
                    <td style=\"padding: 4px 0;\"><strong>Hourly Rate:</strong></td>
                    <td style=\"padding: 4px 0; text-align: right;\">\$" . number_format($hourly_rate, 2) . "/hr</td>
                </tr>
                <tr style=\"font-size: 14px; border-top: 2px solid #374151;\">
                    <td style=\"padding: 10px 0 0 0;\"><strong>Total Value:</strong></td>
                    <td style=\"padding: 10px 0 0 0; text-align: right;\"><strong>\$" . number_format($billable_amount, 2) . "</strong></td>
                </tr>";
        }

        $html .= "
            </table>
        </div>

        <div style=\"margin-top: 15px; font-size: 10px; color: #9ca3af;\">
            NB = Non-Billable (complimentary work, shown for transparency)
        </div>";

        return $html;
    }
}
