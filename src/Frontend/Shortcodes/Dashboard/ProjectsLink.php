<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;

/**
 * Dashboard Projects Hub Link shortcode.
 *
 * Renders a styled button linking to the projects archive.
 *
 * Usage: [dashboard_projects_link]
 *
 * Migrated from: WPCode Snippet #1679
 */
class ProjectsLink extends BaseShortcode {

    protected string $tag = 'dashboard_projects_link';
    protected bool $requires_org = false;
    protected bool $requires_login = true;

    /**
     * Render the shortcode output.
     *
     * @param array $atts   Shortcode attributes.
     * @param int   $org_id Organization ID (unused).
     * @return string HTML output.
     */
    protected function output(array $atts, int $org_id): string {
        ob_start();
        ?>
        <div class="dashboard-projects-link">
            <a href="/projects/" class="projects-hub-btn">
                <span class="icon"><?php echo "\xF0\x9F\x93\x81"; ?></span> View All Projects
            </a>
        </div>
        <style>
            .dashboard-projects-link {
                margin: 24px 0;
                text-align: center;
            }
            .projects-hub-btn {
                display: inline-block;
                font-family: 'Poppins', sans-serif;
                font-size: 16px;
                font-weight: 500;
                color: white;
                background: #467FF7;
                padding: 12px 24px;
                border-radius: 8px;
                text-decoration: none;
                transition: background 0.2s;
            }
            .projects-hub-btn:hover {
                background: #3366cc;
                color: white;
            }
            .projects-hub-btn .icon {
                margin-right: 6px;
            }
        </style>
        <?php
        return ob_get_clean();
    }
}
