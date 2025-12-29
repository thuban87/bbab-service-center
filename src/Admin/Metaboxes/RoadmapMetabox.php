<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Metaboxes;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Roadmap Item editor metaboxes.
 *
 * Displays on roadmap_item edit screens:
 * - ADR Document (sidebar) - Shows attached ADR PDF with download link
 *
 * Phase 7 - Staging fixes
 */
class RoadmapMetabox {

    /**
     * Register hooks.
     */
    public static function register(): void {
        add_action('add_meta_boxes', [self::class, 'registerMetaboxes']);
        add_action('add_meta_boxes', [self::class, 'movePodsMetaboxToNormal'], 999);
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('RoadmapMetabox', 'Registered roadmap metabox hooks');
    }

    /**
     * Move Pods metabox from side to normal position.
     *
     * Phase 8.3 fix - Properly move the Pods "More Fields" metabox.
     */
    public static function movePodsMetaboxToNormal(): void {
        global $wp_meta_boxes;

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'roadmap_item') {
            return;
        }

        $post_type = 'roadmap_item';

        // Check if the metaboxes array exists for this post type
        if (!isset($wp_meta_boxes[$post_type])) {
            return;
        }

        // Look for Pods metaboxes in the side column
        $contexts_to_check = ['side'];
        $priorities_to_check = ['high', 'core', 'default', 'low'];

        foreach ($contexts_to_check as $context) {
            foreach ($priorities_to_check as $priority) {
                if (!isset($wp_meta_boxes[$post_type][$context][$priority])) {
                    continue;
                }

                foreach ($wp_meta_boxes[$post_type][$context][$priority] as $id => $metabox) {
                    // Check if this is a Pods metabox (starts with 'pods-meta-')
                    if (strpos($id, 'pods-meta-') === 0) {
                        // Remove from side
                        unset($wp_meta_boxes[$post_type][$context][$priority][$id]);

                        // Add to normal with high priority
                        if (!isset($wp_meta_boxes[$post_type]['normal']['high'])) {
                            $wp_meta_boxes[$post_type]['normal']['high'] = [];
                        }

                        // Add at the beginning of normal/high
                        $wp_meta_boxes[$post_type]['normal']['high'] =
                            [$id => $metabox] + $wp_meta_boxes[$post_type]['normal']['high'];

                        Logger::debug('RoadmapMetabox', 'Moved Pods metabox to normal position', ['id' => $id]);
                    }
                }
            }
        }
    }


    /**
     * Register the metaboxes.
     */
    public static function registerMetaboxes(): void {
        // ADR Document (sidebar)
        add_meta_box(
            'bbab_roadmap_adr_document',
            'ADR Document',
            [self::class, 'renderADRMetabox'],
            'roadmap_item',
            'side',
            'default'
        );
    }

    /**
     * Render ADR document metabox.
     *
     * Shows the attached ADR PDF with view/download options.
     *
     * @param \WP_Post $post The post object.
     */
    public static function renderADRMetabox(\WP_Post $post): void {
        $post_id = $post->ID;

        // Get ADR PDF - can be stored as attachment ID or array from Pods
        $adr_data = null;
        $adr_url = null;
        $adr_filename = null;

        // Try Pods first
        if (function_exists('pods')) {
            $pod = pods('roadmap_item', $post_id);
            if ($pod) {
                $adr = $pod->field('adr_pdf');
                if ($adr && !empty($adr['guid'])) {
                    $adr_url = $adr['guid'];
                    $adr_filename = $adr['post_title'] ?? basename($adr_url);
                    $adr_data = $adr;
                } elseif ($adr && !empty($adr['ID'])) {
                    $adr_url = wp_get_attachment_url($adr['ID']);
                    $adr_filename = get_the_title($adr['ID']) ?: basename($adr_url);
                }
            }
        }

        // Fallback to raw meta
        if (!$adr_url) {
            $adr_id = get_post_meta($post_id, 'adr_pdf', true);
            if ($adr_id) {
                $adr_url = wp_get_attachment_url($adr_id);
                if ($adr_url) {
                    $adr_filename = get_the_title($adr_id) ?: basename($adr_url);
                }
            }
        }

        // Get roadmap status for context
        $status = '';
        if (function_exists('pods')) {
            $pod = pods('roadmap_item', $post_id);
            $status = $pod ? $pod->field('roadmap_status') : '';
        }
        if (!$status) {
            $status = get_post_meta($post_id, 'roadmap_status', true);
        }

        // Render metabox content
        if ($adr_url) {
            // Has ADR attached
            echo '<div class="bbab-adr-container">';

            // File info
            echo '<div class="bbab-adr-file-info">';
            echo '<span class="dashicons dashicons-pdf" style="color: #dc2626; margin-right: 5px;"></span>';
            echo '<span class="bbab-adr-filename">' . esc_html($adr_filename) . '</span>';
            echo '</div>';

            // Action buttons
            echo '<div class="bbab-adr-actions">';
            echo '<a href="' . esc_url($adr_url) . '" target="_blank" class="button button-primary">';
            echo '<span class="dashicons dashicons-visibility" style="margin-top: 4px;"></span> View PDF';
            echo '</a>';
            echo '<a href="' . esc_url($adr_url) . '" download class="button">';
            echo '<span class="dashicons dashicons-download" style="margin-top: 4px;"></span> Download';
            echo '</a>';
            echo '</div>';

            echo '</div>';
        } else {
            // No ADR attached
            echo '<div class="bbab-adr-empty">';

            if ($status === 'Idea') {
                echo '<p><span class="dashicons dashicons-info-outline" style="color: #2271b1;"></span> No ADR document yet.</p>';
                echo '<p class="description">Use the "Start ADR" row action from the roadmap list to begin the ADR process.</p>';
            } elseif ($status === 'ADR In Progress') {
                echo '<p><span class="dashicons dashicons-clock" style="color: #dba617;"></span> ADR is in progress.</p>';
                echo '<p class="description">Use the ADR PDF field above to attach the document when ready.</p>';
            } else {
                echo '<p><span class="dashicons dashicons-media-document" style="color: #666;"></span> No ADR document attached.</p>';
                echo '<p class="description">Use the ADR PDF field above to attach a document.</p>';
            }

            echo '</div>';
        }
    }

    /**
     * Render admin styles for metaboxes.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'roadmap_item') {
            return;
        }

        echo '<style>
            /* ADR Container */
            #bbab_roadmap_adr_document .inside { margin: 0; padding: 12px; }

            .bbab-adr-container {
                background: #f9fafb;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                padding: 12px;
            }

            .bbab-adr-file-info {
                display: flex;
                align-items: center;
                margin-bottom: 12px;
                padding-bottom: 12px;
                border-bottom: 1px solid #e5e7eb;
            }

            .bbab-adr-filename {
                font-weight: 500;
                word-break: break-word;
            }

            .bbab-adr-actions {
                display: flex;
                gap: 8px;
            }

            .bbab-adr-actions .button {
                flex: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 4px;
            }

            .bbab-adr-actions .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }

            /* Empty state */
            .bbab-adr-empty {
                text-align: center;
                padding: 10px;
            }

            .bbab-adr-empty p {
                margin: 0 0 8px 0;
            }

            .bbab-adr-empty .description {
                color: #666;
                font-size: 12px;
            }

            .bbab-adr-empty .dashicons {
                vertical-align: middle;
                margin-right: 4px;
            }
        </style>';
    }
}
