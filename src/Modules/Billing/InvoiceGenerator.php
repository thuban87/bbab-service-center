<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Billing;

use BBAB\ServiceCenter\Utils\Logger;
use WP_Error;

/**
 * Invoice generation engine.
 *
 * Handles creating invoices from various sources:
 * - Monthly Reports (standard support invoices)
 * - Milestones (project milestone invoices)
 * - Projects (closeout invoices)
 *
 * Migrated from: WPCode Snippets #1996, #2398, #2463
 */
class InvoiceGenerator {

    /**
     * Register hooks.
     */
    public static function register(): void {
        // Admin post handlers for invoice generation
        add_action('admin_post_bbab_generate_milestone_invoice', [self::class, 'handleMilestoneGeneration']);
        add_action('admin_post_bbab_generate_project_closeout', [self::class, 'handleCloseoutGeneration']);

        // Admin notices for generation results
        add_action('admin_notices', [self::class, 'showAdminNotices']);

        // Reset billing status when invoice is trashed/deleted/untrashed
        add_action('wp_trash_post', [self::class, 'handleInvoiceTrash']);
        add_action('before_delete_post', [self::class, 'handleInvoiceDelete']);
        add_action('untrash_post', [self::class, 'handleInvoiceUntrash']);

        Logger::debug('InvoiceGenerator', 'Registered invoice generator hooks');
    }

    // =========================================================================
    // GENERATE FROM MONTHLY REPORT
    // =========================================================================

    /**
     * Generate a draft invoice from a Monthly Report.
     *
     * @param int $report_id Monthly Report post ID.
     * @return int|WP_Error New invoice ID or error.
     */
    public static function fromMonthlyReport(int $report_id): int|WP_Error {
        // Validate report exists
        $report = get_post($report_id);
        if (!$report || $report->post_type !== 'monthly_report') {
            return new WP_Error('invalid_report', 'Invalid Monthly Report ID');
        }

        // Get report data
        $org_id = get_post_meta($report_id, 'organization', true);
        $report_month = get_post_meta($report_id, 'report_month', true);

        if (empty($org_id)) {
            return new WP_Error('no_org', 'Monthly Report has no organization assigned');
        }

        // Check if invoice already exists for this report
        $existing = InvoiceService::getForMonthlyReport($report_id);
        if ($existing) {
            return new WP_Error('invoice_exists', 'An invoice already exists for this Monthly Report', ['invoice_id' => $existing->ID]);
        }

        // Get organization billing settings
        $free_hours_limit = (float) (get_post_meta($org_id, 'free_hours_limit', true) ?: 2);
        $hourly_rate = (float) (get_post_meta($org_id, 'hourly_rate', true) ?: 30);
        $hosting_fee = (float) (get_post_meta($org_id, 'monthly_hosting_fee', true) ?: 0);
        $payment_terms = (int) (get_post_meta($org_id, 'payment_terms_days', true) ?: 5);

        // Get all billable SRs from the report
        $service_requests = self::getBillableSRsFromReport($report_id);

        // Calculate totals (consolidated, not per-SR)
        $billable_hours = 0.0;
        $non_billable_hours = 0.0;

        foreach ($service_requests as $sr) {
            $billable_hours += $sr['billable_hours'];
            $non_billable_hours += $sr['non_billable_hours'];
        }

        // Calculate free vs overage hours
        $free_hours_applied = min($billable_hours, $free_hours_limit);
        $overage_hours = max(0, $billable_hours - $free_hours_limit);

        // Calculate amounts
        $overage_amount = $overage_hours * $hourly_rate;
        $subtotal = $overage_amount + $hosting_fee;

        // Generate invoice number and dates
        $invoice_date = current_time('Y-m-d');
        $invoice_number = InvoiceReferenceGenerator::generateNumber($invoice_date);
        $due_date = date('Y-m-d', strtotime($invoice_date . ' + ' . $payment_terms . ' days'));

        // Create the invoice post
        $invoice_id = wp_insert_post([
            'post_type' => 'invoice',
            'post_title' => $invoice_number,
            'post_status' => 'publish',
        ]);

        if (is_wp_error($invoice_id)) {
            return $invoice_id;
        }

        // Use Pods API to save fields
        if (function_exists('pods')) {
            $invoice_pod = pods('invoice', $invoice_id);
            $invoice_pod->save([
                'invoice_number' => $invoice_number,
                'invoice_date' => $invoice_date,
                'due_date' => $due_date,
                'organization' => $org_id,
                'related_monthly_report' => $report_id,
                'invoice_type' => 'Standard',
                'invoice_status' => 'Draft',
                'amount' => $subtotal,
                'amount_paid' => 0,
                'subtotal' => $subtotal,
                'total_hours' => $billable_hours,
                'free_hours_applied' => $free_hours_applied,
                'overage_hours' => $overage_hours,
                'non_billable_hours' => $non_billable_hours,
                'invoice_notes' => 'Auto-generated from ' . $report_month . ' Monthly Report',
            ]);
        }

        // Create line items
        $display_order = 0;

        // Hosting fee line item (if applicable)
        if ($hosting_fee > 0) {
            LineItemService::create($invoice_id, [
                'line_type' => 'Hosting Fee',
                'description' => 'Monthly hosting - ' . $report_month,
                'quantity' => '',
                'rate' => '',
                'amount' => $hosting_fee,
                'display_order' => $display_order++,
            ]);
        }

        // Single consolidated Support line item (if there are billable hours)
        if ($billable_hours > 0) {
            LineItemService::create($invoice_id, [
                'line_type' => 'Support',
                'description' => 'Technical support - ' . $report_month,
                'quantity' => $billable_hours,
                'rate' => $hourly_rate,
                'amount' => $billable_hours * $hourly_rate,
                'display_order' => $display_order++,
            ]);
        }

        // Free Hours Credit line item
        if ($free_hours_applied > 0) {
            LineItemService::create($invoice_id, [
                'line_type' => 'Free Hours Credit',
                'description' => 'Included support (' . $free_hours_limit . ' hrs/month)',
                'quantity' => $free_hours_applied,
                'rate' => -$hourly_rate,
                'amount' => -($free_hours_applied * $hourly_rate),
                'display_order' => $display_order++,
            ]);
        }

        // Single consolidated Non-Billable line item
        if ($non_billable_hours > 0) {
            LineItemService::create($invoice_id, [
                'line_type' => 'Support (Non-Billable)',
                'description' => 'Non-billable support - ' . $report_month,
                'quantity' => $non_billable_hours,
                'rate' => 0,
                'amount' => 0,
                'display_order' => $display_order++,
            ]);
        }

        Logger::debug('InvoiceGenerator', 'Generated invoice from monthly report', [
            'report_id' => $report_id,
            'invoice_id' => $invoice_id,
            'amount' => $subtotal,
        ]);

        return $invoice_id;
    }

