<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Metaboxes;

use BBAB\ServiceCenter\Modules\Projects\MilestoneService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Milestone editor metaboxes.
 *
 * Displays on milestone edit screens:
 * - Time Entries for this milestone (sidebar)
 * - Attachments from time entries (sidebar)
 *
 * Also handles:
 * - project_link and milestone_link parameters for new TE pre-population
 *
 * Migrated from: WPCode Snippet #2740 (Milestone sections)
 */
class MilestoneMetabox {

    /**
     * Register hooks.
     */
    public static function register(): void {
        add_action('add_meta_boxes', [self::class, 'registerMetaboxes']);
        add_action('admin_head', [self::class, 'renderStyles']);

        // Note: TE pre-population (project_link, milestone_link params) is handled by TimeEntryLinker

        Logger::debug('MilestoneMetabox', 'Registered milestone metabox hooks');
    }

    /**
     * Register the metaboxes.
     */
    public static function registerMetaboxes(): void {
        // Time Entries (sidebar)
        add_meta_box(
            'bbab_milestone_time_entries',
            'Time Entries',
            [self::class, 'renderTimeEntriesMetabox'],
            'milestone',
            'side',
            'default'
        );

        // Attachments (sidebar)
        add_meta_box(
            'bbab_milestone_attachments',
            'Attachments',
            [self::class, 'renderAttachmentsMetabox'],
            'milestone',
            'side',
            'default'
        );
    }

    /**
     * Render time entries metabox.
     *
     * @param \WP_Post $post The post object.
     */
    public static function renderTimeEntriesMetabox(\WP_Post $post): void {
        $milestone_id = $post->ID;

        $time_entries = get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'related_milestone',
                'value' => $milestone_id,
                'compare' => '=',
            ]],
            'orderby' => 'meta_value',
            'meta_key' => 'entry_date',
            'order' => 'DESC',
        ]);

        // Calculate totals
        $total_hours = 0.0;
        $billable_hours = 0.0;
        foreach ($time_entries as $te) {
            $hours = (float) get_post_meta($te->ID, 'hours', true);
            $total_hours += $hours;
            $billable = get_post_meta($te->ID, 'billable', true);
            if ($billable !== '0' && $billable !== 0 && $billable !== false) {
                $billable_hours += $hours;
            }
        }

        $count = count($time_entries);

        // Summary bar
        echo '<div class="bbab-summary-bar">';
        echo '<strong>' . $count . ' entr' . ($count === 1 ? 'y' : 'ies') . '</strong> &bull; ' . number_format($total_hours, 2) . ' hrs';
        if ($billable_hours !== $total_hours) {
            echo ' <span style="color: #666;">(' . number_format($billable_hours, 2) . ' billable)</span>';
        }
        echo '</div>';

        // Time entries group
        echo '<div class="bbab-te-group">';
        echo '<div class="bbab-group-header-green">';
        echo '<span>Time Entries</span>';
        echo '<span>' . $count . ' entries &bull; ' . number_format($total_hours, 2) . ' hrs</span>';
        echo '</div>';

        if ($count > 0) {
            echo '<div class="bbab-scroll-container" style="max-height: 250px;">';
            foreach ($time_entries as $te) {
                self::renderTeRow($te);
            }
            echo '</div>';
        } else {
            echo '<div class="bbab-empty-state">No time entries logged yet.</div>';
        }

        // Footer with button
        echo '<div class="bbab-group-footer">';
        echo '<a href="' . admin_url('post-new.php?post_type=time_entry&related_milestone=' . $milestone_id) . '" class="button button-primary">+ Log Time</a>';
        echo '</div>';
        echo '</div>';
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

        // Display: TE-XXXX - Title (or just TE-XXXX if no title)
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
        $milestone_id = $post->ID;

        $time_entry_ids = get_posts([
            'post_type' => 'time_entry',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'related_milestone',
                'value' => $milestone_id,
                'compare' => '=',
            ]],
            'fields' => 'ids',
        ]);

        if (empty($time_entry_ids)) {
            echo '<div style="padding: 12px;">';
            echo '<p style="color: #666; font-style: italic; margin: 0;">No time entries to check for attachments.</p>';
            echo '</div>';
            return;
        }

        // Collect all attachments
        $all_attachments = [];
        foreach ($time_entry_ids as $te_id) {
            $attachments = get_post_meta($te_id, 'attachments', false);
            if (!empty($attachments) && is_array($attachments[0])) {
                $attachments = $attachments[0];
            }

            if (!empty($attachments)) {
                if (!is_array($attachments)) {
                    $attachments = [$attachments];
                }
                foreach ($attachments as $att_id) {
                    if (!empty($att_id) && !in_array($att_id, $all_attachments)) {
                        $all_attachments[] = $att_id;
                    }
                }
            }
        }

        if (empty($all_attachments)) {
            echo '<div style="padding: 12px;">';
            echo '<p style="color: #666; font-style: italic; margin: 0;">No attachments uploaded to time entries.</p>';
            echo '</div>';
            return;
        }

        $count = count($all_attachments);

        // Header
        echo '<div class="bbab-group-header-green" style="margin: 0;">';
        echo '<span>Files</span>';
        echo '<span>' . $count . ' file' . ($count !== 1 ? 's' : '') . '</span>';
        echo '</div>';

        // Attachments list
        echo '<div style="max-height: 200px; overflow-y: auto;">';
        foreach ($all_attachments as $att_id) {
            self::renderAttachmentRow((int) $att_id);
        }
        echo '</div>';
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
            return "\xF0\x9F\x96\xBC\xEF\xB8\x8F";
        } elseif ($mime_type === 'application/pdf') {
            return "\xF0\x9F\x93\x84";
        } elseif (strpos($mime_type, 'word') !== false || strpos($mime_type, 'document') !== false) {
            return "\xF0\x9F\x93\x9D";
        } elseif (strpos($mime_type, 'sheet') !== false || strpos($mime_type, 'excel') !== false) {
            return "\xF0\x9F\x93\x8A";
        } elseif (strpos($mime_type, 'zip') !== false || strpos($mime_type, 'compressed') !== false) {
            return "\xF0\x9F\x93\xA6";
        }
        return "\xF0\x9F\x93\x8E";
    }

    /**
     * Render admin styles for metaboxes.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'milestone') {
            return;
        }

        echo '<style>
            /* Sidebar Metaboxes */
            #bbab_milestone_time_entries .inside,
            #bbab_milestone_attachments .inside { margin: 0; padding: 0; }

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
