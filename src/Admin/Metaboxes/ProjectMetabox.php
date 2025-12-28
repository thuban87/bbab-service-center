<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Metaboxes;

use BBAB\ServiceCenter\Modules\Projects\ProjectService;
use BBAB\ServiceCenter\Modules\Projects\MilestoneService;
use BBAB\ServiceCenter\Modules\Billing\InvoiceService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Project editor metaboxes.
 *
 * Displays on project edit screens:
 * - Milestones table (normal position)
 * - Time Entries grouped by project/milestone (sidebar)
 * - Attachments aggregated from time entries (sidebar)
 *
 * Also handles:
 * - project_link parameter for new milestone pre-population
 * - AJAX handler for next milestone order
 *
 * Migrated from: WPCode Snippet #2740 (Project sections)
 */
class ProjectMetabox {

    /**
     * Register hooks.
     */
    public static function register(): void {
        add_action('add_meta_boxes', [self::class, 'registerMetaboxes']);
        add_action('admin_head', [self::class, 'renderStyles']);
        add_filter('post_row_actions', [self::class, 'addRowActions'], 10, 2);

        // Handle project_link parameter for new milestones
        add_action('load-post-new.php', [self::class, 'handleProjectLinkParam']);
        add_action('admin_footer-post-new.php', [self::class, 'prepopulateMilestoneProject']);

        // AJAX handler for next milestone order
        add_action('wp_ajax_bbab_get_next_milestone_order', [self::class, 'ajaxGetNextMilestoneOrder']);

        Logger::debug('ProjectMetabox', 'Registered project metabox hooks');
    }

    /**
     * Register the metaboxes.
     */
    public static function registerMetaboxes(): void {
        // Closeout Invoice (sidebar, high priority)
        add_meta_box(
            'bbab_project_closeout_invoice',
            'Project Closeout',
            [self::class, 'renderCloseoutInvoiceMetabox'],
            'project',
            'side',
            'high'
        );

        // Milestones table (main content area)
        add_meta_box(
            'bbab_project_milestones',
            'Project Milestones',
            [self::class, 'renderMilestonesMetabox'],
            'project',
            'normal',
            'high'
        );

        // Time Entries (sidebar)
        add_meta_box(
            'bbab_project_time_entries',
            'Project Time Entries',
            [self::class, 'renderTimeEntriesMetabox'],
            'project',
            'side',
            'default'
        );

        // Attachments (sidebar)
        add_meta_box(
            'bbab_project_attachments',
            'Project Attachments',
            [self::class, 'renderAttachmentsMetabox'],
            'project',
            'side',
            'default'
        );
    }