    /**
     * Get billable SR data from a Monthly Report's time entries.
     *
     * @param int $report_id Monthly Report post ID.
     * @return array Array of SR data with hours.
     */
    public static function getBillableSRsFromReport(int $report_id): array {
        $entries = self::getReportTimeEntries($report_id);
        $sr_data = [];

        foreach ($entries as $entry) {
            $sr_id = get_post_meta($entry->ID, 'related_service_request', true);
            if (empty($sr_id)) {
                continue;
            }

            // Initialize SR data if first time seeing it
            if (!isset($sr_data[$sr_id])) {
                $sr_data[$sr_id] = [
                    'sr_id' => $sr_id,
                    'sr_subject' => get_post_meta($sr_id, 'subject', true) ?: get_the_title($sr_id),
                    'total_hours' => 0.0,
                    'billable_hours' => 0.0,
                    'non_billable_hours' => 0.0,
                ];
            }

            $hours = (float) get_post_meta($entry->ID, 'hours', true);
            $billable = get_post_meta($entry->ID, 'billable', true);

            // Round to quarter hour
            $hours = self::roundToQuarterHour($hours);

            $sr_data[$sr_id]['total_hours'] += $hours;

            if ($billable === '0' || $billable === 0 || $billable === false) {
                $sr_data[$sr_id]['non_billable_hours'] += $hours;
            } else {
                $sr_data[$sr_id]['billable_hours'] += $hours;
            }
        }

        return array_values($sr_data);
    }

