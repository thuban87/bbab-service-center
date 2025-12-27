<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Milestones;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Modules\Projects\MilestoneService;

/**
 * Milestone Work Status Badge shortcode.
 *
 * Renders a styled work status badge with icon for the current milestone.
 * Works in Elementor Loop Grid context.
 *
 * Usage: [milestone_status]
 *
 * Migrated from: WPCode Snippet #1629
 */
class WorkBadge extends BaseShortcode {

    protected string $tag = 'milestone_status';
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

        $status = MilestoneService::getWorkStatus($post_id);

        if (empty($status)) {
            return '';
        }

        $badge_config = [
            'Planned' => ['class' => 'milestone-planned', 'icon' => "\xF0\x9F\x93\x8B"], // clipboard
            'In Progress' => ['class' => 'milestone-inprogress', 'icon' => "\xF0\x9F\x94\xA7"], // wrench
            'On Hold' => ['class' => 'milestone-onhold', 'icon' => "\xE2\x8F\xB8\xEF\xB8\x8F"], // pause
            'Waiting for Client' => ['class' => 'milestone-waiting', 'icon' => "\xE2\x8F\xB3"], // hourglass
            'Completed' => ['class' => 'milestone-completed', 'icon' => "\xE2\x9C\x85"], // check
        ];

        $config = $badge_config[$status] ?? ['class' => 'milestone-default', 'icon' => ''];

        ob_start();
        ?>
        <span class="milestone-status-badge <?php echo esc_attr($config['class']); ?>">
            <?php echo $config['icon']; ?> <?php echo esc_html($status); ?>
        </span>
        <style>
            .milestone-status-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-family: 'Poppins', sans-serif;
                font-size: 12px;
                font-weight: 500;
            }
            .milestone-planned { background: #e8eaf6; color: #5c6bc0; }
            .milestone-inprogress { background: #e3f2fd; color: #1976d2; }
            .milestone-onhold { background: #fef9e7; color: #b7950b; }
            .milestone-waiting { background: #fff3e0; color: #f57c00; }
            .milestone-completed { background: #d5f5e3; color: #1e8449; }
        </style>
        <?php
        return ob_get_clean();
    }
}
