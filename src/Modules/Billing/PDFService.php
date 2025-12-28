<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Billing;

use BBAB\ServiceCenter\Utils\Settings;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Invoice PDF generation service.
 *
 * Generates professional PDF invoices with:
 * - Summary page with line items
 * - Detail page with time entries
 * - Support for Standard (monthly) and Project invoices
 * - Closeout invoice with milestone grouping
 *
 * Requires Dompdf to be installed (in mu-plugins folder).
 *
 * Migrated from snippet: 2113
 */
class PDFService {

    /**
     * Generate PDF for an invoice.
     *
     * @param int $invoice_id Invoice post ID.
     * @return string|\WP_Error Path to generated PDF or error.
     */
    public static function generateInvoicePDF(int $invoice_id) {
        $invoice = get_post($invoice_id);
        if (!$invoice || $invoice->post_type !== 'invoice') {
            return new \WP_Error('invalid_invoice', 'Invoice not found');
        }

        // Get invoice fields
        $invoice_number = get_post_meta($invoice_id, 'invoice_number', true);
        $invoice_date = get_post_meta($invoice_id, 'invoice_date', true);
        $due_date = get_post_meta($invoice_id, 'due_date', true);
        $amount = floatval(get_post_meta($invoice_id, 'amount', true));
        $invoice_status = get_post_meta($invoice_id, 'invoice_status', true);
        $invoice_type = get_post_meta($invoice_id, 'invoice_type', true);
        $total_hours = floatval(get_post_meta($invoice_id, 'total_hours', true));
        $free_hours_applied = floatval(get_post_meta($invoice_id, 'free_hours_applied', true));
        $overage_hours = floatval(get_post_meta($invoice_id, 'overage_hours', true));
        $non_billable_hours = floatval(get_post_meta($invoice_id, 'non_billable_hours', true));
        $invoice_notes = get_post_meta($invoice_id, 'invoice_notes', true);

        // Get organization data
        $org_id = get_post_meta($invoice_id, 'organization', true);
        $org_name = '';
        if ($org_id) {
            // Use Pods 'name' field (preferred) with fallback to post_title
            $org_name = get_post_meta($org_id, 'name', true);
            if (empty($org_name)) {
                $org = get_post($org_id);
                $org_name = $org ? $org->post_title : '';
            }
        }
        $hourly_rate = floatval(get_post_meta($org_id, 'hourly_rate', true) ?: Settings::get('hourly_rate', 30));
        $free_hours_limit = floatval(get_post_meta($org_id, 'free_hours', true) ?: Settings::get('default_free_hours', 2));

        // Get address fields
        $org_address = get_post_meta($org_id, 'address', true) ?: '';
        if (is_array($org_address)) {
            $org_address = implode(', ', array_filter($org_address));
        }
        $org_city = get_post_meta($org_id, 'city', true) ?: '';
        if (is_array($org_city)) {
            $org_city = reset($org_city);
        }
        $org_state = get_post_meta($org_id, 'state', true) ?: '';
        if (is_array($org_state)) {
            $org_state = reset($org_state);
        }
        $org_zip = get_post_meta($org_id, 'zip_code', true) ?: '';
        if (is_array($org_zip)) {
            $org_zip = reset($org_zip);
        }

        // Get billing settings
        $payment_terms_days = get_post_meta($org_id, 'payment_terms_days', true) ?: 5;
        $late_fee_percentage = get_post_meta($org_id, 'late_fee_percentage', true) ?: 5;

        // Get period display
        $period_display = '';
        $report_id = get_post_meta($invoice_id, 'related_monthly_report', true);
        if ($report_id) {
            $period_display = get_post_meta($report_id, 'report_month', true);
        }

        $project_id = get_post_meta($invoice_id, 'related_project', true);
        if ($project_id && empty($period_display)) {
            $period_display = get_post_meta($project_id, 'project_name', true) ?: get_the_title($project_id);
        }

        // Get line items
        $line_item_posts = get_posts([
            'post_type' => 'invoice_line_item',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'related_invoice',
                'value' => $invoice_id,
                'compare' => '=',
            ]],
            'meta_key' => 'display_order',
            'orderby' => 'meta_value_num',
            'order' => 'ASC',
        ]);

        $line_items = [];
        $subtotal = 0;
        foreach ($line_item_posts as $item) {
            $item_amount = floatval(get_post_meta($item->ID, 'amount', true));
            $line_items[] = [
                'type' => get_post_meta($item->ID, 'line_type', true),
                'description' => get_post_meta($item->ID, 'description', true),
                'quantity' => floatval(get_post_meta($item->ID, 'quantity', true)),
                'rate' => floatval(get_post_meta($item->ID, 'rate', true)),
                'amount' => $item_amount,
            ];
            $subtotal += $item_amount;
        }

        // Get time entries for detail page
        $time_entries = self::getInvoiceTimeEntries($invoice_id);

        // Get billing model (flat_rate vs hourly) for milestone invoices
        $billing_model = get_post_meta($invoice_id, 'billing_model', true) ?: '';

        // Format dates
        $invoice_date_display = $invoice_date ? date('F j, Y', strtotime($invoice_date)) : '';
        $due_date_display = $due_date ? date('F j, Y', strtotime($due_date)) : '';

        // Build HTML
        $html = self::buildPDFHTML([
            'invoice_id' => $invoice_id,
            'invoice_number' => $invoice_number,
            'invoice_date' => $invoice_date_display,
            'due_date' => $due_date_display,
            'period' => $period_display,
            'status' => $invoice_status,
            'invoice_type' => $invoice_type,
            'org_name' => $org_name,
            'org_address' => $org_address,
            'org_city' => $org_city,
            'org_state' => $org_state,
            'org_zip' => $org_zip,
            'line_items' => $line_items,
            'subtotal' => $subtotal,
            'total' => $amount,
            'total_hours' => $total_hours,
            'free_hours_applied' => $free_hours_applied,
            'free_hours_limit' => $free_hours_limit,
            'overage_hours' => $overage_hours,
            'non_billable_hours' => $non_billable_hours,
            'hourly_rate' => $hourly_rate,
            'payment_terms_days' => $payment_terms_days,
            'late_fee_percentage' => $late_fee_percentage,
            'notes' => $invoice_notes,
            'closeout_data' => get_post_meta($invoice_id, 'closeout_data', true),
            'time_entries' => $time_entries,
            'billing_model' => $billing_model,
        ]);

        // Check if Dompdf is available
        if (!class_exists('\Dompdf\Dompdf')) {
            Logger::error('PDFService', 'Dompdf class not found');
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
        $invoice_year = date('Y', strtotime($invoice_date ?: 'now'));
        $invoice_month = date('m', strtotime($invoice_date ?: 'now'));
        $pdf_dir = $upload_dir['basedir'] . "/invoices/{$invoice_year}/{$invoice_month}";

        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
        }

        // Save PDF
        $base_filename = sanitize_file_name($invoice_number . '.pdf');
        $pdf_path_check = $pdf_dir . '/' . $base_filename;

        if (file_exists($pdf_path_check)) {
            $filename = sanitize_file_name($invoice_number . '-' . time() . '.pdf');
        } else {
            $filename = $base_filename;
        }

        $pdf_path = $pdf_dir . '/' . $filename;
        $pdf_url = $upload_dir['baseurl'] . "/invoices/{$invoice_year}/{$invoice_month}/{$filename}";

        $write_result = file_put_contents($pdf_path, $dompdf->output());

        if ($write_result === false) {
            Logger::error('PDFService', 'Failed to write PDF file', ['path' => $pdf_path]);
            return new \WP_Error('write_failed', 'Could not save PDF file');
        }

        Logger::debug('PDFService', 'PDF generated', [
            'invoice_id' => $invoice_id,
            'path' => $pdf_path,
            'bytes' => $write_result,
        ]);

        // Attach to invoice via media library
        $attachment_id = self::attachPDFToInvoice($pdf_path, $pdf_url, $invoice_id, $invoice_number);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        // Update invoice with PDF attachment
        update_post_meta($invoice_id, 'invoice_pdf', $attachment_id);

        return $pdf_path;
    }

    /**
     * Get all time entries associated with an invoice.
     *
     * @param int $invoice_id Invoice post ID.
     * @return array Array of time entry data.
     */
    public static function getInvoiceTimeEntries(int $invoice_id): array {
        $invoice_type = get_post_meta($invoice_id, 'invoice_type', true);
        $time_entries = [];

        if ($invoice_type === 'Standard') {
            $time_entries = self::getMonthlyInvoiceTEs($invoice_id);
        } elseif ($invoice_type === 'Project') {
            $time_entries = self::getProjectInvoiceTEs($invoice_id);
        }

        // Sort by date ascending
        usort($time_entries, function ($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        return $time_entries;
    }

    /**
     * Get TEs for a Monthly (Standard) invoice.
     *
     * @param int $invoice_id Invoice post ID.
     * @return array Time entries array.
     */
    private static function getMonthlyInvoiceTEs(int $invoice_id): array {
        $time_entries = [];
        $seen_te_ids = [];

        $report_id = get_post_meta($invoice_id, 'related_monthly_report', true);
        if (empty($report_id)) {
            return [];
        }

        $report_month = get_post_meta($report_id, 'report_month', true);
        $org_id = get_post_meta($report_id, 'organization', true);

        if (empty($report_month) || empty($org_id)) {
            return [];
        }

        $date_start = date('Y-m-01', strtotime($report_month));
        $date_end = date('Y-m-t', strtotime($report_month));

        // Get all SRs for this org
        $srs = get_posts([
            'post_type' => 'service_request',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'organization',
                'value' => $org_id,
                'compare' => '=',
            ]],
        ]);

        foreach ($srs as $sr) {
            $sr_ref = get_post_meta($sr->ID, 'reference_number', true) ?: 'SR-????';
            $sr_subject = get_post_meta($sr->ID, 'subject', true) ?: $sr->post_title;

            // Get TEs for this SR within the date range
            $tes = get_posts([
                'post_type' => 'time_entry',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [
                    [
                        'key' => 'related_service_request',
                        'value' => $sr->ID,
                        'compare' => '=',
                    ],
                    [
                        'key' => 'entry_date',
                        'value' => [$date_start, $date_end],
                        'compare' => 'BETWEEN',
                        'type' => 'DATE',
                    ],
                ],
            ]);

            foreach ($tes as $te) {
                if (in_array($te->ID, $seen_te_ids)) {
                    continue;
                }
                $seen_te_ids[] = $te->ID;

                $time_entries[] = [
                    'id' => $te->ID,
                    'date' => get_post_meta($te->ID, 'entry_date', true),
                    'reference' => $sr_ref,
                    'reference_title' => $sr_subject,
                    'title' => $te->post_title,
                    'description' => get_post_meta($te->ID, 'description', true),
                    'hours' => floatval(get_post_meta($te->ID, 'hours', true)),
                    'billable' => get_post_meta($te->ID, 'billable', true) !== '0',
                ];
            }
        }

        return $time_entries;
    }

    /**
     * Get TEs for a Project invoice.
     *
     * @param int $invoice_id Invoice post ID.
     * @return array Time entries array.
     */
    private static function getProjectInvoiceTEs(int $invoice_id): array {
        $time_entries = [];
        $seen_te_ids = [];

        // Check if invoice is linked to a specific milestone
        $milestone_id = get_post_meta($invoice_id, 'related_milestone', true);

        if (!empty($milestone_id)) {
            // MILESTONE-SPECIFIC INVOICE
            $milestone_ref = get_post_meta($milestone_id, 'reference_number', true) ?: 'PR-????-??';
            $milestone_name = get_post_meta($milestone_id, 'milestone_name', true) ?: get_the_title($milestone_id);

            $milestone_tes = get_posts([
                'post_type' => 'time_entry',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [[
                    'key' => 'related_milestone',
                    'value' => $milestone_id,
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

            return $time_entries;
        }

        // CLOSEOUT INVOICE: Get all TEs for project + all milestones
        $project_id = get_post_meta($invoice_id, 'related_project', true);

        if (empty($project_id)) {
            return [];
        }

        $project_ref = get_post_meta($project_id, 'reference_number', true) ?: 'PR-????';
        $project_name = get_post_meta($project_id, 'project_name', true) ?: get_the_title($project_id);

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

        return $time_entries;
    }

    /**
     * Attach PDF to media library.
     *
     * @param string $pdf_path     Path to PDF file.
     * @param string $pdf_url      URL of PDF file.
     * @param int    $invoice_id   Invoice post ID.
     * @param string $invoice_num  Invoice number.
     * @return int|\WP_Error Attachment ID or error.
     */
    private static function attachPDFToInvoice(string $pdf_path, string $pdf_url, int $invoice_id, string $invoice_num) {
        $filetype = wp_check_filetype(basename($pdf_path), null);

        $attachment = [
            'guid' => $pdf_url,
            'post_mime_type' => $filetype['type'],
            'post_title' => 'Invoice ' . $invoice_num,
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        $attachment_id = wp_insert_attachment($attachment, $pdf_path, $invoice_id);

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
     * @param array $data Invoice data.
     * @return string HTML string.
     */
    private static function buildPDFHTML(array $data): string {
        // Get logo URL from settings or use fallback
        $logo_url = Settings::get('pdf_logo_url', '');
        if (empty($logo_url)) {
            $logo_url = site_url('/wp-content/uploads/2024/07/cropped-ChatGPT-Image-Jun-10-2025-10_32_05-AM.png');
        }

        // Get Zelle email from settings
        $zelle_email = Settings::get('zelle_email', 'wales108@gmail.com');

        // Status badge color
        $status_colors = [
            'Draft' => '#6b7280',
            'Pending' => '#d97706',
            'Paid' => '#059669',
            'Partial' => '#2563eb',
            'Overdue' => '#dc2626',
            'Cancelled' => '#6b7280',
        ];
        $status_color = $status_colors[$data['status']] ?? '#6b7280';

        // Build address block
        $address_html = '';
        $city_state_zip = trim(implode(', ', array_filter([$data['org_city'], $data['org_state']])) . ' ' . $data['org_zip']);
        $address_parts = array_filter([$data['org_address'], $city_state_zip]);
        if (!empty($address_parts)) {
            $address_html = '<br>' . implode('<br>', array_map('esc_html', $address_parts));
        }

        // Build line items summary
        $line_items_html = '';
        foreach ($data['line_items'] as $item) {
            $type_label = esc_html($item['type']);
            $description = esc_html($item['description']);
            $amount = '$' . number_format($item['amount'], 2);
            $amount_style = $item['amount'] < 0 ? 'color: #059669;' : '';

            $line_items_html .= "
            <tr>
                <td style=\"padding: 6px 10px; border-bottom: 1px solid #e5e7eb; font-size: 12px;\">
                    <strong>{$type_label}</strong>
                    " . (!empty($description) ? " - <span style=\"color: #6b7280;\">{$description}</span>" : "") . "
                </td>
                <td style=\"padding: 6px 10px; border-bottom: 1px solid #e5e7eb; text-align: right; font-size: 12px; {$amount_style}\">{$amount}</td>
            </tr>";
        }

        // Hours summary
        $hours_html = '';
        if ($data['total_hours'] > 0) {
            $hours_html = "
            <div style=\"margin-top: 15px; padding: 10px; background: #f9fafb; border-radius: 4px; font-size: 11px;\">
                <strong>Hours:</strong> " . number_format($data['total_hours'], 2) . " billable";
            if ($data['free_hours_applied'] > 0) {
                $hours_html .= " | " . number_format($data['free_hours_applied'], 2) . " free applied";
            }
            if ($data['overage_hours'] > 0) {
                $hours_html .= " | " . number_format($data['overage_hours'], 2) . " overage";
            }
            $hours_html .= "</div>";
        }

        // Notes section
        $notes_html = '';
        $notes = $data['notes'] ?? '';
        if (!empty($notes) && strpos($notes, 'Auto-generated from') === false) {
            $notes_html = "
            <div style=\"margin-top: 10px; padding: 8px 10px; background: #fefce8; border-left: 3px solid #facc15; font-size: 11px;\">
                <strong>Notes:</strong> " . esc_html($notes) . "
            </div>";
        }

        // Build page 2 content
        $page_2_html = '';
        $is_closeout = !empty($data['closeout_data']);
        $closeout_data = $is_closeout ? json_decode($data['closeout_data'], true) : null;

        if ($is_closeout && !empty($closeout_data)) {
            $page_2_html = self::buildCloseoutDetailsPage($data, $closeout_data, $logo_url);
        } elseif (!empty($data['time_entries'])) {
            $page_2_html = self::buildStandardDetailsPage($data, $logo_url);
        }

        $invoice_number_lower = strtolower($data['invoice_number']);

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
                    line-height: 1.4;
                    margin: 0;
                    padding: 30px;
                }
            </style>
        </head>
        <body>
            <!-- PAGE 1: SUMMARY -->
            <div style=\"display: table; width: 100%; margin-bottom: 25px;\">
                <div style=\"display: table-cell; width: 50%; vertical-align: top;\">
                    <img src=\"{$logo_url}\" alt=\"Brad's Bits & Bytes\" style=\"max-width: 160px; margin-bottom: 8px;\">
                    <div style=\"font-size: 11px; color: #6b7280;\">brad@bradsbitsandbytes.com</div>
                </div>
                <div style=\"display: table-cell; width: 50%; vertical-align: top; text-align: right;\">
                    <div style=\"font-size: 24px; font-weight: bold; color: #111827;\">INVOICE</div>
                    <div style=\"font-size: 16px; margin-bottom: 8px;\">" . esc_html($data['invoice_number']) . "</div>
                    <span style=\"display: inline-block; padding: 3px 10px; border-radius: 12px; color: white; font-size: 11px; font-weight: bold; background: {$status_color};\">" . esc_html($data['status']) . "</span>
                </div>
            </div>

            <div style=\"display: table; width: 100%; margin-bottom: 20px;\">
                <div style=\"display: table-cell; width: 33.33%; padding: 12px; background: #f9fafb; vertical-align: top; border-radius: 4px 0 0 4px;\">
                    <div style=\"font-size: 10px; text-transform: uppercase; color: #6b7280; margin-bottom: 3px;\">Bill To</div>
                    <strong style=\"font-size: 12px;\">" . esc_html($data['org_name']) . "</strong>
                    <span style=\"font-size: 11px;\">{$address_html}</span>
                </div>
                <div style=\"display: table-cell; width: 33.33%; padding: 12px; background: #f9fafb; vertical-align: top;\">
                    <div style=\"font-size: 10px; text-transform: uppercase; color: #6b7280; margin-bottom: 3px;\">Invoice Date</div>
                    <strong style=\"font-size: 12px;\">" . esc_html($data['invoice_date']) . "</strong><br><br>
                    <div style=\"font-size: 10px; text-transform: uppercase; color: #6b7280; margin-bottom: 3px;\">Due Date</div>
                    <strong style=\"font-size: 12px;\">" . esc_html($data['due_date']) . "</strong>
                </div>
                <div style=\"display: table-cell; width: 33.33%; padding: 12px; background: #f9fafb; vertical-align: top; border-radius: 0 4px 4px 0;\">
                    <div style=\"font-size: 10px; text-transform: uppercase; color: #6b7280; margin-bottom: 3px;\">Period</div>
                    <strong style=\"font-size: 12px;\">" . esc_html($data['period']) . "</strong><br><br>
                    <div style=\"font-size: 10px; text-transform: uppercase; color: #6b7280; margin-bottom: 3px;\">Amount Due</div>
                    <strong style=\"font-size: 16px; color: #059669;\">\$" . number_format($data['total'], 2) . "</strong>
                </div>
            </div>

            <table style=\"width: 100%; border-collapse: collapse; margin-bottom: 15px;\">
                <thead>
                    <tr>
                        <th style=\"background: #f3f4f6; padding: 8px 10px; text-align: left; font-size: 10px; text-transform: uppercase; color: #6b7280; border-bottom: 2px solid #e5e7eb;\">Description</th>
                        <th style=\"background: #f3f4f6; padding: 8px 10px; text-align: right; font-size: 10px; text-transform: uppercase; color: #6b7280; border-bottom: 2px solid #e5e7eb;\">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    {$line_items_html}
                    <tr>
                        <td style=\"padding: 10px; text-align: right; font-weight: bold; border-top: 2px solid #1f2937; font-size: 13px;\">Total Due:</td>
                        <td style=\"padding: 10px; text-align: right; font-weight: bold; border-top: 2px solid #1f2937; font-size: 14px;\">\$" . number_format($data['total'], 2) . "</td>
                    </tr>
                </tbody>
            </table>

            {$hours_html}
            {$notes_html}

            <div style=\"margin-top: 25px; padding-top: 15px; border-top: 1px solid #e5e7eb; font-size: 11px; color: #6b7280;\">
                <strong>Payment Terms:</strong> Due within {$data['payment_terms_days']} days. Late fee of {$data['late_fee_percentage']}% after 7 days past due.<br>
                <strong>Pay:</strong> Zelle to {$zelle_email} | Online at bradsbitsandbytes.com/invoice/{$invoice_number_lower}
            </div>

            <!-- PAGE 2+: DETAILS -->
            {$page_2_html}

        </body>
        </html>";

        return $html;
    }

    /**
     * Build page 2 for standard/milestone invoices.
     *
     * @param array  $data    Invoice data.
     * @param string $logo_url Logo URL.
     * @return string HTML string.
     */
    private static function buildStandardDetailsPage(array $data, string $logo_url): string {
        $has_milestone_ref = !empty($data['time_entries']) && !empty($data['time_entries'][0]['reference']);
        $milestone_ref = $has_milestone_ref ? $data['time_entries'][0]['reference'] : '';
        $milestone_name = $has_milestone_ref ? $data['time_entries'][0]['reference_title'] : '';

        // Check if this is a flat-rate milestone invoice
        $is_flat_rate = ($data['billing_model'] ?? '') === 'flat_rate';

        $te_billable_hours = 0;
        $te_non_billable_hours = 0;

        // Build TE rows
        $time_entries_html = '';
        foreach ($data['time_entries'] as $te) {
            $te_date = !empty($te['date']) ? date('M j', strtotime($te['date'])) : '';
            $te_desc = esc_html($te['title'] ?: $te['description']);
            $te_hours = number_format($te['hours'], 2);

            if ($te['billable']) {
                $te_billable_hours += $te['hours'];
            } else {
                $te_non_billable_hours += $te['hours'];
            }

            $row_style = !$te['billable'] ? 'color: #9ca3af; font-style: italic;' : '';
            $billable_note = !$te['billable'] ? ' (NB)' : '';

            $time_entries_html .= "
            <tr style=\"{$row_style}\">
                <td style=\"padding: 5px 8px; border-bottom: 1px solid #e5e7eb; white-space: nowrap; font-size: 11px;\">{$te_date}</td>
                <td style=\"padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 11px;\">{$te_desc}{$billable_note}</td>
                <td style=\"padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: right; font-size: 11px;\">{$te_hours}</td>
            </tr>";
        }

        $hourly_rate = $data['hourly_rate'] ?? 30;
        $total_due = $data['total'];

        // Section subtotal row
        $total_hours = $te_billable_hours + $te_non_billable_hours;
        $hours_display = number_format($total_hours, 2);

        if ($is_flat_rate) {
            // FLAT RATE: Just show total hours logged, no rate calculation
            $subtotal_html = "
            <tr style=\"background: #fafafa; font-weight: bold;\">
                <td colspan=\"2\" style=\"padding: 6px 8px; text-align: right; font-size: 10px;\">Total Hours Logged:</td>
                <td style=\"padding: 6px 8px; text-align: right; font-size: 10px;\">{$hours_display}</td>
            </tr>";

            // Grand totals box for flat rate
            $totals_rows = "
            <tr>
                <td style=\"padding: 4px 0;\"><strong>Total Hours Logged:</strong></td>
                <td style=\"padding: 4px 0; text-align: right;\">" . number_format($total_hours, 2) . " hrs</td>
            </tr>
            <tr>
                <td style=\"padding: 4px 0;\"><strong>Billing:</strong></td>
                <td style=\"padding: 4px 0; text-align: right;\">Flat Rate</td>
            </tr>
            <tr style=\"font-size: 14px; border-top: 2px solid #374151;\">
                <td style=\"padding: 10px 0 0 0;\"><strong>Total Due:</strong></td>
                <td style=\"padding: 10px 0 0 0; text-align: right;\"><strong>\$" . number_format($total_due, 2) . "</strong></td>
            </tr>";
        } else {
            // HOURLY: Show the rate calculation
            $gross_amount = $te_billable_hours * $hourly_rate;
            $free_hours_applied = $data['free_hours_applied'] ?? 0;
            $free_hours_credit = $free_hours_applied * $hourly_rate;

            $billable_display = number_format($te_billable_hours, 2);
            if ($te_non_billable_hours > 0) {
                $billable_display .= " <span style=\"color: #9ca3af;\">(+" . number_format($te_non_billable_hours, 2) . " NB)</span>";
            }

            $subtotal_html = "
            <tr style=\"background: #fafafa; font-weight: bold;\">
                <td colspan=\"2\" style=\"padding: 6px 8px; text-align: right; font-size: 10px;\">Subtotal: {$billable_display} hrs x \$" . number_format($hourly_rate, 2) . "</td>
                <td style=\"padding: 6px 8px; text-align: right; font-size: 10px;\">\$" . number_format($gross_amount, 2) . "</td>
            </tr>";

            // Grand totals box for hourly
            $totals_rows = "
            <tr>
                <td style=\"padding: 4px 0;\"><strong>Total Hours:</strong></td>
                <td style=\"padding: 4px 0; text-align: right;\">" . number_format($te_billable_hours, 2) . " hrs</td>
            </tr>
            <tr>
                <td style=\"padding: 4px 0;\"><strong>Rate:</strong></td>
                <td style=\"padding: 4px 0; text-align: right;\">\$" . number_format($hourly_rate, 2) . "/hr</td>
            </tr>
            <tr>
                <td style=\"padding: 4px 0;\"><strong>Gross Amount:</strong></td>
                <td style=\"padding: 4px 0; text-align: right;\">\$" . number_format($gross_amount, 2) . "</td>
            </tr>";

            if ($free_hours_applied > 0) {
                $totals_rows .= "
                <tr>
                    <td style=\"padding: 4px 0; color: #059669;\">Free Hours Credit (" . number_format($free_hours_applied, 2) . " hrs):</td>
                    <td style=\"padding: 4px 0; text-align: right; color: #059669;\">-\$" . number_format($free_hours_credit, 2) . "</td>
                </tr>";
            }

            if ($te_non_billable_hours > 0) {
                $totals_rows .= "
                <tr>
                    <td style=\"padding: 4px 0; color: #9ca3af;\">Non-Billable (" . number_format($te_non_billable_hours, 2) . " hrs):</td>
                    <td style=\"padding: 4px 0; text-align: right; color: #9ca3af;\">\$0.00</td>
                </tr>";
            }

            $totals_rows .= "
            <tr style=\"font-size: 14px; border-top: 2px solid #374151;\">
                <td style=\"padding: 10px 0 0 0;\"><strong>Total Due:</strong></td>
                <td style=\"padding: 10px 0 0 0; text-align: right;\"><strong>\$" . number_format($total_due, 2) . "</strong></td>
            </tr>";
        }

        // Section header
        $section_header = '';
        if ($has_milestone_ref) {
            $billing_badge = $is_flat_rate
                ? '<span style="background: #dbeafe; color: #1e40af; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-left: 8px;">FLAT RATE</span>'
                : '<span style="background: #dcfce7; color: #166534; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-left: 8px;">HOURLY</span>';
            $section_header = "
            <div style=\"background: #f3f4f6; padding: 8px 12px; border-radius: 4px 4px 0 0; border-bottom: 2px solid #e5e7eb; margin-bottom: 0;\">
                <strong style=\"font-size: 12px;\">" . esc_html($milestone_ref . ' - ' . $milestone_name) . "</strong>{$billing_badge}
            </div>";
        }

        $footer_note = $is_flat_rate
            ? "<div style=\"margin-top: 15px; font-size: 10px; color: #9ca3af;\">This is a flat-rate milestone. Hours are shown for transparency.</div>"
            : "<div style=\"margin-top: 15px; font-size: 10px; color: #9ca3af;\">NB = Non-Billable (shown for transparency, not charged)</div>";

        return "
        <div style=\"page-break-before: always;\"></div>

        <div style=\"display: table; width: 100%; margin-bottom: 20px;\">
            <div style=\"display: table-cell; width: 50%; vertical-align: middle;\">
                <img src=\"{$logo_url}\" alt=\"Brad's Bits & Bytes\" style=\"max-width: 120px;\">
            </div>
            <div style=\"display: table-cell; width: 50%; vertical-align: middle; text-align: right;\">
                <div style=\"font-size: 16px; font-weight: bold;\">Time Entry Details</div>
                <div style=\"font-size: 12px; color: #6b7280;\">Invoice " . esc_html($data['invoice_number']) . "</div>
            </div>
        </div>

        {$section_header}

        <table style=\"width: 100%; border-collapse: collapse; font-size: 10px;\">
            <thead>
                <tr style=\"background: " . ($has_milestone_ref ? "#fafafa" : "#f3f4f6") . ";\">
                    <th style=\"padding: 6px 8px; text-align: left; color: #6b7280; border-bottom: 1px solid #e5e7eb; width: 60px;\">Date</th>
                    <th style=\"padding: 6px 8px; text-align: left; color: #6b7280; border-bottom: 1px solid #e5e7eb;\">Description</th>
                    <th style=\"padding: 6px 8px; text-align: right; color: #6b7280; border-bottom: 1px solid #e5e7eb; width: 50px;\">Hours</th>
                </tr>
            </thead>
            <tbody>
                {$time_entries_html}
                {$subtotal_html}
            </tbody>
        </table>

        <div style=\"margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 6px; border: 2px solid #e5e7eb;\">
            <table style=\"width: 100%; font-size: 12px;\">
                {$totals_rows}
            </table>
        </div>

        {$footer_note}";
    }

    /**
     * Build page 2 for closeout invoices.
     *
     * Grouped by milestone with section headers, status badges,
     * time entry details, and credits summary.
     *
     * @param array  $data         Invoice data.
     * @param array  $closeout_data Closeout data (from invoice meta).
     * @param string $logo_url     Logo URL.
     * @return string HTML string.
     */
    private static function buildCloseoutDetailsPage(array $data, array $closeout_data, string $logo_url): string {
        $hourly_rate = $closeout_data['hourly_rate'] ?? 30;
        $sections_html = '';
        $grand_total_hours = 0;
        $grand_total_amount = 0;

        // Project-level TEs section
        if (($closeout_data['project_te_count'] ?? 0) > 0) {
            $project_ref = $closeout_data['project_ref'] ?? '';
            $project_name = $closeout_data['project_name'] ?? '';
            $project_hours = $closeout_data['project_billable_hours'] ?? 0;
            $project_nb_hours = $closeout_data['project_non_billable_hours'] ?? 0;
            $project_amount = $project_hours * $hourly_rate;

            $grand_total_hours += $project_hours;
            $grand_total_amount += $project_amount;

            // Get project TEs
            $project_id = get_post_meta($data['invoice_id'], 'related_project', true);
            $project_tes = [];
            if ($project_id) {
                $te_posts = get_posts([
                    'post_type' => 'time_entry',
                    'posts_per_page' => -1,
                    'post_status' => 'publish',
                    'meta_query' => [[
                        'key' => 'related_project',
                        'value' => $project_id,
                        'compare' => '=',
                    ]],
                    'meta_key' => 'entry_date',
                    'orderby' => 'meta_value',
                    'order' => 'ASC',
                ]);

                foreach ($te_posts as $te) {
                    $project_tes[] = [
                        'date' => get_post_meta($te->ID, 'entry_date', true),
                        'title' => get_the_title($te->ID),
                        'description' => get_post_meta($te->ID, 'description', true),
                        'hours' => floatval(get_post_meta($te->ID, 'hours', true)),
                        'billable' => get_post_meta($te->ID, 'billable', true) !== '0',
                    ];
                }
            }

            $sections_html .= self::buildCloseoutSection(
                $project_ref . ' - ' . $project_name,
                'Project Work',
                null,
                $project_tes,
                $project_hours,
                $project_nb_hours,
                $hourly_rate
            );
        }

        // Milestone sections
        $milestones = $closeout_data['milestones'] ?? [];
        foreach ($milestones as $ms) {
            $ms_hours = $ms['billable_hours'] ?? 0;
            $ms_nb_hours = $ms['non_billable_hours'] ?? 0;
            $ms_amount = $ms_hours * $hourly_rate;

            $grand_total_hours += $ms_hours;
            $grand_total_amount += $ms_amount;

            // Determine status badge
            $status_badge = '';
            $status_color = '#6b7280';
            $invoice_status = $ms['invoice_status'] ?? '';

            if ($invoice_status === 'Paid') {
                $status_badge = 'PAID';
                $status_color = '#059669';
            } elseif ($invoice_status === 'Partial') {
                $amount_paid = $ms['amount_paid'] ?? 0;
                $status_badge = 'PARTIAL ($' . number_format($amount_paid, 2) . ' paid)';
                $status_color = '#2563eb';
            } elseif ($invoice_status === 'Cancelled') {
                $status_badge = 'INCLUDED IN CLOSEOUT';
                $status_color = '#7c3aed';
            } elseif ($invoice_status === 'Draft') {
                $status_badge = 'DRAFT - NOT BILLED';
                $status_color = '#d97706';
            } elseif (empty($invoice_status)) {
                $status_badge = 'NOT INVOICED';
                $status_color = '#d97706';
            }

            // Get milestone TEs
            $ms_tes = [];
            $ms_id = $ms['id'] ?? 0;
            if ($ms_id) {
                $te_posts = get_posts([
                    'post_type' => 'time_entry',
                    'posts_per_page' => -1,
                    'post_status' => 'publish',
                    'meta_query' => [[
                        'key' => 'related_milestone',
                        'value' => $ms_id,
                        'compare' => '=',
                    ]],
                    'meta_key' => 'entry_date',
                    'orderby' => 'meta_value',
                    'order' => 'ASC',
                ]);

                foreach ($te_posts as $te) {
                    $ms_tes[] = [
                        'date' => get_post_meta($te->ID, 'entry_date', true),
                        'title' => get_the_title($te->ID),
                        'description' => get_post_meta($te->ID, 'description', true),
                        'hours' => floatval(get_post_meta($te->ID, 'hours', true)),
                        'billable' => get_post_meta($te->ID, 'billable', true) !== '0',
                    ];
                }
            }

            $ms_ref = $ms['ref'] ?? '';
            $ms_name = $ms['name'] ?? 'Milestone';
            $title = ($ms_ref ?: 'Milestone') . ' - ' . $ms_name;

            $sections_html .= self::buildCloseoutSection(
                $title,
                $status_badge,
                $status_color,
                $ms_tes,
                $ms_hours,
                $ms_nb_hours,
                $hourly_rate
            );
        }

        // Build credits summary
        $credits_html = '';
        $total_credits = 0;
        $credits = $closeout_data['credits'] ?? [];

        if (!empty($credits)) {
            $credits_html .= "
            <div style=\"margin-top: 20px; padding: 15px; background: #ecfdf5; border-radius: 6px;\">
                <div style=\"font-weight: bold; font-size: 12px; margin-bottom: 10px; color: #065f46;\">Previous Payments Applied</div>
                <table style=\"width: 100%; font-size: 11px;\">";

            foreach ($credits as $credit) {
                $credit_amount = $credit['amount'] ?? 0;
                $total_credits += $credit_amount;
                $credit_desc = esc_html($credit['description'] ?? '');
                $credits_html .= "
                <tr>
                    <td style=\"padding: 3px 0;\">{$credit_desc}</td>
                    <td style=\"padding: 3px 0; text-align: right; color: #059669;\">-\$" . number_format($credit_amount, 2) . "</td>
                </tr>";
            }

            $credits_html .= "
                <tr style=\"font-weight: bold; border-top: 1px solid #a7f3d0;\">
                    <td style=\"padding: 8px 0 0 0;\">Total Credits:</td>
                    <td style=\"padding: 8px 0 0 0; text-align: right; color: #059669;\">-\$" . number_format($total_credits, 2) . "</td>
                </tr>
                </table>
            </div>";
        }

        // Grand totals
        $final_balance = $grand_total_amount - $total_credits;
        $balance_color = $final_balance < 0 ? '#059669' : '#1f2937';
        $balance_label = $final_balance < 0 ? 'Credit Balance:' : 'Balance Due:';

        $grand_totals_html = "
        <div style=\"margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 6px; border: 2px solid #e5e7eb;\">
            <table style=\"width: 100%; font-size: 12px;\">
                <tr>
                    <td style=\"padding: 4px 0;\"><strong>Total Project Hours:</strong></td>
                    <td style=\"padding: 4px 0; text-align: right;\">" . number_format($grand_total_hours, 2) . " hrs</td>
                </tr>
                <tr>
                    <td style=\"padding: 4px 0;\"><strong>Gross Project Amount:</strong></td>
                    <td style=\"padding: 4px 0; text-align: right;\">\$" . number_format($grand_total_amount, 2) . "</td>
                </tr>
                <tr>
                    <td style=\"padding: 4px 0;\"><strong>Total Credits Applied:</strong></td>
                    <td style=\"padding: 4px 0; text-align: right; color: #059669;\">-\$" . number_format($total_credits, 2) . "</td>
                </tr>
                <tr style=\"font-size: 14px; border-top: 2px solid #374151;\">
                    <td style=\"padding: 10px 0 0 0;\"><strong>{$balance_label}</strong></td>
                    <td style=\"padding: 10px 0 0 0; text-align: right; color: {$balance_color};\"><strong>\$" . number_format(abs($final_balance), 2) . "</strong></td>
                </tr>
            </table>
        </div>";

        return "
        <div style=\"page-break-before: always;\"></div>

        <div style=\"display: table; width: 100%; margin-bottom: 20px;\">
            <div style=\"display: table-cell; width: 50%; vertical-align: middle;\">
                <img src=\"{$logo_url}\" alt=\"Brad's Bits & Bytes\" style=\"max-width: 120px;\">
            </div>
            <div style=\"display: table-cell; width: 50%; vertical-align: middle; text-align: right;\">
                <div style=\"font-size: 16px; font-weight: bold;\">Project Summary</div>
                <div style=\"font-size: 12px; color: #6b7280;\">Invoice " . esc_html($data['invoice_number']) . "</div>
            </div>
        </div>

        {$sections_html}
        {$credits_html}
        {$grand_totals_html}

        <div style=\"margin-top: 15px; font-size: 10px; color: #9ca3af;\">
            NB = Non-Billable (shown for transparency, not charged)
        </div>";
    }

    /**
     * Build a single section for closeout details page.
     *
     * @param string      $title            Section title.
     * @param string      $status_badge     Badge text (e.g., "PAID", "PARTIAL").
     * @param string|null $status_color     Badge color (hex).
     * @param array       $tes              Time entries array.
     * @param float       $billable_hours   Billable hours.
     * @param float       $non_billable_hours Non-billable hours.
     * @param float       $hourly_rate      Hourly rate.
     * @return string HTML string.
     */
    private static function buildCloseoutSection(
        string $title,
        string $status_badge,
        ?string $status_color,
        array $tes,
        float $billable_hours,
        float $non_billable_hours,
        float $hourly_rate
    ): string {
        $section_html = "
        <div style=\"margin-bottom: 20px;\">
            <div style=\"background: #f3f4f6; padding: 8px 12px; border-radius: 4px 4px 0 0; border-bottom: 2px solid #e5e7eb;\">
                <strong style=\"font-size: 12px;\">" . esc_html($title) . "</strong>";

        if ($status_badge && $status_color) {
            $section_html .= " <span style=\"margin-left: 10px; padding: 2px 8px; background: {$status_color}; color: white; font-size: 9px; border-radius: 10px; font-weight: bold;\">{$status_badge}</span>";
        }

        $section_html .= "
            </div>
            <table style=\"width: 100%; border-collapse: collapse; font-size: 10px;\">
                <thead>
                    <tr>
                        <th style=\"padding: 4px 8px; text-align: left; color: #6b7280; border-bottom: 1px solid #e5e7eb; width: 60px;\">Date</th>
                        <th style=\"padding: 4px 8px; text-align: left; color: #6b7280; border-bottom: 1px solid #e5e7eb;\">Description</th>
                        <th style=\"padding: 4px 8px; text-align: right; color: #6b7280; border-bottom: 1px solid #e5e7eb; width: 50px;\">Hours</th>
                    </tr>
                </thead>
                <tbody>";

        foreach ($tes as $te) {
            $te_date = !empty($te['date']) ? date('M j', strtotime($te['date'])) : '';
            $te_desc = esc_html($te['title'] ?: ($te['description'] ?? ''));
            $te_hours = number_format($te['hours'] ?? 0, 2);
            $is_billable = $te['billable'] ?? true;
            $row_style = !$is_billable ? 'color: #9ca3af; font-style: italic;' : '';
            $nb_marker = !$is_billable ? ' (NB)' : '';

            $section_html .= "
                <tr style=\"{$row_style}\">
                    <td style=\"padding: 4px 8px; border-bottom: 1px solid #f3f4f6;\">{$te_date}</td>
                    <td style=\"padding: 4px 8px; border-bottom: 1px solid #f3f4f6;\">{$te_desc}{$nb_marker}</td>
                    <td style=\"padding: 4px 8px; border-bottom: 1px solid #f3f4f6; text-align: right;\">{$te_hours}</td>
                </tr>";
        }

        // Section subtotal
        $section_amount = $billable_hours * $hourly_rate;
        $hours_display = number_format($billable_hours, 2);
        if ($non_billable_hours > 0) {
            $hours_display .= " <span style=\"color: #9ca3af;\">(+" . number_format($non_billable_hours, 2) . " NB)</span>";
        }

        $section_html .= "
                <tr style=\"background: #fafafa; font-weight: bold;\">
                    <td colspan=\"2\" style=\"padding: 6px 8px; text-align: right; font-size: 10px;\">Subtotal: {$hours_display} hrs × \$" . number_format($hourly_rate, 2) . "</td>
                    <td style=\"padding: 6px 8px; text-align: right; font-size: 10px;\">\$" . number_format($section_amount, 2) . "</td>
                </tr>
                </tbody>
            </table>
        </div>";

        return $section_html;
    }
}
