<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Metaboxes;

use BBAB\ServiceCenter\Modules\TimeTracking\TimeEntryService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Metaboxes for Service Request edit screen.
 *
 * Includes:
 * - Linked Time Entries metabox (shows all TEs for this SR)
 * - Aggregated Attachments metabox (shows files from all linked TEs)
 *
 * Migrated from: WPCode Snippet #1906
 */
class ServiceRequestMetabox {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        add_action('add_meta_boxes', [self::class, 'registerMetaboxes']);
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('ServiceRequestMetabox', 'Registered SR metabox hooks');
    }

    /**
     * Register the metaboxes.
     */
    public static function registerMetaboxes(): void {
        add_meta_box(
            'bbab_sr_time_entries',
            '&#9200; Linked Time Entries',
            [self::class, 'renderTimeEntriesMetabox'],
            'service_request',
            'side',
            'high'
        );

        add_meta_box(
            'bbab_sr_attachments',
            '&#128206; Time Entry Attachments',
            [self::class, 'renderAttachmentsMetabox'],
            'service_request',
            'side',
            'default'
        );
    }

    /**
     * Render the Time Entries metabox.
     *
     * @param \WP_Post $post Current post object.
     */
    public static function renderTimeEntriesMetabox(\WP_Post $post): void {
        $sr_id = $post->ID;

        // Get all linked time entries (published only)
        $entry_ids = TimeEntryService::getEntriesForSR($sr_id);

        if (empty($entry_ids)) {
            echo '<p style="color: #666; font-style: italic;">No time entries logged yet.</p>';
            echo '<p><a href="' . esc_url(admin_url('post-new.php?post_type=time_entry&related_service_request=' . $sr_id)) . '" class="button button-primary">+ Log Time</a></p>';
            return;
        }

        // Calculate total hours
        $total_hours = 0;
        foreach ($entry_ids as $entry_id) {
            $hours = get_post_meta($entry_id, 'hours', true);
            $total_hours += floatval($hours);
        }

        $count = count($entry_ids);
        $plural = $count === 1 ? 'entry' : 'entries';

        echo '<div class="bbab-time-entries-summary">';
        echo '<strong>' . $count . ' ' . $plural . ', ' . number_format($total_hours, 2) . ' hours total</strong>';
        echo '</div>';

        echo '<div class="bbab-time-entries-list">';

        foreach ($entry_ids as $entry_id) {
            $entry_date = get_post_meta($entry_id, 'entry_date', true);
            $description = get_post_meta($entry_id, 'description', true);
            $hours = get_post_meta($entry_id, 'hours', true);
            $work_type_id = get_post_meta($entry_id, 'work_type', true);
            $billable = get_post_meta($entry_id, 'billable', true);

            // Handle array storage
            if (is_array($entry_date)) {
                $entry_date = reset($entry_date);
            }

            // Get work type name
            $work_type = '';
            if ($work_type_id) {
                $term = get_term((int) $work_type_id, 'work_type');
                if ($term && !is_wp_error($term)) {
                    $work_type = $term->name;
                }
            }

            // Format date
            $formatted_date = $entry_date ? date('M j, Y', strtotime($entry_date)) : 'No date';

            // Non-billable badge
            $billable_badge = '';
            if ($billable === '0' || $billable === 0 || $billable === false) {
                $billable_badge = ' <span class="non-billable-badge">No charge</span>';
            }

            echo '<div class="bbab-time-entry-row">';
            echo '<div class="entry-date">&#128197; ' . esc_html($formatted_date) . '</div>';
            echo '<div class="entry-description">' . esc_html($description ?: '(No description)') . '</div>';
            echo '<div class="entry-meta">';
            echo number_format(floatval($hours), 2) . ' hrs';
            if ($work_type) {
                echo ' &bull; ' . esc_html($work_type);
            }
            echo $billable_badge;
            echo ' &bull; <a href="' . esc_url(get_edit_post_link($entry_id)) . '">Edit</a>';
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';

        echo '<div class="bbab-time-entries-footer">';
        echo '<a href="' . esc_url(admin_url('post-new.php?post_type=time_entry&related_service_request=' . $sr_id)) . '" class="button button-primary">+ Log More Time</a>';
        echo '</div>';
    }

    /**
     * Render the Attachments metabox.
     *
     * @param \WP_Post $post Current post object.
     */
    public static function renderAttachmentsMetabox(\WP_Post $post): void {
        $sr_id = $post->ID;

        // Get all linked time entries
        $entry_ids = TimeEntryService::getEntriesForSR($sr_id);

        if (empty($entry_ids)) {
            echo '<p style="color: #666; font-style: italic; margin: 0;">No time entries to check for attachments.</p>';
            return;
        }

        // Collect all attachments from TEs
        $all_attachments = [];

        foreach ($entry_ids as $te_id) {
            // Get all attachment values (Pods may store multi-file as separate meta rows)
            $attachments = get_post_meta($te_id, 'attachments', false);
            if (!empty($attachments) && is_array($attachments[0])) {
                // If Pods stored as serialized array, flatten it
                $attachments = $attachments[0];
            }

            if (empty($attachments)) {
                continue;
            }

            // Normalize to array
            if (!is_array($attachments)) {
                $attachments = [$attachments];
            }

            foreach ($attachments as $att_id) {
                if (!empty($att_id) && !isset($all_attachments[$att_id])) {
                    $all_attachments[$att_id] = $te_id; // Track which TE it came from
                }
            }
        }

        if (empty($all_attachments)) {
            echo '<p style="color: #666; font-style: italic; margin: 0;">No attachments uploaded to time entries.</p>';
            return;
        }

        $count = count($all_attachments);

        // Header
        echo '<div class="bbab-attachments-header">';
        echo '<strong>' . $count . ' file' . ($count !== 1 ? 's' : '') . '</strong>';
        echo '</div>';

        // Attachments list
        echo '<div class="bbab-attachments-list">';

        foreach ($all_attachments as $att_id => $source_te_id) {
            $url = wp_get_attachment_url((int) $att_id);
            $filename = basename(get_attached_file((int) $att_id) ?: '');
            $mime = get_post_mime_type((int) $att_id);
            $icon = self::getFileIcon($mime ?: '');

            if (!$url) {
                continue;
            }

            echo '<div class="bbab-attachment-row">';
            echo '<a href="' . esc_url($url) . '" target="_blank" title="' . esc_attr($filename) . '">';
            echo $icon . ' <span class="attachment-filename">' . esc_html($filename) . '</span>';
            echo '</a>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Get file icon based on MIME type.
     *
     * @param string $mime_type MIME type.
     * @return string Emoji icon.
     */
    private static function getFileIcon(string $mime_type): string {
        if (strpos($mime_type, 'image/') === 0) {
            return '&#128247;'; // camera emoji
        } elseif ($mime_type === 'application/pdf') {
            return '&#128196;'; // page facing up emoji
        } elseif (strpos($mime_type, 'word') !== false || strpos($mime_type, 'document') !== false) {
            return '&#128196;';
        } elseif (strpos($mime_type, 'sheet') !== false || strpos($mime_type, 'excel') !== false) {
            return '&#128202;'; // bar chart emoji
        } elseif (strpos($mime_type, 'zip') !== false || strpos($mime_type, 'compressed') !== false) {
            return '&#128230;'; // package emoji
        }
        return '&#128196;';
    }

    /**
     * Render metabox styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'service_request') {
            return;
        }

        echo '<style>
            /* Time Entries Metabox */
            #bbab_sr_time_entries .inside {
                margin: 0;
                padding: 12px;
            }

            .bbab-time-entries-summary {
                padding: 12px;
                background: #f0f6fc;
                border-left: 4px solid #0073aa;
                margin-bottom: 16px;
                font-size: 14px;
            }

            .bbab-time-entries-list {
                margin-bottom: 16px;
                max-height: 300px;
                overflow-y: auto;
            }

            .bbab-time-entry-row {
                padding: 12px;
                border-bottom: 1px solid #ddd;
                background: #fafafa;
                margin-bottom: 8px;
                border-radius: 4px;
            }

            .bbab-time-entry-row:hover {
                background: #f0f0f0;
            }

            .entry-date {
                font-size: 12px;
                color: #666;
                margin-bottom: 4px;
            }

            .entry-description {
                font-weight: 500;
                color: #23282d;
                margin-bottom: 6px;
            }

            .entry-meta {
                font-size: 13px;
                color: #666;
            }

            .entry-meta a {
                color: #0073aa;
                text-decoration: none;
            }

            .entry-meta a:hover {
                text-decoration: underline;
            }

            .non-billable-badge {
                background: #d5f5e3;
                color: #1e8449;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 500;
            }

            .bbab-time-entries-footer {
                padding-top: 8px;
            }

            /* Attachments Metabox */
            #bbab_sr_attachments .inside {
                margin: 0;
                padding: 12px;
            }

            .bbab-attachments-header {
                padding: 10px 12px;
                background: #0047AB;
                color: white;
                margin: -12px -12px 12px -12px;
                font-size: 13px;
            }

            .bbab-attachments-list {
                max-height: 250px;
                overflow-y: auto;
            }

            .bbab-attachment-row {
                padding: 8px 0;
                border-bottom: 1px solid #eee;
                font-size: 13px;
            }

            .bbab-attachment-row:last-child {
                border-bottom: none;
            }

            .bbab-attachment-row a {
                color: #1e40af;
                text-decoration: none;
                display: flex;
                align-items: center;
                gap: 6px;
            }

            .bbab-attachment-row a:hover {
                text-decoration: underline;
            }

            .attachment-filename {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                max-width: 200px;
                display: inline-block;
            }
        </style>';
    }
}
