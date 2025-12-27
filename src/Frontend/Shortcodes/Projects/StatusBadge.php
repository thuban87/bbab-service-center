<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Projects;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Modules\Projects\ProjectService;

/**
 * Project Status Badge shortcode.
 *
 * Renders a styled status badge for the current project.
 * Works in Elementor Loop Grid context.
 *
 * Usage: [project_status_badge]
 *
 * Migrated from: WPCode Snippet #1533
 */
class StatusBadge extends BaseShortcode {

    protected string $tag = 'project_status_badge';
    protected bool $requires_org = false;
    protected bool $requires_login = false;

    /**
     * Render the shortcode output.
     *
     * @param array $atts   Shortcode attributes.
     * @param int   $org_id Organization ID (unused - uses current post context).
     * @return string HTML output.
     */
    protected function output(array $atts, int $org_id): string {
        $post_id = get_the_ID();

        // Fallback for Elementor loop context
        if (!$post_id) {
            global $post;
            $post_id = $post ? $post->ID : 0;
        }

        if (!$post_id) {
            return '';
        }

        $status = get_post_meta($post_id, 'project_status', true);

        $badge_classes = [
            'Active' => 'status-active',
            'Waiting on Client' => 'status-waiting',
            'On Hold' => 'status-hold',
            'Completed' => 'status-complete',
            'Cancelled' => 'status-cancelled',
        ];

        $class = $badge_classes[$status] ?? 'status-default';

        ob_start();
        ?>
        <span class="project-status-badge <?php echo esc_attr($class); ?>">
            <?php echo esc_html($status ?: 'Active'); ?>
        </span>
        <style>
            .project-status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 12px;
                font-family: 'Poppins', sans-serif;
                font-size: 12px;
                font-weight: 500;
            }
            .status-active { background: #d5f5e3; color: #1e8449; }
            .status-waiting { background: #fef9e7; color: #b7950b; }
            .status-hold { background: #e8eaf6; color: #5c6bc0; }
            .status-complete { background: #f5f5f5; color: #616161; }
            .status-cancelled { background: #ffebee; color: #c62828; }
        </style>
        <?php
        return ob_get_clean();
    }
}
