<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;

/**
 * Dashboard Recent Time Entries shortcode.
 *
 * Shows recent work activity for the organization.
 *
 * Shortcode: [dashboard_recent_entries]
 * Attributes:
 * - limit: Number of entries to show (default: 5)
 *
 * Migrated from: WPCode Snippet #1127
 */
class RecentEntries extends BaseShortcode {

    protected string $tag = 'dashboard_recent_entries';

    /**
     * Render the recent entries output.
     */
    protected function output(array $atts, int $org_id): string {
        $atts = shortcode_atts([
            'limit' => 5,
        ], $atts);

        $limit = intval($atts['limit']);

        // Get all Service Requests for this org
        $sr_ids = get_posts([
            'post_type' => 'service_request',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'organization',
                'value' => $org_id,
                'compare' => '=',
            ]],
            'fields' => 'ids',
        ]);

        // Get all Projects for this org
        $project_ids = get_posts([
            'post_type' => 'project',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'organization',
                'value' => $org_id,
                'compare' => '=',
            ]],
            'fields' => 'ids',
        ]);

        // Get all Milestones for those projects
        $milestone_ids = [];
        if (!empty($project_ids)) {
            $milestone_ids = get_posts([
                'post_type' => 'milestone',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [[
                    'key' => 'related_project',
                    'value' => $project_ids,
                    'compare' => 'IN',
                ]],
                'fields' => 'ids',
            ]);
        }

        // Build meta query for time entries
        $te_meta_query = ['relation' => 'OR'];

        if (!empty($sr_ids)) {
            $te_meta_query[] = [
                'key' => 'related_service_request',
                'value' => $sr_ids,
                'compare' => 'IN',
            ];
        }

        if (!empty($project_ids)) {
            $te_meta_query[] = [
                'key' => 'related_project',
                'value' => $project_ids,
                'compare' => 'IN',
            ];
        }

        if (!empty($milestone_ids)) {
            $te_meta_query[] = [
                'key' => 'related_milestone',
                'value' => $milestone_ids,
                'compare' => 'IN',
            ];
        }

        // If no related items exist, no entries to show
        if (count($te_meta_query) <= 1) {
            return '<p style="color: #64748b; text-align: center; padding: 20px;">No activity recorded yet.</p>';
        }

        // Get time entries
        $entries = get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => $limit,
            'post_status' => 'publish',
            'meta_query' => $te_meta_query,
            'meta_key' => 'entry_date',
            'orderby' => 'meta_value',
            'order' => 'DESC',
        ]);

        if (empty($entries)) {
            return '<p style="color: #64748b; text-align: center; padding: 20px;">No activity recorded yet.</p>';
        }

        // Build output
        ob_start();
        ?>
        <div class="bbab-recent-entries" style="font-family: 'Poppins', sans-serif;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background-color: #F3F5F8;">
                        <th style="padding: 10px; text-align: left; font-size: 14px; font-weight: 600; color: #1C244B;">Date</th>
                        <th style="padding: 10px; text-align: left; font-size: 14px; font-weight: 600; color: #1C244B;">Description</th>
                        <th style="padding: 10px; text-align: center; font-size: 14px; font-weight: 600; color: #1C244B;">Hours</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry):
                        $entry_id = $entry->ID;
                        $date = get_post_meta($entry_id, 'entry_date', true);
                        // Use post_title (the descriptive work title) instead of description meta
                        $title = get_the_title($entry_id);
                        $hours = get_post_meta($entry_id, 'hours', true);
                        $billable = get_post_meta($entry_id, 'billable', true);

                        // Format date for display
                        $formatted_date = $date ? date('M j, Y', strtotime($date)) : '';

                        // Get the reference number from the parent item
                        $ref_prefix = '';

                        $related_sr = get_post_meta($entry_id, 'related_service_request', true);
                        $related_project = get_post_meta($entry_id, 'related_project', true);
                        $related_milestone = get_post_meta($entry_id, 'related_milestone', true);

                        if (!empty($related_sr)) {
                            $sr_ref = get_post_meta($related_sr, 'reference_number', true);
                            if ($sr_ref) {
                                $ref_prefix = $sr_ref . ' - ';
                            }
                        } elseif (!empty($related_milestone)) {
                            $ms_ref = get_post_meta($related_milestone, 'reference_number', true);
                            if ($ms_ref) {
                                $ref_prefix = $ms_ref . ' - ';
                            }
                        } elseif (!empty($related_project)) {
                            $proj_ref = get_post_meta($related_project, 'reference_number', true);
                            if ($proj_ref) {
                                $ref_prefix = $proj_ref . ' - ';
                            }
                        }

                        // Build final display text with reference prefix and title
                        if (empty($title)) {
                            $title = '(Work performed)';
                        }
                        $display_description = $ref_prefix . $title;

                        // Hours display with non-billable badge
                        $hours_display = number_format(floatval($hours), 2);
                        $nc_badge = '';
                        if ($billable === '0' || $billable === 0 || $billable === false) {
                            $nc_badge = ' <span style="display: inline-block; background: #d5f5e3; color: #1e8449; font-size: 11px; padding: 2px 6px; border-radius: 4px; margin-left: 4px;">No charge</span>';
                        }
                    ?>
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #eee; font-size: 14px; color: #324A6D; white-space: nowrap;"><?php echo esc_html($formatted_date); ?></td>
                        <td style="padding: 10px; border-bottom: 1px solid #eee; font-size: 14px; color: #324A6D;"><?php echo esc_html($display_description); ?></td>
                        <td style="padding: 10px; text-align: center; border-bottom: 1px solid #eee; font-size: 14px; color: #324A6D; white-space: nowrap;"><?php echo $hours_display . $nc_badge; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}
