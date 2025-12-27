<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Milestones;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Modules\Projects\MilestoneService;

/**
 * Back to Project Link shortcode.
 *
 * Renders a navigation link back to the parent project from a milestone single page.
 *
 * Usage: [back_to_project_link]
 *
 * Migrated from: WPCode Snippet #1641
 */
class BackToProject extends BaseShortcode {

    protected string $tag = 'back_to_project_link';
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
        $milestone_id = get_the_ID();

        if (!$milestone_id) {
            return '';
        }

        // Get parent project
        $project = MilestoneService::getProject($milestone_id);

        if (!$project) {
            // Fallback to history.back()
            return '<a href="javascript:history.back()" class="back-to-project-link">&larr; Back</a>' . $this->getStyles();
        }

        $project_url = get_permalink($project->ID);
        $project_name = get_the_title($project->ID);

        // Sanity check - don't link to self
        $current_url = get_permalink($milestone_id);
        if ($project_url === $current_url) {
            return '<a href="javascript:history.back()" class="back-to-project-link">&larr; Back</a>' . $this->getStyles();
        }

        ob_start();
        ?>
        <a href="<?php echo esc_url($project_url); ?>" class="back-to-project-link">
            &larr; Back to <?php echo esc_html($project_name); ?>
        </a>
        <?php echo $this->getStyles(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Get component styles.
     *
     * @return string CSS styles.
     */
    private function getStyles(): string {
        return '<style>
            .back-to-project-link {
                display: inline-block;
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                color: #467FF7;
                text-decoration: none;
                margin-bottom: 20px;
            }
            .back-to-project-link:hover {
                text-decoration: underline;
            }
        </style>';
    }
}
