<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Workbench;

use BBAB\ServiceCenter\Utils\Cache;
use BBAB\ServiceCenter\Utils\Logger;
use BBAB\ServiceCenter\Core\SimulationBootstrap;

/**
 * Brad's Workbench - Main Dashboard Page.
 *
 * Provides an admin command center with quick access to:
 * - Service Requests
 * - Projects
 * - Invoices
 * - Client Tasks
 * - Roadmap Items
 * - Client simulation controls
 */
class WorkbenchPage {

    /**
     * Status sort order for Service Requests.
     */
    private array $sr_status_order = [
        'New' => 1,
        'Acknowledged' => 2,
        'In Progress' => 3,
        'Waiting on Client' => 4,
        'On Hold' => 5,
    ];

    /**
     * Status sort order for Projects.
     */
    private array $project_status_order = [
        'Active' => 1,
        'Waiting on Client' => 2,
        'On Hold' => 3,
    ];

    /**
     * Status sort order for Invoices.
     */
    private array $invoice_status_order = [
        'Draft' => 1,
        'Pending' => 2,
        'Partial' => 3,
        'Overdue' => 4,
    ];

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action('admin_menu', [$this, 'registerMenuPages']);
    }

    /**
     * Register admin menu pages.
     */
    public function registerMenuPages(): void {
        // Main menu page
        add_menu_page(
            __("Brad's Workbench", 'bbab-service-center'),
            __("Brad's Workbench", 'bbab-service-center'),
            'manage_options',
            'bbab-workbench',
            [$this, 'renderMainPage'],
            'dashicons-desktop',
            2
        );

        // Rename the auto-created submenu item
        add_submenu_page(
            'bbab-workbench',
            __("Brad's Workbench", 'bbab-service-center'),
            __('Dashboard', 'bbab-service-center'),
            'manage_options',
            'bbab-workbench',
            [$this, 'renderMainPage']
        );

        // Sub-pages will be added later when those classes are ported
        // For now, just the main dashboard
    }

    /**
     * Render the main workbench page.
     */
    public function renderMainPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'bbab-service-center'));
        }

        // Get data
        $service_requests = $this->getOpenServiceRequests(10);
        $projects = $this->getActiveProjects(10);
        $invoices = $this->getPendingInvoices(10);

        // Get counts
        $sr_total_count = $this->getOpenServiceRequestCount();
        $project_total_count = $this->getActiveProjectCount();
        $invoice_total_count = $this->getPendingInvoiceCount();

        // Get organizations for simulation
        $organizations = $this->getAllOrganizations();

        // Get simulation state
        $simulating_org_id = SimulationBootstrap::getCurrentSimulatedOrgId();
        $simulating_org_name = $simulating_org_id ? get_the_title($simulating_org_id) : '';

        // Render
        ?>
        <div class="wrap bbab-workbench">
            <h1><?php esc_html_e("Brad's Workbench", 'bbab-service-center'); ?></h1>

            <?php $this->renderSimulationBox($organizations, $simulating_org_id, $simulating_org_name); ?>

            <div class="bbab-workbench-grid">
                <?php
                $this->renderServiceRequestsBox($service_requests, $sr_total_count);
                $this->renderProjectsBox($projects, $project_total_count);
                $this->renderInvoicesBox($invoices, $invoice_total_count);
                ?>
            </div>
        </div>

        <style>
            .bbab-workbench-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }
            .bbab-workbench-box {
                background: #fff;
                border: 1px solid #c3c4c7;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .bbab-workbench-box-header {
                padding: 12px 15px;
                border-bottom: 1px solid #c3c4c7;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .bbab-workbench-box-header h2 {
                margin: 0;
                font-size: 14px;
            }
            .bbab-workbench-box-content {
                padding: 0;
            }
            .bbab-workbench-table {
                width: 100%;
                border-collapse: collapse;
            }
            .bbab-workbench-table th,
            .bbab-workbench-table td {
                padding: 8px 12px;
                text-align: left;
                border-bottom: 1px solid #f0f0f1;
            }
            .bbab-workbench-table tr:last-child td {
                border-bottom: none;
            }
            .bbab-status-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 500;
            }
            .status-new { background: #dff0d8; color: #3c763d; }
            .status-acknowledged { background: #d9edf7; color: #31708f; }
            .status-in-progress { background: #fcf8e3; color: #8a6d3b; }
            .status-waiting-on-client { background: #f2dede; color: #a94442; }
            .status-on-hold { background: #e0e0e0; color: #666; }
            .status-active { background: #dff0d8; color: #3c763d; }
            .status-draft { background: #e0e0e0; color: #666; }
            .status-pending { background: #fcf8e3; color: #8a6d3b; }
            .status-overdue { background: #f2dede; color: #a94442; }
            .bbab-simulation-box {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                padding: 15px 20px;
                margin-bottom: 20px;
                border-radius: 4px;
            }
            .bbab-simulation-box.active {
                background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            }
            .bbab-simulation-box h3 {
                margin: 0 0 10px 0;
                color: #fff;
            }
            .bbab-simulation-box select {
                padding: 8px 12px;
                min-width: 200px;
            }
            .bbab-simulation-box .button {
                margin-left: 10px;
            }
        </style>
        <?php
    }

    /**
     * Render the simulation control box.
     */
    private function renderSimulationBox(array $organizations, ?int $simulating_org_id, string $simulating_org_name): void {
        $is_active = $simulating_org_id > 0;
        ?>
        <div class="bbab-simulation-box <?php echo $is_active ? 'active' : ''; ?>">
            <h3>
                <?php if ($is_active): ?>
                    🔍 Simulating: <?php echo esc_html($simulating_org_name); ?>
                <?php else: ?>
                    👤 Client Simulation
                <?php endif; ?>
            </h3>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="bbab-workbench">
                <?php wp_nonce_field('bbab_sc_simulation', '_wpnonce'); ?>

                <?php if ($is_active): ?>
                    <p>You are viewing the site as <strong><?php echo esc_html($simulating_org_name); ?></strong></p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=bbab-workbench&bbab_sc_exit_simulation=1'), 'bbab_sc_simulation')); ?>" class="button button-primary">
                        Exit Simulation
                    </a>
                    <a href="<?php echo esc_url(home_url()); ?>" class="button" target="_blank">
                        View Frontend →
                    </a>
                <?php else: ?>
                    <select name="bbab_sc_simulate_org">
                        <option value="">Select an organization...</option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?php echo esc_attr($org['id']); ?>">
                                <?php echo esc_html($org['name']); ?>
                                <?php if ($org['shortcode']): ?>
                                    (<?php echo esc_html($org['shortcode']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button button-primary">Start Simulation</button>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the service requests box.
     */
    private function renderServiceRequestsBox(array $requests, int $total): void {
        ?>
        <div class="bbab-workbench-box">
            <div class="bbab-workbench-box-header">
                <h2>📋 Service Requests</h2>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=service_request')); ?>">
                    View All (<?php echo esc_html($total); ?>)
                </a>
            </div>
            <div class="bbab-workbench-box-content">
                <?php if (empty($requests)): ?>
                    <p style="padding: 15px; margin: 0;">No open service requests.</p>
                <?php else: ?>
                    <table class="bbab-workbench-table">
                        <thead>
                            <tr>
                                <th>Ref</th>
                                <th>Org</th>
                                <th>Title</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $sr): ?>
                                <?php
                                $ref = get_post_meta($sr->ID, 'reference_number', true);
                                $status = get_post_meta($sr->ID, 'request_status', true);
                                $org_shortcode = $this->getOrgShortcode($sr->ID);
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url(get_edit_post_link($sr->ID)); ?>">
                                            <?php echo esc_html($ref ?: 'SR-' . $sr->ID); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html($org_shortcode); ?></td>
                                    <td><?php echo esc_html(wp_trim_words($sr->post_title, 6)); ?></td>
                                    <td><?php echo $this->renderStatusBadge($status); ?></td>
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
     * Render the projects box.
     */
    private function renderProjectsBox(array $projects, int $total): void {
        ?>
        <div class="bbab-workbench-box">
            <div class="bbab-workbench-box-header">
                <h2>📁 Projects</h2>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=project')); ?>">
                    View All (<?php echo esc_html($total); ?>)
                </a>
            </div>
            <div class="bbab-workbench-box-content">
                <?php if (empty($projects)): ?>
                    <p style="padding: 15px; margin: 0;">No active projects.</p>
                <?php else: ?>
                    <table class="bbab-workbench-table">
                        <thead>
                            <tr>
                                <th>Org</th>
                                <th>Project</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $project): ?>
                                <?php
                                $status = get_post_meta($project->ID, 'project_status', true);
                                $org_shortcode = $this->getOrgShortcode($project->ID);
                                ?>
                                <tr>
                                    <td><?php echo esc_html($org_shortcode); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url(get_edit_post_link($project->ID)); ?>">
                                            <?php echo esc_html(wp_trim_words($project->post_title, 6)); ?>
                                        </a>
                                    </td>
                                    <td><?php echo $this->renderStatusBadge($status); ?></td>
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
     * Render the invoices box.
     */
    private function renderInvoicesBox(array $invoices, int $total): void {
        ?>
        <div class="bbab-workbench-box">
            <div class="bbab-workbench-box-header">
                <h2>💰 Invoices</h2>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=invoice')); ?>">
                    View All (<?php echo esc_html($total); ?>)
                </a>
            </div>
            <div class="bbab-workbench-box-content">
                <?php if (empty($invoices)): ?>
                    <p style="padding: 15px; margin: 0;">No pending invoices.</p>
                <?php else: ?>
                    <table class="bbab-workbench-table">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Org</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <?php
                                $inv_num = get_post_meta($invoice->ID, 'invoice_number', true);
                                $status = get_post_meta($invoice->ID, 'invoice_status', true);
                                $amount = get_post_meta($invoice->ID, 'total_amount', true);
                                $org_shortcode = $this->getOrgShortcode($invoice->ID);
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url(get_edit_post_link($invoice->ID)); ?>">
                                            <?php echo esc_html($inv_num ?: 'INV-' . $invoice->ID); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html($org_shortcode); ?></td>
                                    <td><?php echo esc_html($this->formatCurrency((float)$amount)); ?></td>
                                    <td><?php echo $this->renderStatusBadge($status); ?></td>
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
     * Get all organizations for simulation dropdown.
     */
    private function getAllOrganizations(): array {
        $orgs = get_posts([
            'post_type' => 'client_organization',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $result = [];
        foreach ($orgs as $org) {
            $shortcode = get_post_meta($org->ID, 'shortcode', true)
                ?: get_post_meta($org->ID, 'organization_shortcode', true);
            $result[] = [
                'id' => $org->ID,
                'name' => $org->post_title,
                'shortcode' => $shortcode,
            ];
        }

        return $result;
    }

    /**
     * Get open service requests.
     */
    public function getOpenServiceRequests(int $limit = 10): array {
        $cache_key = 'workbench_open_srs_' . $limit;

        return Cache::remember($cache_key, function() use ($limit) {
            $results = get_posts([
                'post_type' => 'service_request',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'request_status',
                        'value' => ['Completed', 'Cancelled'],
                        'compare' => 'NOT IN',
                    ],
                ],
            ]);

            // Sort by status priority
            usort($results, function($a, $b) {
                $status_a = get_post_meta($a->ID, 'request_status', true);
                $status_b = get_post_meta($b->ID, 'request_status', true);

                $order_a = $this->sr_status_order[$status_a] ?? 99;
                $order_b = $this->sr_status_order[$status_b] ?? 99;

                if ($order_a !== $order_b) {
                    return $order_a - $order_b;
                }

                return strtotime($b->post_date) - strtotime($a->post_date);
            });

            return array_slice($results, 0, $limit);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get open service request count.
     */
    public function getOpenServiceRequestCount(): int {
        $cache_key = 'workbench_open_srs_count';

        return Cache::remember($cache_key, function() {
            $results = get_posts([
                'post_type' => 'service_request',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'request_status',
                        'value' => ['Completed', 'Cancelled'],
                        'compare' => 'NOT IN',
                    ],
                ],
            ]);

            return count($results);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get active projects.
     */
    public function getActiveProjects(int $limit = 10): array {
        $cache_key = 'workbench_active_projects_' . $limit;

        return Cache::remember($cache_key, function() use ($limit) {
            $results = get_posts([
                'post_type' => 'project',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'project_status',
                        'value' => ['Active', 'Waiting on Client', 'On Hold'],
                        'compare' => 'IN',
                    ],
                ],
            ]);

            usort($results, function($a, $b) {
                $status_a = get_post_meta($a->ID, 'project_status', true);
                $status_b = get_post_meta($b->ID, 'project_status', true);

                $order_a = $this->project_status_order[$status_a] ?? 99;
                $order_b = $this->project_status_order[$status_b] ?? 99;

                if ($order_a !== $order_b) {
                    return $order_a - $order_b;
                }

                return strtotime($b->post_date) - strtotime($a->post_date);
            });

            return array_slice($results, 0, $limit);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get active project count.
     */
    public function getActiveProjectCount(): int {
        $cache_key = 'workbench_active_projects_count';

        return Cache::remember($cache_key, function() {
            $results = get_posts([
                'post_type' => 'project',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'project_status',
                        'value' => ['Active', 'Waiting on Client', 'On Hold'],
                        'compare' => 'IN',
                    ],
                ],
            ]);

            return count($results);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get pending invoices.
     */
    public function getPendingInvoices(int $limit = 10): array {
        $cache_key = 'workbench_pending_invoices_' . $limit;

        return Cache::remember($cache_key, function() use ($limit) {
            $results = get_posts([
                'post_type' => 'invoice',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'invoice_status',
                        'value' => ['Paid', 'Cancelled'],
                        'compare' => 'NOT IN',
                    ],
                ],
            ]);

            usort($results, function($a, $b) {
                $status_a = get_post_meta($a->ID, 'invoice_status', true);
                $status_b = get_post_meta($b->ID, 'invoice_status', true);

                $order_a = $this->invoice_status_order[$status_a] ?? 99;
                $order_b = $this->invoice_status_order[$status_b] ?? 99;

                if ($order_a !== $order_b) {
                    return $order_a - $order_b;
                }

                $due_a = get_post_meta($a->ID, 'due_date', true);
                $due_b = get_post_meta($b->ID, 'due_date', true);

                return strtotime($due_a ?: '9999-12-31') - strtotime($due_b ?: '9999-12-31');
            });

            return array_slice($results, 0, $limit);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get pending invoice count.
     */
    public function getPendingInvoiceCount(): int {
        $cache_key = 'workbench_pending_invoices_count';

        return Cache::remember($cache_key, function() {
            $results = get_posts([
                'post_type' => 'invoice',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'invoice_status',
                        'value' => ['Paid', 'Cancelled'],
                        'compare' => 'NOT IN',
                    ],
                ],
            ]);

            return count($results);
        }, HOUR_IN_SECONDS);
    }

    /**
     * Get organization shortcode for a post.
     */
    public function getOrgShortcode(int $post_id): string {
        $org_id = get_post_meta($post_id, 'organization', true);

        if (empty($org_id)) {
            return '';
        }

        if (is_array($org_id)) {
            $org_id = reset($org_id);
        }

        $shortcode = get_post_meta($org_id, 'shortcode', true)
            ?: get_post_meta($org_id, 'organization_shortcode', true);

        return $shortcode ?: '';
    }

    /**
     * Render a status badge.
     */
    public function renderStatusBadge(string $status): string {
        if (empty($status)) {
            return '';
        }

        $css_class = 'status-' . sanitize_title($status);

        return sprintf(
            '<span class="bbab-status-badge %s">%s</span>',
            esc_attr($css_class),
            esc_html($status)
        );
    }

    /**
     * Format currency.
     */
    public function formatCurrency(float $amount): string {
        return '$' . number_format($amount, 2);
    }
}
