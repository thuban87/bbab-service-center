<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Projects;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Modules\Projects\ProjectService;
use BBAB\ServiceCenter\Modules\Projects\MilestoneService;

/**
 * Project Milestone Progress shortcode.
 *
 * Renders a mini progress bar showing completed vs total milestones.
 * Works in Elementor Loop Grid context.
 *
 * Usage: [project_milestone_progress]
 *
 * Migrated from: WPCode Snippet #1534
 */
class MilestoneProgress extends BaseShortcode {

    protected string $tag = 'project_milestone_progress';
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
            return '<em>No project context</em>';
        }

        // Get milestones for this project
        $milestones = ProjectService::getMilestones($post_id);

        if (empty($milestones)) {
            return '<span class="no-milestones">Single payment project</span>';
        }

        $total = count($milestones);
        $complete = 0;

        // Count paid milestones
        foreach ($milestones as $milestone) {
            if (MilestoneService::isPaid($milestone->ID)) {
                $complete++;
            }
        }

        $percentage = $total > 0 ? round(($complete / $total) * 100) : 0;

        ob_start();
        ?>
        <div class="milestone-mini-progress">
            <div class="progress-text"><?php echo $complete; ?> of <?php echo $total; ?> milestones complete</div>
            <div class="mini-progress-bar">
                <div class="mini-progress-fill" style="width: <?php echo $percentage; ?>%"></div>
            </div>
        </div>
        <style>
            .milestone-mini-progress {
                margin-top: 8px;
            }
            .progress-text {
                font-family: 'Poppins', sans-serif;
                font-size: 12px;
                color: #7f8c8d;
                margin-bottom: 6px;
            }
            .mini-progress-bar {
                height: 6px;
                background: #e0e0e0;
                border-radius: 3px;
                overflow: hidden;
            }
            .mini-progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #467FF7, #6fa3ff);
                border-radius: 3px;
                transition: width 0.3s ease;
            }
            .no-milestones {
                font-family: 'Poppins', sans-serif;
                font-size: 12px;
                color: #7f8c8d;
                font-style: italic;
            }
        </style>
        <?php
        return ob_get_clean();
    }
}
