<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Projects;

use BBAB\ServiceCenter\Utils\UserContext;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Project Archive Filter.
 *
 * Filters the project archive query by:
 * - Organization (clients only see their own)
 * - Show/hide completed projects
 * - Sort options (status, date, name, target)
 *
 * Migrated from: WPCode Snippet #1517
 */
class ArchiveFilter {

    /**
     * Register the archive filter hook.
     */
    public static function register(): void {
        add_action('pre_get_posts', [self::class, 'filterArchive']);
        Logger::debug('ArchiveFilter', 'Registered project archive filter hook');
    }

    /**
     * Filter the project archive query.
     *
     * @param \WP_Query $query The query object.
     */
    public static function filterArchive(\WP_Query $query): void {
        // Only frontend, main query, project archive
        if (is_admin() || !$query->is_main_query() || !is_post_type_archive('project')) {
            return;
        }

        // Require login
        if (!is_user_logged_in()) {
            $query->set('post__in', [0]); // Return nothing
            return;
        }

        // Get user's organization (simulation-aware)
        $org_id = UserContext::getCurrentOrgId();

        // Non-admins without an org see nothing
        if (!current_user_can('administrator') && empty($org_id)) {
            $query->set('post__in', [0]);
            return;
        }

        // Build meta query
        $meta_query = ['relation' => 'AND'];

        // Organization filter (non-admins only see their org)
        if (!current_user_can('administrator')) {
            $meta_query['org_clause'] = [
                'key' => 'organization',
                'value' => $org_id,
                'compare' => '=',
            ];
        }

        // Status clause for sorting
        $meta_query['status_clause'] = [
            'key' => 'project_status',
            'compare' => 'EXISTS',
        ];

        // Show/hide completed projects
        $show_completed = isset($_GET['show_completed']) && $_GET['show_completed'] === '1';
        if (!$show_completed) {
            $meta_query[] = [
                'key' => 'project_status',
                'value' => 'Completed',
                'compare' => '!=',
            ];
        }

        $query->set('meta_query', $meta_query);

        // Handle sorting
        $sort = isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : 'status';

        switch ($sort) {
            case 'date':
                $query->set('orderby', 'date');
                $query->set('order', 'DESC');
                break;

            case 'date_asc':
                $query->set('orderby', 'date');
                $query->set('order', 'ASC');
                break;

            case 'name':
                $query->set('orderby', 'title');
                $query->set('order', 'ASC');
                break;

            case 'target':
                // Sort by target_completion date
                $meta_query['target_clause'] = [
                    'key' => 'target_completion',
                    'compare' => 'EXISTS',
                ];
                $query->set('meta_query', $meta_query);
                $query->set('orderby', ['target_clause' => 'ASC', 'date' => 'DESC']);
                break;

            case 'status':
            default:
                // Sort by status then date
                $query->set('orderby', ['status_clause' => 'ASC', 'date' => 'DESC']);
                break;
        }
    }
}
