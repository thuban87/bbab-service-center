<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Adds reference number columns and metaboxes for Projects and Milestones.
 *
 * Supplements snippet 1492's columns with the reference number display.
 */
class ProjectMilestoneRefColumns {

    /**
     * Register hooks.
     *
     * NOTE: Column registration removed - now handled by ProjectColumns.php
     * and MilestoneColumns.php. This class only handles reference metaboxes.
     */
    public static function register(): void {
        // Metaboxes only - columns now handled by ProjectColumns/MilestoneColumns
        add_action('add_meta_boxes', [self::class, 'registerMetaboxes']);

        // Styles for metaboxes
        add_action('admin_head', [self::class, 'renderMetaboxStyles']);

        Logger::debug('ProjectMilestoneRefColumns', 'Registered reference metabox hooks');
    }

    /**
     * Add reference column to projects (inserted after checkbox).
     */
    public static function addProjectRefColumn(array $columns): array {
        $new_columns = [];
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            if ($key === 'cb') {
                $new_columns['reference'] = 'Ref';
            }
        }
        return $new_columns;
    }

    /**
     * Render project reference column.
     */
    public static function renderProjectRefColumn(string $column, int $post_id): void {
        if ($column !== 'reference') {
            return;
        }

        $ref = get_post_meta($post_id, 'reference_number', true);
        if ($ref) {
            echo '<span class="pm-reference">' . esc_html($ref) . '</span>';
        } else {
            echo '<span class="pm-no-ref">—</span>';
        }
    }

    /**
     * Add reference column to milestones (inserted after checkbox).
     */
    public static function addMilestoneRefColumn(array $columns): array {
        $new_columns = [];
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            if ($key === 'cb') {
                $new_columns['reference'] = 'Ref';
            }
        }
        return $new_columns;
    }

    /**
     * Render milestone reference column.
     */
    public static function renderMilestoneRefColumn(string $column, int $post_id): void {
        if ($column !== 'reference') {
            return;
        }

        $ref = get_post_meta($post_id, 'reference_number', true);
        if ($ref) {
            echo '<span class="pm-reference">' . esc_html($ref) . '</span>';
        } else {
            echo '<span class="pm-no-ref">—</span>';
        }
    }

    /**
     * Register reference metaboxes.
     */
    public static function registerMetaboxes(): void {
        add_meta_box(
            'bbab_project_reference',
            'Reference Number',
            [self::class, 'renderProjectMetabox'],
            'project',
            'side',
            'high'
        );

        add_meta_box(
            'bbab_milestone_reference',
            'Reference Number',
            [self::class, 'renderMilestoneMetabox'],
            'milestone',
            'side',
            'high'
        );
    }

    /**
     * Render project reference metabox.
     */
    public static function renderProjectMetabox(\WP_Post $post): void {
        $ref = get_post_meta($post->ID, 'reference_number', true);

        if ($ref) {
            echo '<div class="pm-ref-display">';
            echo '<span class="pm-ref-value">' . esc_html($ref) . '</span>';
            echo '</div>';
        } else {
            echo '<div class="pm-ref-pending">';
            echo '<em>Will be assigned on save</em>';
            echo '</div>';
        }
    }

    /**
     * Render milestone reference metabox.
     */
    public static function renderMilestoneMetabox(\WP_Post $post): void {
        $ref = get_post_meta($post->ID, 'reference_number', true);

        if ($ref) {
            echo '<div class="pm-ref-display">';
            echo '<span class="pm-ref-value">' . esc_html($ref) . '</span>';
            echo '</div>';

            // Show parent project ref for context
            $project_id = get_post_meta($post->ID, 'related_project', true);
            if (is_array($project_id)) {
                $project_id = reset($project_id);
            }
            if ($project_id) {
                $project_ref = get_post_meta($project_id, 'reference_number', true);
                $project_name = get_the_title($project_id);
                echo '<div class="pm-ref-context">';
                echo 'Project: <a href="' . esc_url(get_edit_post_link($project_id)) . '">';
                echo esc_html($project_ref ?: $project_name);
                echo '</a>';
                echo '</div>';
            }
        } else {
            echo '<div class="pm-ref-pending">';
            echo '<em>Will be assigned on save</em>';
            echo '<p class="description">Requires: related project with reference number + milestone order</p>';
            echo '</div>';
        }
    }

    /**
     * Render metabox styles.
     */
    public static function renderMetaboxStyles(): void {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, ['project', 'milestone'], true)) {
            return;
        }

        // Metabox-only styles (column styles now in ProjectColumns/MilestoneColumns)
        echo '<style>
            .pm-ref-display {
                text-align: center;
                padding: 10px;
            }
            .pm-ref-value {
                font-family: monospace;
                font-size: 18px;
                font-weight: 600;
                color: #467FF7;
            }
            .pm-ref-pending {
                text-align: center;
                padding: 10px;
                color: #666;
            }
            .pm-ref-context {
                margin-top: 10px;
                font-size: 12px;
                color: #666;
                text-align: center;
            }
        </style>';
    }
}
