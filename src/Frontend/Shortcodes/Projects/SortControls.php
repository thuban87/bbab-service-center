<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Projects;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;

/**
 * Project Sort Controls shortcode.
 *
 * Renders sorting option buttons for the project archive.
 *
 * Usage: [project_sort_controls]
 *
 * Migrated from: WPCode Snippet #1677
 */
class SortControls extends BaseShortcode {

    protected string $tag = 'project_sort_controls';
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
        $current_sort = isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : 'status';

        $sorts = [
            'status' => 'Status',
            'date' => 'Start Date (Closest)',
            'date_asc' => 'Start Date (Furthest)',
            'name' => 'Name (A-Z)',
            'target' => 'Target Date',
        ];

        ob_start();
        ?>
        <div class="project-sort-controls">
            <span class="sort-label">Sort by:</span>
            <?php foreach ($sorts as $key => $label): ?>
                <?php
                $url = add_query_arg('sort', $key, remove_query_arg('sort'));
                $active = ($current_sort === $key) ? 'active' : '';
                ?>
                <a href="<?php echo esc_url($url); ?>" class="sort-btn <?php echo $active; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <style>
            .project-sort-controls {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 16px;
                flex-wrap: wrap;
            }
            .sort-label {
                font-family: 'Poppins', sans-serif;
                font-size: 13px;
                color: #7f8c8d;
            }
            .sort-btn {
                font-family: 'Poppins', sans-serif;
                font-size: 13px;
                padding: 4px 12px;
                border-radius: 16px;
                text-decoration: none;
                color: #324A6D;
                background: white;
                border: 1px solid #e0e0e0;
                transition: all 0.2s;
            }
            .sort-btn:hover {
                border-color: #467FF7;
                color: #467FF7;
            }
            .sort-btn.active {
                background: #467FF7;
                color: white;
                border-color: #467FF7;
            }
        </style>
        <?php
        return ob_get_clean();
    }
}
