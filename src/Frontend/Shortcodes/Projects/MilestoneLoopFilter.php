<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Projects;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Milestone Loop Grid Filter.
 *
 * Provides an Elementor Loop Grid query filter to show milestones
 * for the current project, ordered by milestone_order.
 *
 * Usage in Elementor: Set Query ID to "milestone_by_project"
 *
 * Migrated from: WPCode Snippet #1622
 */
class MilestoneLoopFilter {

    /**
     * Register the Elementor query filter hook.
     */
    public static function register(): void {
        add_action('elementor/query/milestone_by_project', [self::class, 'filterQuery']);
        Logger::debug('MilestoneLoopFilter', 'Registered Elementor milestone loop filter');
    }

    /**
     * Filter the Elementor Loop Grid query to show milestones for current project.
     *
     * @param \WP_Query $query The query object.
     */
    public static function filterQuery(\WP_Query $query): void {
        $project_id = get_the_ID();

        if (!$project_id) {
            return;
        }

        $query->set('post_type', 'milestone');
        $query->set('posts_per_page', -1);
        $query->set('meta_query', [
            [
                'key' => 'related_project',
                'value' => $project_id,
                'compare' => '=',
            ],
        ]);

        // Order by milestone_order field, fallback to ID
        $query->set('meta_key', 'milestone_order');
        $query->set('orderby', ['meta_value_num' => 'ASC', 'ID' => 'ASC']);
    }
}
