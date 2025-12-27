<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;

/**
 * Dashboard Project Payments shortcode.
 *
 * Displays active projects with milestones and linked invoices,
 * showing payment progress for each.
 *
 * Three-tier logic: Project -> Milestone -> Invoice
 * Falls back to direct Project -> Invoice if no milestones exist.
 *
 * Shortcode: [dashboard_project_payments]
 *
 * Migrated from snippet: 1255
 */
class ProjectPayments extends BaseShortcode {

    protected string $tag = 'dashboard_project_payments';
    protected bool $requires_org = true;

    /**
     * Render the project payments widget.
     *
     * @param array $atts   Shortcode attributes.
     * @param int   $org_id Organization ID.
     * @return string HTML output.
     */
    protected function output(array $atts, int $org_id): string {
        // Get active projects for this organization
        $project_posts = get_posts([
            'post_type' => 'project',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'organization',
                'value' => $org_id,
                'compare' => '=',
            ]],
            'orderby' => 'meta_value',
            'meta_key' => 'start_date',
            'order' => 'DESC',
        ]);

        $projects = [];

        foreach ($project_posts as $project_post) {
            $project_id = $project_post->ID;
            $status = get_post_meta($project_id, 'project_status', true);

            // Only show Active or Waiting on Client projects
            if (!in_array($status, ['Active', 'Waiting on Client'])) {
                continue;
            }

            $project_name = get_post_meta($project_id, 'project_name', true) ?: $project_post->post_title;
            $total_budget = floatval(get_post_meta($project_id, 'total_budget', true));

            // Get milestones for this project
            $milestone_posts = get_posts([
                'post_type' => 'milestone',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [[
                    'key' => 'related_project',
                    'value' => $project_id,
                    'compare' => '=',
                ]],
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);

            $milestones = [];
            $milestone_total = 0;
            $total_paid = 0;
            $has_milestones = !empty($milestone_posts);

            if ($has_milestones) {
                // THREE-TIER: Project -> Milestone -> Invoice
                foreach ($milestone_posts as $milestone_post) {
                    $milestone_id = $milestone_post->ID;
                    $milestone_name = get_post_meta($milestone_id, 'milestone_name', true) ?: $milestone_post->post_title;
                    $milestone_amount = floatval(get_post_meta($milestone_id, 'milestone_amount', true));

                    $milestone_total += $milestone_amount;

                    // Get invoices for this milestone
                    $invoice_posts = get_posts([
                        'post_type' => 'invoice',
                        'posts_per_page' => -1,
                        'post_status' => 'publish',
                        'meta_query' => [[
                            'key' => 'related_milestone',
                            'value' => $milestone_id,
                            'compare' => '=',
                        ]],
                        'orderby' => 'meta_value',
                        'meta_key' => 'invoice_date',
                        'order' => 'ASC',
                    ]);

                    $invoices = [];
                    $milestone_paid = 0;

                    foreach ($invoice_posts as $invoice_post) {
                        $inv_amount = floatval(get_post_meta($invoice_post->ID, 'amount', true));
                        $inv_amount_paid = floatval(get_post_meta($invoice_post->ID, 'amount_paid', true));
                        $inv_status = get_post_meta($invoice_post->ID, 'invoice_status', true);
                        $inv_pdf = get_post_meta($invoice_post->ID, 'invoice_pdf', true);

                        if ($inv_status === 'Paid') {
                            $milestone_paid += $inv_amount;
                        } else {
                            $milestone_paid += $inv_amount_paid;
                        }

                        $invoices[] = [
                            'number' => get_post_meta($invoice_post->ID, 'invoice_number', true),
                            'amount' => $inv_amount,
                            'amount_paid' => $inv_amount_paid,
                            'status' => $inv_status,
                            'date' => get_post_meta($invoice_post->ID, 'invoice_date', true),
                            'pdf' => $inv_pdf,
                        ];
                    }

                    $total_paid += $milestone_paid;

                    // Determine milestone status
                    if (empty($invoices)) {
                        $m_status = 'Pending';
                    } elseif ($milestone_paid >= $milestone_amount) {
                        $m_status = 'Paid';
                    } else {
                        $m_status = 'Invoiced';
                    }

                    $milestones[] = [
                        'id' => $milestone_id,
                        'name' => $milestone_name,
                        'amount' => $milestone_amount,
                        'paid' => $milestone_paid,
                        'status' => $m_status,
                        'invoices' => $invoices,
                    ];
                }

                // Project total comes from milestones
                $project_total = $milestone_total;

            } else {
                // TWO-TIER FALLBACK: Project -> Invoice (no milestones)
                $invoice_posts = get_posts([
                    'post_type' => 'invoice',
                    'posts_per_page' => -1,
                    'post_status' => 'publish',
                    'meta_query' => [[
                        'key' => 'related_project',
                        'value' => $project_id,
                        'compare' => '=',
                    ]],
                    'orderby' => 'meta_value',
                    'meta_key' => 'invoice_date',
                    'order' => 'ASC',
                ]);

                $invoices = [];
                $invoiced_total = 0;

                foreach ($invoice_posts as $invoice_post) {
                    $inv_amount = floatval(get_post_meta($invoice_post->ID, 'amount', true));
                    $inv_amount_paid = floatval(get_post_meta($invoice_post->ID, 'amount_paid', true));
                    $inv_status = get_post_meta($invoice_post->ID, 'invoice_status', true);
                    $inv_pdf = get_post_meta($invoice_post->ID, 'invoice_pdf', true);

                    $invoiced_total += $inv_amount;

                    if ($inv_status === 'Paid') {
                        $total_paid += $inv_amount;
                    } else {
                        $total_paid += $inv_amount_paid;
                    }

                    $invoices[] = [
                        'number' => get_post_meta($invoice_post->ID, 'invoice_number', true),
                        'amount' => $inv_amount,
                        'amount_paid' => $inv_amount_paid,
                        'status' => $inv_status,
                        'date' => get_post_meta($invoice_post->ID, 'invoice_date', true),
                        'pdf' => $inv_pdf,
                    ];
                }

                // Create a fake "milestone" for display consistency
                if (!empty($invoices)) {
                    $milestones[] = [
                        'id' => 0,
                        'name' => 'Project Payments',
                        'amount' => $total_budget > 0 ? $total_budget : $invoiced_total,
                        'paid' => $total_paid,
                        'status' => $total_paid >= ($total_budget > 0 ? $total_budget : $invoiced_total) ? 'Paid' : 'Invoiced',
                        'invoices' => $invoices,
                    ];
                }

                // Project total from budget field, or sum of invoices if not set
                $project_total = $total_budget > 0 ? $total_budget : $invoiced_total;
            }

            // Skip projects with no invoices at all
            $has_any_invoices = false;
            foreach ($milestones as $m) {
                if (!empty($m['invoices'])) {
                    $has_any_invoices = true;
                    break;
                }
            }
            if (!$has_any_invoices) {
                continue;
            }

            $projects[] = [
                'id' => $project_id,
                'name' => $project_name,
                'status' => $status,
                'total' => $project_total,
                'paid' => $total_paid,
                'milestones' => $milestones,
                'has_milestones' => $has_milestones,
            ];
        }

        // Don't display section if no active projects with invoices
        if (empty($projects)) {
            return '';
        }

        return $this->renderProjects($projects);
    }

    /**
     * Render the projects HTML.
     *
     * @param array $projects Project data array.
     * @return string HTML output.
     */
    private function renderProjects(array $projects): string {
        ob_start();
        ?>
        <div class="bbab-project-payments">
            <h3>Project Payments</h3>

            <?php foreach ($projects as $project): ?>
                <?php
                $percentage = $project['total'] > 0 ? ($project['paid'] / $project['total']) * 100 : 0;
                $remaining = $project['total'] - $project['paid'];
                ?>
                <div class="bbab-project-card">
                    <div class="bbab-project-header">
                        <h4>
                            <?php echo esc_html($project['name']); ?>
                            <?php if ($project['status'] === 'Waiting on Client'): ?>
                                <span class="bbab-project-status status-waiting">Waiting on You</span>
                            <?php endif; ?>
                        </h4>
                        <span class="bbab-project-totals">
                            $<?php echo number_format($project['paid'], 2); ?> of $<?php echo number_format($project['total'], 2); ?>
                        </span>
                    </div>

                    <div class="bbab-project-progress">
                        <div class="bbab-progress-bar">
                            <div class="bbab-progress-fill" style="width: <?php echo min($percentage, 100); ?>%"></div>
                        </div>
                        <span class="bbab-progress-percent"><?php echo round($percentage); ?>%</span>
                    </div>

                    <?php if ($remaining > 0): ?>
                        <div class="bbab-project-remaining">
                            $<?php echo number_format($remaining, 2); ?> remaining
                        </div>
                    <?php endif; ?>

                    <?php foreach ($project['milestones'] as $milestone): ?>
                        <div class="bbab-milestone-group">
                            <?php if ($project['has_milestones']): ?>
                                <div class="bbab-milestone-header">
                                    <span class="bbab-milestone-title"><?php echo esc_html($milestone['name']); ?></span>
                                    <span class="bbab-milestone-amount">$<?php echo number_format($milestone['amount'], 2); ?></span>
                                    <span class="bbab-milestone-status status-<?php echo esc_attr(sanitize_title($milestone['status'])); ?>">
                                        <?php echo esc_html($milestone['status']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($milestone['invoices'])): ?>
                                <div class="bbab-invoice-list">
                                    <?php if ($project['has_milestones']): ?>
                                        <h5>Invoices</h5>
                                    <?php else: ?>
                                        <h5>Payment History</h5>
                                    <?php endif; ?>

                                    <?php foreach ($milestone['invoices'] as $inv): ?>
                                        <?php $inv_status_class = sanitize_title($inv['status']); ?>
                                        <div class="bbab-invoice-item <?php echo esc_attr($inv_status_class); ?>">
                                            <span class="bbab-invoice-number"><?php echo esc_html($inv['number']); ?></span>
                                            <span class="bbab-invoice-amount">$<?php echo number_format($inv['amount'], 2); ?></span>
                                            <span class="bbab-invoice-status status-<?php echo esc_attr($inv_status_class); ?>">
                                                <?php echo esc_html($inv['status']); ?>
                                            </span>
                                            <?php if (!empty($inv['pdf'])): ?>
                                                <?php
                                                $pdf_url = is_array($inv['pdf']) ? $inv['pdf']['guid'] : wp_get_attachment_url((int) $inv['pdf']);
                                                ?>
                                                <a href="<?php echo esc_url($pdf_url); ?>" target="_blank" class="bbab-invoice-pdf">PDF</a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <style>
            .bbab-project-payments {
                background: #F3F5F8;
                border-radius: 12px;
                padding: 24px;
                margin-bottom: 24px;
                font-family: 'Poppins', sans-serif;
            }
            .bbab-project-payments h3 {
                font-size: 22px;
                font-weight: 600;
                color: #1C244B;
                margin: 0 0 16px 0;
            }
            .bbab-project-card {
                background: white;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 16px;
            }
            .bbab-project-card:last-child {
                margin-bottom: 0;
            }
            .bbab-project-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 12px;
                flex-wrap: wrap;
                gap: 8px;
            }
            .bbab-project-header h4 {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
                color: #1C244B;
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }
            .bbab-project-status {
                font-size: 11px;
                padding: 3px 10px;
                border-radius: 12px;
                font-weight: 500;
            }
            .bbab-project-status.status-waiting {
                background: #fef9e7;
                color: #b7950b;
            }
            .bbab-project-totals {
                font-size: 14px;
                color: #324A6D;
            }
            .bbab-project-progress {
                display: flex;
                align-items: center;
                gap: 12px;
                margin-bottom: 8px;
            }
            .bbab-progress-bar {
                flex: 1;
                height: 12px;
                background: linear-gradient(90deg, #fdedec 0%, #fef9e7 50%, #e8f4fd 100%);
                border-radius: 6px;
                overflow: hidden;
                box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
            }
            .bbab-progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #3498db 0%, #2ecc71 100%);
                border-radius: 6px;
                transition: width 0.5s ease;
            }
            .bbab-progress-percent {
                font-weight: 600;
                font-size: 14px;
                color: #1C244B;
                min-width: 45px;
            }
            .bbab-project-remaining {
                font-size: 13px;
                color: #e67e22;
                margin-bottom: 16px;
            }

            /* Milestone Group */
            .bbab-milestone-group {
                margin-top: 16px;
                padding-top: 16px;
                border-top: 1px solid #eee;
            }
            .bbab-milestone-group:first-of-type {
                margin-top: 12px;
            }
            .bbab-milestone-header {
                display: flex;
                align-items: center;
                gap: 12px;
                margin-bottom: 10px;
                flex-wrap: wrap;
            }
            .bbab-milestone-title {
                font-size: 15px;
                font-weight: 600;
                color: #1C244B;
                flex: 1;
                min-width: 150px;
            }
            .bbab-milestone-amount {
                font-size: 14px;
                font-weight: 500;
                color: #324A6D;
            }
            .bbab-milestone-status {
                font-size: 11px;
                padding: 2px 8px;
                border-radius: 10px;
                font-weight: 500;
            }
            .bbab-milestone-status.status-pending {
                background: #f5f5f5;
                color: #7f8c8d;
            }
            .bbab-milestone-status.status-invoiced {
                background: #fef9e7;
                color: #b7950b;
            }
            .bbab-milestone-status.status-paid {
                background: #d5f5e3;
                color: #1e8449;
            }

            /* Invoice List */
            .bbab-invoice-list h5 {
                font-size: 13px;
                font-weight: 500;
                color: #7f8c8d;
                margin: 8px 0 6px 0;
            }
            .bbab-invoice-item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 6px 0;
                border-bottom: 1px solid #f5f5f5;
                flex-wrap: wrap;
            }
            .bbab-invoice-item:last-child {
                border-bottom: none;
            }
            .bbab-invoice-number {
                font-size: 13px;
                color: #324A6D;
                flex: 1;
                min-width: 100px;
            }
            .bbab-invoice-amount {
                font-size: 13px;
                font-weight: 500;
                color: #1C244B;
            }
            .bbab-invoice-status {
                font-size: 11px;
                padding: 2px 8px;
                border-radius: 10px;
            }
            .bbab-invoice-status.status-paid {
                background: #d5f5e3;
                color: #1e8449;
            }
            .bbab-invoice-status.status-pending {
                background: #fef9e7;
                color: #b7950b;
            }
            .bbab-invoice-status.status-partial {
                background: #e8f4fd;
                color: #2980b9;
            }
            .bbab-invoice-pdf {
                font-size: 12px;
                color: #467FF7;
                text-decoration: none;
            }
            .bbab-invoice-pdf:hover {
                text-decoration: underline;
            }
        </style>
        <?php
        return ob_get_clean();
    }
}
