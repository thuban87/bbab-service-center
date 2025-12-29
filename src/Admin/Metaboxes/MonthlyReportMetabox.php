<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Metaboxes;

use BBAB\ServiceCenter\Modules\Billing\MonthlyReportService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Monthly Report editor metaboxes.
 *
 * Displays on monthly_report edit screens:
 * - Time Entries for this report period (sidebar)
 *
 * Migrated from: Phase 7.6 (new functionality)
 */
class MonthlyReportMetabox {

    /**
     * Register hooks.
     */
    public static function register(): void {
        add_action('add_meta_boxes', [self::class, 'registerMetaboxes']);
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('MonthlyReportMetabox', 'Registered monthly report metabox hooks');
    }

    /**
     * Register the metaboxes.
     */
    public static function registerMetaboxes(): void {
        // Time Entries (sidebar)
        add_meta_box(
            'bbab_monthly_report_time_entries',
            'Time Entries',
            [self::class, 'renderTimeEntriesMetabox'],
            'monthly_report',
            'side',
            'default'
        );

        // Report PDF (sidebar)
        add_meta_box(
            'bbab_monthly_report_pdf',
            'Report PDF',
            [self::class, 'renderPDFMetabox'],
            'monthly_report',
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
        $report_id = $post->ID;

        // Get time entries using MonthlyReportService (queries by date range)
        $time_entries = MonthlyReportService::getTimeEntries($report_id);

        // Sort by entry_date descending (most recent first)
        usort($time_entries, function($a, $b) {
            $date_a = get_post_meta($a->ID, 'entry_date', true);
            $date_b = get_post_meta($b->ID, 'entry_date', true);
            return strtotime($date_b) - strtotime($date_a);
        });

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
        echo '<strong>' . $count . ' entr' . ($count === 1 ? 'y' : 'ies') . '</strong> &bull; ' . number_format($total_hours, 2) . ' hrs total';
        if ($billable_hours !== $total_hours) {
            echo '<br><span style="color: #059669; font-weight: 600;">' . number_format($billable_hours, 2) . ' billable hrs</span>';
            echo ' <span style="color: #666;">(' . number_format($total_hours - $billable_hours, 2) . ' no charge)</span>';
        }
        echo '</div>';

        // Time entries group
        echo '<div class="bbab-te-group">';
        echo '<div class="bbab-group-header-green">';
        echo '<span>Time Entries</span>';
        echo '<span>' . $count . '</span>';
        echo '</div>';

        if ($count > 0) {
            echo '<div class="bbab-scroll-container" style="max-height: 350px;">';
            foreach ($time_entries as $te) {
                self::renderTeRow($te);
            }
            echo '</div>';
        } else {
            echo '<div class="bbab-empty-state">No time entries for this report period.</div>';
        }

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

        // Get related SR
        $sr_id = get_post_meta($te_id, 'related_service_request', true);
        $sr_ref = '';
        if ($sr_id) {
            $sr_ref = get_post_meta($sr_id, 'reference_number', true);
        }

        $formatted_date = $date ? date('M j', strtotime($date)) : '-';
        $billable_badge = ($billable === '0' || $billable === 0 || $billable === false)
            ? ' <span style="background: #d5f5e3; color: #1e8449; padding: 1px 5px; border-radius: 3px; font-size: 10px;">NC</span>'
            : '';

        // Display: TE-XXXX - Title (or just TE-XXXX if no title)
        $display_text = $te_ref ?: 'TE';
        if (!empty($title) && $title !== 'Auto Draft' && $title !== $te_ref) {
            $display_text = $te_ref . ' - ' . wp_trim_words($title, 6, '...');
        }

        echo '<div class="bbab-te-row">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center;">';
        echo '<span style="color: #666;">' . esc_html($formatted_date) . '</span>';
        echo '<span>' . number_format($hours, 2) . ' hrs' . $billable_badge . '</span>';
        echo '</div>';
        echo '<div style="margin-top: 3px;">';
        echo '<a href="' . esc_url($edit_link) . '">' . esc_html($display_text) . '</a>';
        if ($sr_ref) {
            echo ' <span style="color: #888; font-size: 10px;">(' . esc_html($sr_ref) . ')</span>';
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render PDF metabox.
     *
     * Shows the attached report PDF with view/download options.
     *
     * @param \WP_Post $post The post object.
     */
    public static function renderPDFMetabox(\WP_Post $post): void {
        $post_id = $post->ID;

        // Get report PDF - can be stored in various formats
        $pdf_url = null;
        $pdf_filename = null;

        // Debug: Log what we're getting (error_log bypasses debug mode)
        error_log('[BBAB-SC] MonthlyReportMetabox checking PDF for post_id: ' . $post_id);

        // Debug: List all meta keys to find the correct field name
        $all_meta = get_post_meta($post_id);
        $meta_keys = array_keys($all_meta);
        error_log('[BBAB-SC] All meta keys for post ' . $post_id . ': ' . implode(', ', $meta_keys));

        // Check specifically for pdf-related keys
        foreach ($meta_keys as $key) {
            if (stripos($key, 'pdf') !== false || stripos($key, 'report') !== false || stripos($key, 'file') !== false || stripos($key, 'attachment') !== false) {
                error_log('[BBAB-SC] Found relevant meta key: ' . $key . ' = ' . print_r(get_post_meta($post_id, $key, true), true));
            }
        }

        Logger::debug('MonthlyReportMetabox', 'Checking PDF for report', ['post_id' => $post_id]);

        // Try Pods first (handles most modern attachments)
        if (function_exists('pods')) {
            $pod = pods('monthly_report', $post_id);
            if ($pod) {
                $pdf = $pod->field('site_health_pdf');
                error_log('[BBAB-SC] Pods field report_pdf returned: ' . print_r($pdf, true));
                Logger::debug('MonthlyReportMetabox', 'Pods field result', [
                    'pdf_type' => gettype($pdf),
                    'pdf_value' => is_array($pdf) ? $pdf : (string) $pdf,
                ]);

                // Pods can return: array with guid, array with ID, just an ID, or URL string
                if (is_array($pdf)) {
                    if (!empty($pdf['guid'])) {
                        $pdf_url = $pdf['guid'];
                        $pdf_filename = $pdf['post_title'] ?? basename($pdf_url);
                    } elseif (!empty($pdf['ID'])) {
                        $pdf_url = wp_get_attachment_url($pdf['ID']);
                        $pdf_filename = get_the_title($pdf['ID']) ?: basename($pdf_url);
                    }
                } elseif (is_numeric($pdf)) {
                    // Just an attachment ID
                    $pdf_url = wp_get_attachment_url((int) $pdf);
                    if ($pdf_url) {
                        $pdf_filename = get_the_title((int) $pdf) ?: basename($pdf_url);
                    }
                } elseif (is_string($pdf) && !empty($pdf)) {
                    // Might be a URL string (old format) or an ID as string
                    if (filter_var($pdf, FILTER_VALIDATE_URL)) {
                        $pdf_url = $pdf;
                        $pdf_filename = basename($pdf_url);
                    } elseif (is_numeric($pdf)) {
                        $pdf_url = wp_get_attachment_url((int) $pdf);
                        if ($pdf_url) {
                            $pdf_filename = get_the_title((int) $pdf) ?: basename($pdf_url);
                        }
                    }
                }
            }
        }

        // Fallback to raw meta - handle multiple storage formats
        if (!$pdf_url) {
            $pdf_meta = get_post_meta($post_id, 'report_pdf', true);

            if (!empty($pdf_meta)) {
                if (is_numeric($pdf_meta)) {
                    // Attachment ID
                    $pdf_url = wp_get_attachment_url((int) $pdf_meta);
                    if ($pdf_url) {
                        $pdf_filename = get_the_title((int) $pdf_meta) ?: basename($pdf_url);
                    }
                } elseif (is_string($pdf_meta) && filter_var($pdf_meta, FILTER_VALIDATE_URL)) {
                    // Direct URL
                    $pdf_url = $pdf_meta;
                    $pdf_filename = basename($pdf_url);
                } elseif (is_array($pdf_meta)) {
                    // Serialized array (rare but possible)
                    if (!empty($pdf_meta['guid'])) {
                        $pdf_url = $pdf_meta['guid'];
                        $pdf_filename = $pdf_meta['post_title'] ?? basename($pdf_url);
                    } elseif (!empty($pdf_meta['ID'])) {
                        $pdf_url = wp_get_attachment_url($pdf_meta['ID']);
                        $pdf_filename = get_the_title($pdf_meta['ID']) ?: basename($pdf_url);
                    } elseif (!empty($pdf_meta['url'])) {
                        $pdf_url = $pdf_meta['url'];
                        $pdf_filename = basename($pdf_url);
                    }
                }
            }
        }

        // Render metabox content
        if ($pdf_url) {
            // Has PDF attached
            echo '<div class="bbab-pdf-container">';

            // File info
            echo '<div class="bbab-pdf-file-info">';
            echo '<span class="dashicons dashicons-pdf" style="color: #dc2626; margin-right: 5px;"></span>';
            echo '<span class="bbab-pdf-filename">' . esc_html($pdf_filename) . '</span>';
            echo '</div>';

            // Action buttons
            echo '<div class="bbab-pdf-actions">';
            echo '<a href="' . esc_url($pdf_url) . '" target="_blank" class="button button-primary">';
            echo '<span class="dashicons dashicons-visibility" style="margin-top: 4px;"></span> View';
            echo '</a>';
            echo '<a href="' . esc_url($pdf_url) . '" download class="button">';
            echo '<span class="dashicons dashicons-download" style="margin-top: 4px;"></span> Download';
            echo '</a>';
            echo '</div>';

            echo '</div>';
        } else {
            // No PDF attached
            echo '<div class="bbab-pdf-empty">';
            echo '<p><span class="dashicons dashicons-media-document" style="color: #666;"></span> No PDF attached.</p>';
            echo '<p class="description">Use the Report PDF field above to attach a document.</p>';
            echo '</div>';
        }
    }

    /**
     * Render admin styles for metaboxes.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'monthly_report') {
            return;
        }

        echo '<style>
            /* Sidebar Metaboxes */
            #bbab_monthly_report_time_entries .inside { margin: 0; padding: 0; }
            #bbab_monthly_report_pdf .inside { margin: 0; padding: 12px; }

            /* Group Headers */
            .bbab-group-header-green {
                background: #059669; color: white; padding: 8px 10px;
                font-weight: 600; font-size: 12px;
                display: flex; justify-content: space-between; align-items: center;
            }

            /* Rows */
            .bbab-te-row {
                padding: 8px 10px; border-bottom: 1px solid #eee;
                font-size: 12px; background: white;
            }
            .bbab-te-row a { text-decoration: none; color: #1e40af; }
            .bbab-te-row a:hover { text-decoration: underline; }

            /* Empty State */
            .bbab-empty-state {
                padding: 10px; color: #666; font-style: italic;
                font-size: 12px; background: #fafafa;
            }

            /* Summary */
            .bbab-summary-bar {
                background: #f0f6fc; border-left: 4px solid #2271b1;
                padding: 10px 12px; margin: 12px; font-size: 13px;
            }

            /* Scroll Container */
            .bbab-scroll-container { max-height: 400px; overflow-y: auto; }

            /* PDF Metabox */
            .bbab-pdf-container {
                background: #f9fafb;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                padding: 12px;
            }
            .bbab-pdf-file-info {
                display: flex;
                align-items: center;
                margin-bottom: 12px;
                padding-bottom: 12px;
                border-bottom: 1px solid #e5e7eb;
            }
            .bbab-pdf-filename {
                font-weight: 500;
                word-break: break-word;
            }
            .bbab-pdf-actions {
                display: flex;
                gap: 8px;
            }
            .bbab-pdf-actions .button {
                flex: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 4px;
            }
            .bbab-pdf-actions .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            .bbab-pdf-empty {
                text-align: center;
                padding: 10px;
            }
            .bbab-pdf-empty p { margin: 0 0 8px 0; }
            .bbab-pdf-empty .description { color: #666; font-size: 12px; }
            .bbab-pdf-empty .dashicons { vertical-align: middle; margin-right: 4px; }
        </style>';
    }
}