    /**
     * Get time entries for a monthly report.
     *
     * @param int $report_id Monthly Report post ID.
     * @return array Array of time entry posts.
     */
    public static function getReportTimeEntries(int $report_id): array {
        $org_id = get_post_meta($report_id, 'organization', true);
        $report_month = get_post_meta($report_id, 'report_month', true);

        if (empty($org_id) || empty($report_month)) {
            return [];
        }

        // Parse report month to get date range
        $month_timestamp = strtotime('1 ' . $report_month);
        if ($month_timestamp === false) {
            return [];
        }

        $month_start = date('Y-m-01', $month_timestamp);
        $month_end = date('Y-m-t', $month_timestamp);

        return get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'organization',
                    'value' => $org_id,
                    'compare' => '=',
                ],
                [
                    'key' => 'entry_date',
                    'value' => [$month_start, $month_end],
                    'compare' => 'BETWEEN',
                    'type' => 'DATE',
                ],
            ],
        ]);
    }

    // =========================================================================
    // GENERATE FROM MILESTONE
    // =========================================================================

    /**
     * Generate an invoice from a milestone.
     *
     * @param int $milestone_id Milestone post ID.
     * @return int|WP_Error New invoice ID or error.
     */
    public static function fromMilestone(int $milestone_id): int|WP_Error {
        // Validate milestone exists
        $milestone = get_post($milestone_id);
        if (!$milestone || $milestone->post_type !== 'milestone') {
            return new WP_Error('invalid_milestone', 'Invalid Milestone ID');
        }

        // Get milestone data
        $milestone_name = get_post_meta($milestone_id, 'milestone_name', true) ?: get_the_title($milestone_id);
        $milestone_amount = (float) get_post_meta($milestone_id, 'milestone_amount', true);
        $is_deposit = get_post_meta($milestone_id, 'is_deposit', true);
        $billing_status = get_post_meta($milestone_id, 'billing_status', true);
        $related_project = get_post_meta($milestone_id, 'related_project', true);
        $milestone_ref = get_post_meta($milestone_id, 'reference_number', true) ?: '';

        // Validations
        if (in_array($billing_status, ['Invoiced', 'Invoiced as Deposit', 'Paid'], true)) {
            return new WP_Error('already_invoiced', 'This milestone has already been invoiced');
        }

        if (empty($related_project)) {
            return new WP_Error('no_project', 'Milestone is not linked to a project');
        }

        // Get project data
        $project = get_post($related_project);
        if (!$project) {
            return new WP_Error('invalid_project', 'Related project not found');
        }

        $project_name = get_post_meta($related_project, 'project_name', true) ?: get_the_title($related_project);
        $org_id = get_post_meta($related_project, 'organization', true);

        if (empty($org_id)) {
            return new WP_Error('no_org', 'Project has no organization assigned');
        }

        // Get organization billing settings
        $payment_terms = (int) (get_post_meta($org_id, 'payment_terms_days', true) ?: 5);
        $hourly_rate = (float) (get_post_meta($org_id, 'hourly_rate', true) ?: 30);

        // Determine billing method: Flat Rate vs Hourly
        $is_hourly = ($milestone_amount <= 0);
        $total_hours = 0.0;
        $billable_hours = 0.0;
        $non_billable_hours = 0.0;
        $invoice_amount = $milestone_amount;

        if ($is_hourly) {
            // HOURLY: Calculate from Time Entries
            $time_entries = get_posts([
                'post_type' => 'time_entry',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [[
                    'key' => 'related_milestone',
                    'value' => $milestone_id,
                    'compare' => '=',
                ]],
            ]);

            if (empty($time_entries)) {
                return new WP_Error('no_time_entries', 'No time entries found for this milestone. For hourly billing, add time entries first.');
            }

            foreach ($time_entries as $te) {
                $hours = (float) get_post_meta($te->ID, 'hours', true);
                $billable = get_post_meta($te->ID, 'billable', true);

                $hours = self::roundToQuarterHour($hours);
                $total_hours += $hours;

                if ($billable === '0' || $billable === 0 || $billable === false) {
                    $non_billable_hours += $hours;
                } else {
                    $billable_hours += $hours;
                }
            }

            $invoice_amount = $billable_hours * $hourly_rate;

            if ($invoice_amount <= 0) {
                return new WP_Error('no_billable_hours', 'No billable hours found for this milestone.');
            }
        } elseif ($milestone_amount <= 0) {
            return new WP_Error('no_amount', 'Milestone has no amount set');
        }

        // Generate invoice number and dates
        $invoice_date = current_time('Y-m-d');
        $invoice_number = InvoiceReferenceGenerator::generateNumber($invoice_date);
        $due_date = date('Y-m-d', strtotime($invoice_date . ' + ' . $payment_terms . ' days'));

        // Determine billing status for milestone
        $new_billing_status = ($is_deposit === '1' || $is_deposit === 1) ? 'Invoiced as Deposit' : 'Invoiced';

        // Build description
        $description = !empty($milestone_ref) ? $milestone_ref . ' - ' . $milestone_name : $milestone_name;

        // Create the invoice post
        $invoice_id = wp_insert_post([
            'post_type' => 'invoice',
            'post_title' => $invoice_number,
            'post_status' => 'publish',
        ]);

        if (is_wp_error($invoice_id)) {
            return $invoice_id;
        }

        // Build invoice notes
        $invoice_notes = 'Generated from milestone ' . $milestone_name . ', attached to project ' . $project_name;
        if ($is_deposit === '1' || $is_deposit === 1) {
            $invoice_notes .= ' (Deposit)';
        }
        if ($is_hourly) {
            $invoice_notes .= ' | Hourly billing';
        }

        // Use Pods API to save fields
        if (function_exists('pods')) {
            $invoice_pod = pods('invoice', $invoice_id);
            $invoice_pod->save([
                'invoice_number' => $invoice_number,
                'invoice_date' => $invoice_date,
                'due_date' => $due_date,
                'organization' => $org_id,
                'related_project' => $related_project,
                'related_milestone' => $milestone_id,
                'invoice_type' => 'Project',
                'invoice_status' => 'Draft',
                'amount' => $invoice_amount,
                'amount_paid' => 0,
                'subtotal' => $invoice_amount,
                'total_hours' => $billable_hours,
                'free_hours_applied' => 0,
                'overage_hours' => $billable_hours,
                'non_billable_hours' => $non_billable_hours,
                'invoice_notes' => $invoice_notes,
            ]);
        }

        // Store billing model for PDF generation
        update_post_meta($invoice_id, 'billing_model', $is_hourly ? 'hourly' : 'flat_rate');

        // Create line item(s)
        $display_order = 0;
        $line_type = ($is_deposit === '1' || $is_deposit === 1) ? 'Project Deposit' : 'Project Milestone';

        if ($is_hourly) {
            // HOURLY: Single line item with hours calculation
            LineItemService::create($invoice_id, [
                'line_type' => $line_type,
                'description' => $description,
                'quantity' => $billable_hours,
                'rate' => $hourly_rate,
                'amount' => $invoice_amount,
                'related_milestone' => $milestone_id,
                'display_order' => $display_order++,
            ]);

            // Add non-billable line item for transparency
            if ($non_billable_hours > 0) {
                LineItemService::create($invoice_id, [
                    'line_type' => 'Project (Non-Billable)',
                    'description' => $description . ' (non-billable)',
                    'quantity' => $non_billable_hours,
                    'rate' => 0,
                    'amount' => 0,
                    'related_milestone' => $milestone_id,
                    'display_order' => $display_order++,
                ]);
            }
        } else {
            // FLAT RATE: Single line item with fixed amount
            LineItemService::create($invoice_id, [
                'line_type' => $line_type,
                'description' => $description,
                'quantity' => '',
                'rate' => '',
                'amount' => $milestone_amount,
                'related_milestone' => $milestone_id,
                'display_order' => $display_order++,
            ]);
        }

        // Update milestone billing status
        update_post_meta($milestone_id, 'billing_status', $new_billing_status);

        Logger::debug('InvoiceGenerator', 'Generated invoice from milestone', [
            'milestone_id' => $milestone_id,
            'invoice_id' => $invoice_id,
            'amount' => $invoice_amount,
            'is_hourly' => $is_hourly,
        ]);

        return $invoice_id;
    }

    // =========================================================================
    // GENERATE CLOSEOUT FROM PROJECT
    // =========================================================================

    /**
     * Generate a closeout invoice from a project.
     *
     * @param int $project_id Project post ID.
     * @return int|WP_Error New invoice ID or error.
     */
    public static function closeoutFromProject(int $project_id): int|WP_Error {
        // Validate project exists
        $project = get_post($project_id);
        if (!$project || $project->post_type !== 'project') {
            return new WP_Error('invalid_project', 'Invalid Project ID');
        }

        // Check if already closed out
        $billing_status = get_post_meta($project_id, 'billing_status', true) ?: 'Not Started';
        if ($billing_status === 'Invoiced') {
            return new WP_Error('already_closed', 'This project has already been closed out');
        }

        // Get project data
        $project_name = get_post_meta($project_id, 'project_name', true) ?: get_the_title($project_id);
        $project_ref = get_post_meta($project_id, 'reference_number', true) ?: 'PR-????';
        $org_id = get_post_meta($project_id, 'organization', true);

        if (empty($org_id)) {
            return new WP_Error('no_org', 'Project has no organization assigned');
        }

        // Get organization billing settings
        $payment_terms = (int) (get_post_meta($org_id, 'payment_terms_days', true) ?: 5);
        $hourly_rate = (float) (get_post_meta($org_id, 'hourly_rate', true) ?: 30);

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
            'meta_key' => 'milestone_order',
            'orderby' => 'meta_value_num',
            'order' => 'ASC',
        ]);

        // Analyze each milestone and its invoice status
        $line_items_data = [];
        $credits = [];
        $voided_invoices = [];
        $total_credits = 0.0;
        $milestone_details = [];

        foreach ($milestones as $milestone) {
            $ms_id = $milestone->ID;
            $ms_name = get_post_meta($ms_id, 'milestone_name', true) ?: get_the_title($ms_id);
            $ms_ref = get_post_meta($ms_id, 'reference_number', true) ?: '';
            $ms_billing_status = get_post_meta($ms_id, 'billing_status', true) ?: 'Pending';

            // Get TEs for this milestone
            $ms_tes = get_posts([
                'post_type' => 'time_entry',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [[
                    'key' => 'related_milestone',
                    'value' => $ms_id,
                    'compare' => '=',
                ]],
            ]);

            // Calculate hours for this milestone
            $ms_billable_hours = 0.0;
            $ms_non_billable_hours = 0.0;

            foreach ($ms_tes as $te) {
                $hours = (float) get_post_meta($te->ID, 'hours', true);
                $billable = get_post_meta($te->ID, 'billable', true);

                $hours = self::roundToQuarterHour($hours);

                if ($billable === '0' || $billable === 0 || $billable === false) {
                    $ms_non_billable_hours += $hours;
                } else {
                    $ms_billable_hours += $hours;
                }
            }

            $ms_amount = $ms_billable_hours * $hourly_rate;

            // Check for existing invoice
            $existing_invoices = InvoiceService::getForMilestone($ms_id);
            $invoice_status = '';
            $invoice_id_existing = 0;
            $amount_paid = 0.0;
            $invoice_amount = 0.0;

            if (!empty($existing_invoices)) {
                $invoice_id_existing = $existing_invoices[0]->ID;
                $invoice_status = InvoiceService::getStatus($invoice_id_existing);
                $amount_paid = InvoiceService::getPaidAmount($invoice_id_existing);
                $invoice_amount = InvoiceService::getAmount($invoice_id_existing);
            }

            // Store milestone details for closeout data
            $milestone_details[] = [
                'id' => $ms_id,
                'name' => $ms_name,
                'ref' => $ms_ref,
                'billable_hours' => $ms_billable_hours,
                'non_billable_hours' => $ms_non_billable_hours,
                'amount' => $ms_amount,
                'invoice_status' => $invoice_status,
                'invoice_amount' => $invoice_amount,
                'amount_paid' => $amount_paid,
                'te_count' => count($ms_tes),
            ];

            // Determine what to do based on invoice status
            if ($invoice_status === 'Paid') {
                // Credit the full amount
                $credits[] = [
                    'description' => ($ms_ref ?: $ms_name) . ' (Paid)',
                    'amount' => $invoice_amount,
                    'milestone_id' => $ms_id,
                ];
                $total_credits += $invoice_amount;
            } elseif ($invoice_status === 'Partial') {
                // Void the partial invoice, credit what was paid, include TEs in line items
                $voided_invoices[] = $invoice_id_existing;

                $credits[] = [
                    'description' => ($ms_ref ?: $ms_name) . ' (Partial payment)',
                    'amount' => $amount_paid,
                    'milestone_id' => $ms_id,
                ];
                $total_credits += $amount_paid;

                if ($ms_billable_hours > 0) {
                    $line_items_data[] = [
                        'type' => 'Project Milestone',
                        'description' => $ms_ref ? $ms_ref . ' - ' . $ms_name : $ms_name,
                        'hours' => $ms_billable_hours,
                        'amount' => $ms_amount,
                        'milestone_id' => $ms_id,
                    ];
                }
            } elseif (in_array($invoice_status, ['Pending', 'Overdue'], true)) {
                // Void the invoice and include TEs in line items
                $voided_invoices[] = $invoice_id_existing;

                if ($ms_billable_hours > 0) {
                    $line_items_data[] = [
                        'type' => 'Project Milestone',
                        'description' => $ms_ref ? $ms_ref . ' - ' . $ms_name : $ms_name,
                        'hours' => $ms_billable_hours,
                        'amount' => $ms_amount,
                        'milestone_id' => $ms_id,
                    ];
                }
            } elseif ($invoice_status === 'Draft') {
                // Exclude from line items (TEs not approved)
            } else {
                // No invoice exists - check billing_status
                if ($ms_billing_status !== 'Pending' && !empty($ms_billing_status)) {
                    if ($ms_billable_hours > 0) {
                        $line_items_data[] = [
                            'type' => 'Project Milestone',
                            'description' => $ms_ref ? $ms_ref . ' - ' . $ms_name : $ms_name,
                            'hours' => $ms_billable_hours,
                            'amount' => $ms_amount,
                            'milestone_id' => $ms_id,
                        ];
                    }
                }
            }
        }

        // Get project-level TEs
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

        $project_billable_hours = 0.0;
        $project_non_billable_hours = 0.0;

        foreach ($project_tes as $te) {
            $hours = (float) get_post_meta($te->ID, 'hours', true);
            $billable = get_post_meta($te->ID, 'billable', true);

            $hours = self::roundToQuarterHour($hours);

            if ($billable === '0' || $billable === 0 || $billable === false) {
                $project_non_billable_hours += $hours;
            } else {
                $project_billable_hours += $hours;
            }
        }

        $project_amount = $project_billable_hours * $hourly_rate;

        // Add project-level line item if there are hours
        if ($project_billable_hours > 0) {
            array_unshift($line_items_data, [
                'type' => 'Project Work',
                'description' => $project_ref . ' - ' . $project_name,
                'hours' => $project_billable_hours,
                'amount' => $project_amount,
                'milestone_id' => null,
            ]);
        }

        // Calculate totals
        $line_items_total = array_sum(array_column($line_items_data, 'amount'));
        $invoice_total = $line_items_total - $total_credits;

        // Calculate total hours
        $total_billable_hours = $project_billable_hours;
        $total_non_billable_hours = $project_non_billable_hours;
        foreach ($milestone_details as $ms) {
            $total_billable_hours += $ms['billable_hours'];
            $total_non_billable_hours += $ms['non_billable_hours'];
        }

        // Generate invoice number and dates
        $invoice_date = current_time('Y-m-d');
        $invoice_number = InvoiceReferenceGenerator::generateNumber($invoice_date);
        $due_date = date('Y-m-d', strtotime($invoice_date . ' + ' . $payment_terms . ' days'));

        // Create the invoice post
        $invoice_id = wp_insert_post([
            'post_type' => 'invoice',
            'post_title' => $invoice_number,
            'post_status' => 'publish',
        ]);

        if (is_wp_error($invoice_id)) {
            return $invoice_id;
        }

        // Build invoice notes
        $invoice_notes = 'Project Closeout: ' . $project_name;
        if (!empty($voided_invoices)) {
            $invoice_notes .= ' | Voided ' . count($voided_invoices) . ' unpaid invoice(s)';
        }

        // Store closeout data for PDF generation
        $closeout_data = [
            'project_ref' => $project_ref,
            'project_name' => $project_name,
            'project_billable_hours' => $project_billable_hours,
            'project_non_billable_hours' => $project_non_billable_hours,
            'project_te_count' => count($project_tes),
            'milestones' => $milestone_details,
            'credits' => $credits,
            'hourly_rate' => $hourly_rate,
        ];

        // Use Pods API to save fields
        if (function_exists('pods')) {
            $invoice_pod = pods('invoice', $invoice_id);
            $invoice_pod->save([
                'invoice_number' => $invoice_number,
                'invoice_date' => $invoice_date,
                'due_date' => $due_date,
                'organization' => $org_id,
                'related_project' => $project_id,
                'invoice_type' => 'Project',
                'invoice_status' => 'Draft',
                'amount' => $invoice_total,
                'amount_paid' => 0,
                'subtotal' => $line_items_total,
                'total_hours' => $total_billable_hours,
                'free_hours_applied' => 0,
                'overage_hours' => $total_billable_hours,
                'non_billable_hours' => $total_non_billable_hours,
                'invoice_notes' => $invoice_notes,
                'is_closeout_invoice' => 1,
                'closeout_data' => json_encode($closeout_data),
            ]);
        }

        // Create line items
        $display_order = 0;

        foreach ($line_items_data as $item) {
            $line_item_data = [
                'line_type' => $item['type'],
                'description' => $item['description'],
                'quantity' => $item['hours'],
                'rate' => $hourly_rate,
                'amount' => $item['amount'],
                'display_order' => $display_order++,
            ];

            if (!empty($item['milestone_id'])) {
                $line_item_data['related_milestone'] = $item['milestone_id'];
            }

            LineItemService::create($invoice_id, $line_item_data);
        }

        // Add credit line items
        foreach ($credits as $credit) {
            $credit_data = [
                'line_type' => 'Previous Payment',
                'description' => $credit['description'],
                'quantity' => '',
                'rate' => '',
                'amount' => -$credit['amount'],
                'display_order' => $display_order++,
            ];

            if (!empty($credit['milestone_id'])) {
                $credit_data['related_milestone'] = $credit['milestone_id'];
            }

            LineItemService::create($invoice_id, $credit_data);
        }

        // Void the unpaid invoices
        foreach ($voided_invoices as $void_invoice_id) {
            update_post_meta($void_invoice_id, 'invoice_status', 'Cancelled');

            // Reset the milestone's billing status
            $void_milestone_id = get_post_meta($void_invoice_id, 'related_milestone', true);
            if ($void_milestone_id) {
                update_post_meta($void_milestone_id, 'billing_status', 'Pending');
            }
        }

        // Update project billing status
        update_post_meta($project_id, 'billing_status', 'Invoiced');

        Logger::debug('InvoiceGenerator', 'Generated closeout invoice from project', [
            'project_id' => $project_id,
            'invoice_id' => $invoice_id,
            'amount' => $invoice_total,
            'voided_count' => count($voided_invoices),
        ]);

        return $invoice_id;
    }

    // =========================================================================
    // ADMIN POST HANDLERS
    // =========================================================================

    /**
     * Handle milestone invoice generation action.
     */
    public static function handleMilestoneGeneration(): void {
        $milestone_id = isset($_GET['milestone_id']) ? (int) $_GET['milestone_id'] : 0;

        // Verify nonce
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_generate_milestone_invoice_' . $milestone_id)) {
            wp_die('Security check failed');
        }

        // Verify user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die('You do not have permission to perform this action');
        }

        // Generate the invoice
        $result = self::fromMilestone($milestone_id);

        if (is_wp_error($result)) {
            wp_redirect(admin_url('edit.php?post_type=milestone&invoice_error=' . urlencode($result->get_error_message())));
            exit;
        }

        // Redirect to the new invoice
        wp_redirect(admin_url('post.php?post=' . $result . '&action=edit&invoice_created=1'));
        exit;
    }

    /**
     * Handle project closeout invoice generation action.
     */
    public static function handleCloseoutGeneration(): void {
        $project_id = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;

        // Verify nonce
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bbab_generate_project_closeout_' . $project_id)) {
            wp_die('Security check failed');
        }

        // Verify user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die('You do not have permission to perform this action');
        }

        // Generate the invoice
        $result = self::closeoutFromProject($project_id);

        if (is_wp_error($result)) {
            wp_redirect(admin_url('edit.php?post_type=project&closeout_error=' . urlencode($result->get_error_message())));
            exit;
        }

        // Redirect to the new invoice
        wp_redirect(admin_url('post.php?post=' . $result . '&action=edit&closeout_created=1'));
        exit;
    }

    /**
     * Show admin notices for generation results.
     */
    public static function showAdminNotices(): void {
        if (isset($_GET['invoice_created']) && $_GET['invoice_created'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Invoice created successfully!</strong> Review the details and click "Finalize" when ready to generate the PDF.</p></div>';
        }

        if (isset($_GET['invoice_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Invoice generation failed:</strong> ' . esc_html($_GET['invoice_error']) . '</p></div>';
        }

        if (isset($_GET['closeout_created']) && $_GET['closeout_created'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Closeout invoice created successfully!</strong> Review the details and click "Finalize" when ready to generate the PDF.</p></div>';
        }

        if (isset($_GET['closeout_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Closeout generation failed:</strong> ' . esc_html($_GET['closeout_error']) . '</p></div>';
        }
    }

    // =========================================================================
    // INVOICE LIFECYCLE HOOKS
    // =========================================================================

    /**
     * Handle invoice trash - reset milestone/project billing status.
     *
     * @param int $post_id Post ID being trashed.
     */
    public static function handleInvoiceTrash(int $post_id): void {
        if (get_post_type($post_id) !== 'invoice') {
            return;
        }

        // Reset milestone billing status if applicable
        $milestone_id = get_post_meta($post_id, 'related_milestone', true);
        if (!empty($milestone_id)) {
            update_post_meta($milestone_id, 'billing_status', 'Pending');
        }

        // Reset project billing status if this is a closeout invoice
        $is_closeout = get_post_meta($post_id, 'is_closeout_invoice', true);
        if ($is_closeout === '1') {
            $project_id = get_post_meta($post_id, 'related_project', true);
            if (!empty($project_id)) {
                update_post_meta($project_id, 'billing_status', 'In Progress');
            }
        }
    }

    /**
     * Handle invoice delete - reset milestone/project billing status.
     *
     * @param int $post_id Post ID being deleted.
     */
    public static function handleInvoiceDelete(int $post_id): void {
        // Same logic as trash
        self::handleInvoiceTrash($post_id);
    }

    /**
     * Handle invoice untrash - restore milestone/project billing status.
     *
     * @param int $post_id Post ID being untrashed.
     */
    public static function handleInvoiceUntrash(int $post_id): void {
        if (get_post_type($post_id) !== 'invoice') {
            return;
        }

        // Restore milestone billing status
        $milestone_id = get_post_meta($post_id, 'related_milestone', true);
        if (!empty($milestone_id)) {
            $is_deposit = get_post_meta($milestone_id, 'is_deposit', true);
            $new_status = ($is_deposit === '1' || $is_deposit === 1) ? 'Invoiced as Deposit' : 'Invoiced';
            update_post_meta($milestone_id, 'billing_status', $new_status);
        }

        // Restore project billing status if this is a closeout invoice
        $is_closeout = get_post_meta($post_id, 'is_closeout_invoice', true);
        if ($is_closeout === '1') {
            $project_id = get_post_meta($post_id, 'related_project', true);
            if (!empty($project_id)) {
                update_post_meta($project_id, 'billing_status', 'Invoiced');
            }
        }
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Round hours to nearest quarter hour.
     *
     * @param float $hours Hours value.
     * @return float Rounded hours.
     */
    public static function roundToQuarterHour(float $hours): float {
        $minutes = $hours * 60;
        $rounded_minutes = ceil($minutes / 15) * 15;
        return $rounded_minutes / 60;
    }

    /**
     * Get total billable hours for a milestone from its TEs.
     *
     * @param int $milestone_id Milestone post ID.
     * @return float Total billable hours.
     */
    public static function getMilestoneTotalHours(int $milestone_id): float {
        $tes = get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'related_milestone',
                'value' => $milestone_id,
                'compare' => '=',
            ]],
        ]);

        $total_hours = 0.0;
        foreach ($tes as $te) {
            $billable = get_post_meta($te->ID, 'billable', true);
            if ($billable !== '0' && $billable !== 0 && $billable !== false) {
                $total_hours += (float) get_post_meta($te->ID, 'hours', true);
            }
        }

        return $total_hours;
    }
}