    /**
     * Render the milestones table metabox.
     *
     * @param \WP_Post $post The post object.
     */
    public static function renderMilestonesMetabox(\WP_Post $post): void {
        $project_id = $post->ID;
        $milestones = ProjectService::getMilestones($project_id);

        if (empty($milestones)) {
            echo '<p style="color: #666; font-style: italic; margin: 0;">No milestones created yet.</p>';
            echo '<p style="margin: 12px 0 0 0;"><a href="' . admin_url('post-new.php?post_type=milestone&project_link=' . $project_id) . '" class="button button-primary">+ Add Milestone</a></p>';
            return;
        }

        // Calculate totals
        $total_amount = 0;
        $total_invoiced = 0;
        $total_paid = 0;

        foreach ($milestones as $ms) {
            $amount = MilestoneService::getAmount($ms->ID);
            $total_amount += $amount;

            $billing_status = get_post_meta($ms->ID, 'billing_status', true);
            if (in_array($billing_status, ['Invoiced', 'Invoiced as Deposit', 'Paid'])) {
                $total_invoiced += $amount;
            }
            if ($billing_status === 'Paid') {
                $total_paid += $amount;
            }
        }

        // Summary bar
        echo '<div class="bbab-milestones-summary" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px; margin-bottom: 16px;">';
        echo '<strong>' . count($milestones) . ' milestone' . (count($milestones) !== 1 ? 's' : '') . '</strong>';
        echo ' &nbsp;&bull;&nbsp; Total: $' . number_format($total_amount, 2);
        echo ' &nbsp;&bull;&nbsp; Invoiced: $' . number_format($total_invoiced, 2);
        echo ' &nbsp;&bull;&nbsp; Paid: $' . number_format($total_paid, 2);
        echo '</div>';

        // Milestones table
        echo '<table class="widefat striped" style="margin-bottom: 12px;">';
        echo '<thead><tr>';
        echo '<th style="width: 50px;">#</th>';
        echo '<th>Milestone</th>';
        echo '<th style="width: 100px;">Amount</th>';
        echo '<th style="width: 120px;">Work Status</th>';
        echo '<th style="width: 120px;">Billing</th>';
        echo '<th style="width: 80px;">Actions</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($milestones as $ms) {
            $ms_id = $ms->ID;
            $order = get_post_meta($ms_id, 'milestone_order', true) ?: '—';
            $name = get_post_meta($ms_id, 'milestone_name', true) ?: '(Unnamed)';
            $ref = get_post_meta($ms_id, 'reference_number', true);
            $amount = MilestoneService::getAmount($ms_id);
            $work_status = MilestoneService::getWorkStatus($ms_id);
            $billing_status = get_post_meta($ms_id, 'billing_status', true) ?: 'Pending';
            $is_deposit = MilestoneService::isDeposit($ms_id);
            $edit_link = get_edit_post_link($ms_id);

            echo '<tr>';
            echo '<td style="text-align: center; font-weight: 600;">' . esc_html($order) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_link) . '" style="font-weight: 500; text-decoration: none;">' . esc_html($name) . '</a>';
            if ($ref) {
                echo ' <span style="color: #999; font-size: 12px;">(' . esc_html($ref) . ')</span>';
            }
            if ($is_deposit) {
                echo ' <span style="background: #7c3aed; color: white; padding: 1px 6px; border-radius: 3px; font-size: 10px; margin-left: 4px;">DEPOSIT</span>';
            }
            echo '</td>';
            echo '<td>$' . number_format($amount, 2) . '</td>';
            echo '<td>' . MilestoneService::getWorkStatusBadgeHtml($work_status) . '</td>';
            echo '<td>' . self::getBillingStatusBadge($billing_status) . '</td>';
            echo '<td><a href="' . esc_url($edit_link) . '" class="button button-small">Edit</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Add new milestone button
        echo '<a href="' . admin_url('post-new.php?post_type=milestone&project_link=' . $project_id) . '" class="button button-primary">+ Add Milestone</a>';
    }

    /**
     * Get billing status badge HTML.
     *
     * @param string $status Billing status.
     * @return string HTML badge.
     */
    private static function getBillingStatusBadge(string $status): string {
        $colors = [
            'Pending' => 'background: #f5f5f5; color: #666;',
            'Invoiced' => 'background: #e3f2fd; color: #1976d2;',
            'Invoiced as Deposit' => 'background: #ede9fe; color: #7c3aed;',
            'Paid' => 'background: #d5f5e3; color: #1e8449;',
        ];

        $style = $colors[$status] ?? 'background: #f5f5f5; color: #666;';

        return sprintf(
            '<span style="%s padding: 3px 8px; border-radius: 10px; font-size: 11px; font-weight: 500;">%s</span>',
            $style,
            esc_html($status)
        );
    }

    /**
     * Render time entries metabox.
     *
     * @param \WP_Post $post The post object.
     */
    public static function renderTimeEntriesMetabox(\WP_Post $post): void {
        $project_id = $post->ID;

        // Get time entries directly linked to this project (not via milestones)
        $project_tes = get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'related_project',
                'value' => $project_id,
                'compare' => '=',
            ]],
            'orderby' => 'meta_value',
            'meta_key' => 'entry_date',
            'order' => 'DESC',
        ]);

        // Get all milestones
        $milestones = ProjectService::getMilestones($project_id);

        // Calculate grand totals
        $grand_total_hours = 0.0;
        $grand_total_entries = 0;

        // Project-level hours
        $project_hours = 0.0;
        foreach ($project_tes as $te) {
            $hours = (float) get_post_meta($te->ID, 'hours', true);
            $project_hours += $hours;
            $grand_total_hours += $hours;
            $grand_total_entries++;
        }

        // Collect milestone data
        $milestone_data = [];
        foreach ($milestones as $ms) {
            $ms_id = $ms->ID;
            $ms_name = get_post_meta($ms_id, 'milestone_name', true) ?: '(Unnamed)';
            $ms_ref = get_post_meta($ms_id, 'reference_number', true);

            $ms_tes = get_posts([
                'post_type' => 'time_entry',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [[
                    'key' => 'related_milestone',
                    'value' => $ms_id,
                    'compare' => '=',
                ]],
                'orderby' => 'meta_value',
                'meta_key' => 'entry_date',
                'order' => 'DESC',
            ]);

            $ms_hours = 0.0;
            foreach ($ms_tes as $te) {
                $hours = (float) get_post_meta($te->ID, 'hours', true);
                $ms_hours += $hours;
                $grand_total_hours += $hours;
                $grand_total_entries++;
            }

            $milestone_data[] = [
                'id' => $ms_id,
                'name' => $ms_name,
                'ref' => $ms_ref,
                'entries' => $ms_tes,
                'hours' => $ms_hours,
            ];
        }

        // Grand total summary
        if ($grand_total_entries > 0) {
            echo '<div class="bbab-summary-bar">';
            echo '<strong>Total: ' . $grand_total_entries . ' entr' . ($grand_total_entries === 1 ? 'y' : 'ies') . '</strong> &bull; ' . number_format($grand_total_hours, 2) . ' hours';
            echo '</div>';
        }

        echo '<div class="bbab-scroll-container">';

        // Project-level TEs
        echo '<div class="bbab-te-group">';
        echo '<div class="bbab-group-header-blue">';
        echo '<span>Project-Level</span>';
        echo '<span>' . count($project_tes) . ' entries &bull; ' . number_format($project_hours, 2) . ' hrs</span>';
        echo '</div>';

        if (!empty($project_tes)) {
            foreach ($project_tes as $te) {
                self::renderTeRow($te);
            }
        } else {
            echo '<div class="bbab-empty-state">No project-level time entries.</div>';
        }

        echo '<div class="bbab-group-footer">';
        echo '<a href="' . admin_url('post-new.php?post_type=time_entry&related_project=' . $project_id) . '" class="button button-small">+ Log Time to Project</a>';
        echo '</div>';
        echo '</div>';

        // Milestone TEs (grouped) - only show milestones with time entries
        $milestones_with_entries = 0;
        foreach ($milestone_data as $ms) {
            // Skip milestones with no entries to reduce clutter
            if (empty($ms['entries'])) {
                continue;
            }
            $milestones_with_entries++;

            $ms_label = $ms['ref'] ? $ms['ref'] . ': ' . $ms['name'] : $ms['name'];

            echo '<div class="bbab-te-group" style="margin-top: 12px;">';
            echo '<div class="bbab-group-header-green">';
            echo '<span>' . esc_html($ms_label) . '</span>';
            echo '<span>' . count($ms['entries']) . ' entries &bull; ' . number_format($ms['hours'], 2) . ' hrs</span>';
            echo '</div>';

            foreach ($ms['entries'] as $te) {
                self::renderTeRow($te);
            }

            echo '<div class="bbab-group-footer">';
            echo '<a href="' . admin_url('post-new.php?post_type=time_entry&related_milestone=' . $ms['id']) . '" class="button button-small">+ Log Time</a>';
            echo ' <a href="' . esc_url(get_edit_post_link($ms['id'])) . '" class="button button-small" style="margin-left: 4px;">Edit Milestone</a>';
            echo '</div>';
            echo '</div>';
        }

        // Show summary if milestones exist but have no entries
        if (!empty($milestone_data) && $milestones_with_entries === 0) {
            $total_milestones = count($milestone_data);
            echo '<div class="bbab-empty-state" style="margin: 12px;">';
            echo esc_html($total_milestones) . ' milestone' . ($total_milestones > 1 ? 's' : '') . ' exist, but none have time entries yet.';
            echo '</div>';
        }

        if (empty($milestone_data)) {
            echo '<div class="bbab-empty-state" style="margin: 12px; text-align: center; border-radius: 4px;">';
            echo 'No milestones created yet. <a href="' . admin_url('post-new.php?post_type=milestone&project_link=' . $project_id) . '">Add one</a>';
            echo '</div>';
        }

        echo '</div>'; // Close scroll container
    }

    /**
     * Render a single time entry row.
     *
     * @param \WP_Post $te Time entry post.
     */
    private static function renderTeRow(\WP_Post $te): void {
        $te_id = $te->ID;
        $date = get_post_meta($te_id, 'entry_date', true);
        $title = get_the_title($te_id);
        $te_ref = get_post_meta($te_id, 'reference_number', true);
        $hours = (float) get_post_meta($te_id, 'hours', true);
        $billable = get_post_meta($te_id, 'billable', true);
        $edit_link = get_edit_post_link($te_id);

        $formatted_date = $date ? date('M j', strtotime($date)) : '—';
        $billable_badge = ($billable === '0' || $billable === 0) ? ' <span style="background: #d5f5e3; color: #1e8449; padding: 1px 5px; border-radius: 3px; font-size: 10px;">NC</span>' : '';

        $display_text = $te_ref ?: 'TE';
        if (!empty($title) && $title !== 'Auto Draft' && $title !== $te_ref) {
            $display_text = $te_ref . ' - ' . wp_trim_words($title, 8, '...');
        }

        echo '<div class="bbab-te-row">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center;">';
        echo '<span style="color: #666;">' . esc_html($formatted_date) . '</span>';
        echo '<span>' . number_format($hours, 2) . ' hrs' . $billable_badge . '</span>';
        echo '</div>';
        echo '<div style="margin-top: 3px;"><a href="' . esc_url($edit_link) . '">' . esc_html($display_text) . '</a></div>';
        echo '</div>';
    }

    /**
     * Render attachments metabox.
     *
     * @param \WP_Post $post The post object.
     */
    public static function renderAttachmentsMetabox(\WP_Post $post): void {
        $project_id = $post->ID;

        // Get time entries directly linked to this project
        $project_te_ids = get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'related_project',
                'value' => $project_id,
                'compare' => '=',
            ]],
            'fields' => 'ids',
        ]);

        // Get all milestones
        $milestones = ProjectService::getMilestones($project_id);

        // Collect project-level attachments
        $project_attachments = self::collectAttachments($project_te_ids);

        // Collect milestone attachments
        $milestone_data = [];
        foreach ($milestones as $ms) {
            $ms_id = $ms->ID;
            $ms_name = get_post_meta($ms_id, 'milestone_name', true) ?: '(Unnamed)';
            $ms_ref = get_post_meta($ms_id, 'reference_number', true);

            $ms_te_ids = get_posts([
                'post_type' => 'time_entry',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [[
                    'key' => 'related_milestone',
                    'value' => $ms_id,
                    'compare' => '=',
                ]],
                'fields' => 'ids',
            ]);

            $milestone_data[] = [
                'name' => $ms_name,
                'ref' => $ms_ref,
                'attachments' => self::collectAttachments($ms_te_ids),
            ];
        }

        // Calculate grand total
        $grand_total = count($project_attachments);
        foreach ($milestone_data as $ms) {
            $grand_total += count($ms['attachments']);
        }

        if ($grand_total === 0) {
            echo '<div style="padding: 12px;">';
            echo '<p style="color: #666; font-style: italic; margin: 0;">No attachments uploaded to time entries.</p>';
            echo '</div>';
            return;
        }

        // Summary
        echo '<div class="bbab-summary-bar">';
        echo '<strong>' . $grand_total . ' file' . ($grand_total !== 1 ? 's' : '') . ' total</strong>';
        echo '</div>';

        echo '<div class="bbab-scroll-container">';

        // Project-level attachments
        echo '<div class="bbab-attachment-group">';
        echo '<div class="bbab-group-header-blue">';
        echo '<span>Project-Level</span>';
        echo '<span>' . count($project_attachments) . ' file' . (count($project_attachments) !== 1 ? 's' : '') . '</span>';
        echo '</div>';

        if (!empty($project_attachments)) {
            foreach ($project_attachments as $att_id) {
                self::renderAttachmentRow((int) $att_id);
            }
        } else {
            echo '<div class="bbab-empty-state">No project-level attachments.</div>';
        }
        echo '</div>';

        // Milestone attachments
        foreach ($milestone_data as $ms) {
            $ms_label = $ms['ref'] ? $ms['ref'] . ': ' . $ms['name'] : $ms['name'];

            echo '<div class="bbab-attachment-group" style="margin-top: 12px;">';
            echo '<div class="bbab-group-header-green">';
            echo '<span>' . esc_html($ms_label) . '</span>';
            echo '<span>' . count($ms['attachments']) . ' file' . (count($ms['attachments']) !== 1 ? 's' : '') . '</span>';
            echo '</div>';

            if (!empty($ms['attachments'])) {
                foreach ($ms['attachments'] as $att_id) {
                    self::renderAttachmentRow((int) $att_id);
                }
            } else {
                echo '<div class="bbab-empty-state">No attachments.</div>';
            }
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Collect unique attachment IDs from time entries.
     *
     * @param array $te_ids Time entry IDs.
     * @return array Unique attachment IDs.
     */
    private static function collectAttachments(array $te_ids): array {
        $attachments = [];

        foreach ($te_ids as $te_id) {
            $atts = get_post_meta($te_id, 'attachments', false);
            if (!empty($atts) && is_array($atts[0])) {
                $atts = $atts[0];
            }

            if (!empty($atts)) {
                if (!is_array($atts)) {
                    $atts = [$atts];
                }
                foreach ($atts as $att_id) {
                    if (!empty($att_id) && !in_array($att_id, $attachments)) {
                        $attachments[] = $att_id;
                    }
                }
            }
        }

        return $attachments;
    }

    /**
     * Render a single attachment row.
     *
     * @param int $att_id Attachment ID.
     */
    private static function renderAttachmentRow(int $att_id): void {
        $url = wp_get_attachment_url($att_id);
        $filename = basename(get_attached_file($att_id));
        $mime = get_post_mime_type($att_id);
        $icon = self::getFileIcon($mime);

        if (!$url) {
            return;
        }

        echo '<div class="bbab-attachment-row">';
        echo '<a href="' . esc_url($url) . '" target="_blank" title="' . esc_attr($filename) . '">';
        echo $icon . ' <span class="attachment-filename">' . esc_html($filename) . '</span>';
        echo '</a>';
        echo '</div>';
    }

    /**
     * Get file icon based on MIME type.
     *
     * @param string $mime_type MIME type.
     * @return string Icon emoji.
     */
    private static function getFileIcon(string $mime_type): string {
        if (strpos($mime_type, 'image/') === 0) {
            return "\xF0\x9F\x96\xBC\xEF\xB8\x8F"; // framed picture
        } elseif ($mime_type === 'application/pdf') {
            return "\xF0\x9F\x93\x84"; // document
        } elseif (strpos($mime_type, 'word') !== false || strpos($mime_type, 'document') !== false) {
            return "\xF0\x9F\x93\x9D"; // memo
        } elseif (strpos($mime_type, 'sheet') !== false || strpos($mime_type, 'excel') !== false) {
            return "\xF0\x9F\x93\x8A"; // chart
        } elseif (strpos($mime_type, 'zip') !== false || strpos($mime_type, 'compressed') !== false) {
            return "\xF0\x9F\x93\xA6"; // package
        }
        return "\xF0\x9F\x93\x8E"; // file
    }

    /**
     * Handle project_link parameter for new milestones.
     */
    public static function handleProjectLinkParam(): void {
        if (!isset($_GET['post_type']) || $_GET['post_type'] !== 'milestone' || !isset($_GET['project_link'])) {
            return;
        }

        $project_id = intval($_GET['project_link']);
        $user_id = get_current_user_id();

        set_transient('bbab_pending_project_link_' . $user_id, $project_id, 300);
    }

    /**
     * Pre-populate milestone project field via JavaScript.
     */
    public static function prepopulateMilestoneProject(): void {
        global $post_type;
        if ($post_type !== 'milestone') {
            return;
        }

        $user_id = get_current_user_id();
        $project_id = get_transient('bbab_pending_project_link_' . $user_id);

        if (!$project_id) {
            return;
        }

        delete_transient('bbab_pending_project_link_' . $user_id);
        $nonce = wp_create_nonce('bbab_milestone_order');

        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var projectId = <?php echo intval($project_id); ?>;

            // Set the related_project field
            var $select = $('select[name="related_project"]');
            if ($select.length) {
                $select.val(projectId).trigger('change');
            }

            // Get next milestone order
            $.post(ajaxurl, {
                action: 'bbab_get_next_milestone_order',
                project_id: projectId,
                nonce: '<?php echo $nonce; ?>'
            }, function(response) {
                if (response.success && response.data.next_order) {
                    $('input[name="milestone_order"]').val(response.data.next_order);
                }
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler to get next milestone order for a project.
     */
    public static function ajaxGetNextMilestoneOrder(): void {
        check_ajax_referer('bbab_milestone_order', 'nonce');

        $project_id = intval($_POST['project_id'] ?? 0);
        if (!$project_id) {
            wp_send_json_error(['message' => 'No project ID']);
        }

        global $wpdb;
        $max_order = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(pm.meta_value AS UNSIGNED))
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} pm2 ON pm.post_id = pm2.post_id
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = 'milestone_order'
             AND pm2.meta_key = 'related_project'
             AND pm2.meta_value = %d
             AND p.post_status = 'publish'
             AND p.post_type = 'milestone'",
            $project_id
        ));

        $next_order = ($max_order ? intval($max_order) : 0) + 1;

        wp_send_json_success(['next_order' => $next_order]);
    }

    /**
     * Render closeout invoice metabox.
     *
     * @param \WP_Post $post The post object.
     */
    public static function renderCloseoutInvoiceMetabox(\WP_Post $post): void {
        $billing_status = get_post_meta($post->ID, 'billing_status', true) ?: 'Not Started';
        $org_id = get_post_meta($post->ID, 'organization', true);
        $project_ref = get_post_meta($post->ID, 'reference_number', true) ?: 'PR-????';

        // Get all milestones for this project
        $milestones = ProjectService::getMilestones($post->ID);

        // Analyze milestone statuses
        $warnings = [];
        $milestone_summary = [];

        foreach ($milestones as $milestone) {
            $ms_name = get_post_meta($milestone->ID, 'milestone_name', true) ?: get_the_title($milestone->ID);
            $ms_ref = get_post_meta($milestone->ID, 'reference_number', true) ?: '';
            $ms_billing_status = get_post_meta($milestone->ID, 'billing_status', true) ?: 'Pending';

            // Check for existing invoice
            $existing_invoices = InvoiceService::getForMilestone($milestone->ID);

            $invoice_status = '';
            if (!empty($existing_invoices)) {
                $invoice_status = InvoiceService::getStatus($existing_invoices[0]->ID);
            }

            // Determine display status and warnings
            $display_status = $ms_billing_status;
            $status_color = '#6b7280';

            if ($invoice_status === 'Draft') {
                $display_status = 'Draft Invoice';
                $status_color = '#d97706';
                $warnings[] = '<strong>' . esc_html($ms_ref ?: $ms_name) . '</strong> has a Draft invoice (TEs not approved)';
            } elseif ($invoice_status === 'Paid') {
                $display_status = 'Paid';
                $status_color = '#059669';
            } elseif ($invoice_status === 'Partial') {
                $display_status = 'Partial';
                $status_color = '#2563eb';
            } elseif (in_array($invoice_status, ['Pending', 'Overdue'], true)) {
                $display_status = $invoice_status . ' (will void)';
                $status_color = '#dc2626';
            } elseif ($ms_billing_status === 'Pending' || empty($ms_billing_status)) {
                $display_status = 'Not Invoiced';
                $status_color = '#d97706';
                $warnings[] = '<strong>' . esc_html($ms_ref ?: $ms_name) . '</strong> has not been invoiced yet';
            }

            $milestone_summary[] = [
                'name' => $ms_ref ?: $ms_name,
                'status' => $display_status,
                'color' => $status_color,
            ];
        }

        // Check for project-level TEs
        $project_tes = get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'related_project',
                'value' => $post->ID,
                'compare' => '=',
            ]],
        ]);

        // Get existing closeout invoice if any
        $existing_closeout = InvoiceService::getCloseoutForProject($post->ID);

        echo '<div style="padding: 10px 0;">';

        // Billing status badge
        $status_colors = [
            'Not Started' => '#6b7280',
            'In Progress' => '#2563eb',
            'Invoiced' => '#059669',
        ];
        $color = $status_colors[$billing_status] ?? '#6b7280';
        echo '<p><strong>Billing Status:</strong> <span style="color: ' . $color . '; font-weight: 600;">' . esc_html($billing_status) . '</span></p>';

        // Project TEs count
        echo '<p><strong>Project-level TEs:</strong> ' . count($project_tes) . '</p>';

        echo '<hr style="margin: 12px 0; border: none; border-top: 1px solid #e5e7eb;">';

        // Milestone summary
        if (!empty($milestone_summary)) {
            echo '<p style="margin-bottom: 8px;"><strong>Milestones:</strong></p>';
            echo '<div style="font-size: 12px; max-height: 150px; overflow-y: auto;">';
            foreach ($milestone_summary as $ms) {
                echo '<div style="padding: 4px 0; border-bottom: 1px solid #f3f4f6;">';
                echo '<span style="color: ' . $ms['color'] . ';">&#9679;</span> ';
                echo esc_html($ms['name']) . ' - <span style="color: ' . $ms['color'] . ';">' . esc_html($ms['status']) . '</span>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<p style="color: #6b7280; font-size: 12px;">No milestones found.</p>';
        }

        echo '<hr style="margin: 12px 0; border: none; border-top: 1px solid #e5e7eb;">';

        // Warnings
        if (!empty($warnings)) {
            echo '<div style="background: #fef3c7; border-left: 3px solid #f59e0b; padding: 8px; margin-bottom: 12px; font-size: 11px;">';
            echo '<strong style="color: #92400e;">Warnings:</strong><br>';
            echo implode('<br>', $warnings);
            echo '<br><br><em>These milestones will be excluded from line items but shown on details page.</em>';
            echo '</div>';
        }

        // If already closed out, show link to invoice
        if ($existing_closeout) {
            $invoice_number = get_post_meta($existing_closeout->ID, 'invoice_number', true);
            $edit_link = get_edit_post_link($existing_closeout->ID);

            echo '<p style="margin-bottom: 10px;">Closeout Invoice: <a href="' . esc_url($edit_link) . '"><strong>' . esc_html($invoice_number) . '</strong></a></p>';
        }
        // Show generate button if eligible
        elseif (!empty($org_id) && $billing_status !== 'Invoiced') {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=bbab_generate_project_closeout&project_id=' . $post->ID),
                'bbab_generate_project_closeout_' . $post->ID
            );

            echo '<a href="' . esc_url($url) . '" class="button button-primary" style="width: 100%; text-align: center; background: #059669; border-color: #059669;">Generate Closeout Invoice</a>';

            if (!empty($warnings)) {
                echo '<p style="color: #92400e; font-size: 10px; margin-top: 6px;">Proceeding will exclude warned milestones from billing.</p>';
            }
        }
        // Show why we can't generate
        else {
            echo '<p style="color: #dc2626; font-size: 12px;">';
            if (empty($org_id)) {
                echo 'Cannot generate: No organization linked.';
            } elseif ($billing_status === 'Invoiced') {
                echo 'Project already closed out.';
            }
            echo '</p>';
        }

        echo '</div>';
    }

    /**
     * Add row actions to project list.
     *
     * @param array    $actions Existing actions.
     * @param \WP_Post $post    The post object.
     * @return array Modified actions.
     */
    public static function addRowActions(array $actions, \WP_Post $post): array {
        if ($post->post_type !== 'project') {
            return $actions;
        }

        // Check if project already closed out
        $billing_status = get_post_meta($post->ID, 'billing_status', true) ?: 'Not Started';

        if ($billing_status === 'Invoiced') {
            // Show link to existing closeout invoice
            $existing_closeout = InvoiceService::getCloseoutForProject($post->ID);
            if ($existing_closeout) {
                $edit_link = get_edit_post_link($existing_closeout->ID);
                $invoice_number = get_post_meta($existing_closeout->ID, 'invoice_number', true);
                $actions['view_closeout'] = '<a href="' . esc_url($edit_link) . '" style="color: #059669;">View Closeout Invoice (' . esc_html($invoice_number) . ')</a>';
            }
            return $actions;
        }

        // Check if project has an organization
        $org_id = get_post_meta($post->ID, 'organization', true);
        if (empty($org_id)) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bbab_generate_project_closeout&project_id=' . $post->ID),
            'bbab_generate_project_closeout_' . $post->ID
        );

        $actions['generate_closeout'] = '<a href="' . esc_url($url) . '" style="color: #059669; font-weight: 500;">Generate Closeout Invoice</a>';

        return $actions;
    }

    /**
     * Render admin styles for metaboxes.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'project') {
            return;
        }

        echo '<style>
            /* Milestones Table */
            #bbab_project_milestones .inside { margin: 0; padding: 12px; }
            #bbab_project_milestones table { border: 1px solid #ddd; }
            #bbab_project_milestones th { background: #f9f9f9; font-weight: 600; }
            #bbab_project_milestones td,
            #bbab_project_milestones th { padding: 10px 12px; vertical-align: middle; }

            /* Sidebar Metaboxes */
            #bbab_project_time_entries .inside,
            #bbab_project_attachments .inside { margin: 0; padding: 0; }

            /* Group Headers */
            .bbab-group-header-blue {
                background: #1e40af; color: white; padding: 8px 10px;
                font-weight: 600; font-size: 12px;
                display: flex; justify-content: space-between; align-items: center;
            }
            .bbab-group-header-green {
                background: #059669; color: white; padding: 8px 10px;
                font-weight: 600; font-size: 12px;
                display: flex; justify-content: space-between; align-items: center;
            }

            /* Rows */
            .bbab-te-row, .bbab-attachment-row {
                padding: 8px 10px; border-bottom: 1px solid #eee;
                font-size: 12px; background: white;
            }
            .bbab-te-row a, .bbab-attachment-row a { text-decoration: none; color: #1e40af; }
            .bbab-te-row a:hover, .bbab-attachment-row a:hover { text-decoration: underline; }

            .bbab-attachment-row a { display: flex; align-items: center; gap: 6px; }
            .attachment-filename {
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
                max-width: 180px; display: inline-block;
            }

            /* Empty State */
            .bbab-empty-state {
                padding: 10px; color: #666; font-style: italic;
                font-size: 12px; background: #fafafa;
            }

            /* Summary & Footer */
            .bbab-summary-bar {
                background: #f0f6fc; border-left: 4px solid #2271b1;
                padding: 10px 12px; margin: 12px; font-size: 13px;
            }
            .bbab-group-footer {
                padding: 8px 10px; background: #f9fafb; border-top: 1px solid #e5e7eb;
            }

            /* Scroll Container */
            .bbab-scroll-container { max-height: 400px; overflow-y: auto; }
        </style>';
    }
}
