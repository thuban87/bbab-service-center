<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\ServiceRequests;

use BBAB\ServiceCenter\Modules\TimeTracking\TimeEntryService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Service Request business logic service.
 *
 * Handles:
 * - Status management and badge rendering
 * - Hours aggregation (delegates to TimeEntryService)
 * - Organization-scoped queries
 *
 * Migrated from: WPCode Snippets #1715 (partial), #1716 (partial)
 */
class ServiceRequestService {

    /**
     * Valid status values.
     */
    public const STATUSES = [
        'New',
        'Acknowledged',
        'In Progress',
        'Waiting on Client',
        'On Hold',
        'Completed',
        'Cancelled',
    ];

    /**
     * Status CSS classes (kebab-case).
     */
    public const STATUS_CLASSES = [
        'New' => 'new',
        'Acknowledged' => 'acknowledged',
        'In Progress' => 'in-progress',
        'Waiting on Client' => 'waiting-on-client',
        'On Hold' => 'on-hold',
        'Completed' => 'completed',
        'Cancelled' => 'cancelled',
    ];

    /**
     * Status badge colors.
     */
    public const STATUS_COLORS = [
        'New' => ['bg' => '#e3f2fd', 'color' => '#1976d2'],
        'Acknowledged' => ['bg' => '#fff3e0', 'color' => '#f57c00'],
        'In Progress' => ['bg' => '#e8f5e9', 'color' => '#388e3c'],
        'Waiting on Client' => ['bg' => '#fef9e7', 'color' => '#b7950b'],
        'On Hold' => ['bg' => '#e8eaf6', 'color' => '#5c6bc0'],
        'Completed' => ['bg' => '#f5f5f5', 'color' => '#616161'],
        'Cancelled' => ['bg' => '#ffebee', 'color' => '#c62828'],
    ];

    /**
     * Request type labels.
     */
    public const REQUEST_TYPES = [
        'Support' => 'Support',
        'Feature Request' => 'Feature Request',
        'Content Update' => 'Content Update',
        'Consultation' => 'Consultation',
    ];

    /**
     * Priority levels.
     */
    public const PRIORITIES = [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    /**
     * Priority colors.
     */
    public const PRIORITY_COLORS = [
        'low' => ['bg' => '#f5f5f5', 'color' => '#616161'],
        'normal' => ['bg' => '#e3f2fd', 'color' => '#1976d2'],
        'high' => ['bg' => '#fff3e0', 'color' => '#f57c00'],
        'urgent' => ['bg' => '#ffebee', 'color' => '#c62828'],
    ];

    /**
     * Get service requests for an organization.
     *
     * @param int   $org_id Organization ID.
     * @param array $args   Optional query arguments.
     * @return array Array of service request post objects.
     */
    public static function getForOrg(int $org_id, array $args = []): array {
        $defaults = [
            'post_type' => 'service_request',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => 'organization',
                    'value' => $org_id,
                    'compare' => '=',
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $query_args = wp_parse_args($args, $defaults);

        // Merge meta_query if additional filters provided
        if (!empty($args['meta_query'])) {
            $query_args['meta_query'] = array_merge(
                [['key' => 'organization', 'value' => $org_id, 'compare' => '=']],
                $args['meta_query']
            );
        }

        return get_posts($query_args);
    }

    /**
     * Get active (non-closed) service requests for an organization.
     *
     * @param int $org_id Organization ID.
     * @return array Array of service request post objects.
     */
    public static function getActiveForOrg(int $org_id): array {
        return get_posts([
            'post_type' => 'service_request',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'organization',
                    'value' => $org_id,
                    'compare' => '=',
                ],
                [
                    'key' => 'request_status',
                    'value' => ['Completed', 'Cancelled'],
                    'compare' => 'NOT IN',
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    /**
     * Get total hours logged for a service request.
     *
     * Delegates to TimeEntryService for the actual calculation.
     *
     * @param int $sr_id Service request ID.
     * @return float Total billable hours.
     */
    public static function getTotalHours(int $sr_id): float {
        return TimeEntryService::getTotalHoursForSR($sr_id);
    }

    /**
     * Update service request status.
     *
     * @param int    $sr_id      Service request ID.
     * @param string $new_status New status value.
     * @return bool True on success.
     */
    public static function updateStatus(int $sr_id, string $new_status): bool {
        // Validate status
        if (!in_array($new_status, self::STATUSES, true)) {
            Logger::warning('ServiceRequestService', "Invalid status: {$new_status}");
            return false;
        }

        $result = update_post_meta($sr_id, 'request_status', $new_status);

        // If changing to Completed, set completed_date
        if ($new_status === 'Completed') {
            update_post_meta($sr_id, 'completed_date', wp_date('m/d/Y'));
        }

        Logger::debug('ServiceRequestService', "Updated SR {$sr_id} status to {$new_status}");

        return $result !== false;
    }

    /**
     * Get status badge HTML.
     *
     * @param string $status Status value.
     * @return string HTML for status badge.
     */
    public static function getStatusBadgeHtml(string $status): string {
        $class = self::STATUS_CLASSES[$status] ?? 'unknown';
        $colors = self::STATUS_COLORS[$status] ?? ['bg' => '#f5f5f5', 'color' => '#666'];

        return sprintf(
            '<span class="sr-status sr-status-%s" style="background:%s;color:%s;">%s</span>',
            esc_attr($class),
            esc_attr($colors['bg']),
            esc_attr($colors['color']),
            esc_html($status)
        );
    }

    /**
     * Get priority badge HTML.
     *
     * @param string $priority Priority value.
     * @return string HTML for priority badge.
     */
    public static function getPriorityBadgeHtml(string $priority): string {
        $priority_lower = strtolower($priority);
        $label = self::PRIORITIES[$priority_lower] ?? $priority;
        $colors = self::PRIORITY_COLORS[$priority_lower] ?? ['bg' => '#f5f5f5', 'color' => '#666'];

        return sprintf(
            '<span class="sr-priority sr-priority-%s" style="background:%s;color:%s;">%s</span>',
            esc_attr($priority_lower),
            esc_attr($colors['bg']),
            esc_attr($colors['color']),
            esc_html($label)
        );
    }

    /**
     * Get request type badge HTML.
     *
     * @param string $type Request type value.
     * @return string HTML for type badge.
     */
    public static function getTypeBadgeHtml(string $type): string {
        $label = self::REQUEST_TYPES[$type] ?? $type;

        return sprintf(
            '<span class="sr-type" style="background:#e8eaf6;color:#5c6bc0;">%s</span>',
            esc_html($label)
        );
    }

    /**
     * Check if a status is considered "closed" (Completed or Cancelled).
     *
     * @param string $status Status value.
     * @return bool True if closed.
     */
    public static function isClosedStatus(string $status): bool {
        return in_array($status, ['Completed', 'Cancelled'], true);
    }

    /**
     * Get a service request by ID with validation.
     *
     * @param int $sr_id Service request ID.
     * @return \WP_Post|null Post object or null if not found/invalid.
     */
    public static function get(int $sr_id): ?\WP_Post {
        $post = get_post($sr_id);

        if (!$post || $post->post_type !== 'service_request') {
            return null;
        }

        return $post;
    }

    /**
     * Get service request meta data as an array.
     *
     * @param int $sr_id Service request ID.
     * @return array Associative array of SR data.
     */
    public static function getData(int $sr_id): array {
        $post = self::get($sr_id);

        if (!$post) {
            return [];
        }

        // Get submitted_date, handling array storage
        $submitted_date = get_post_meta($sr_id, 'submitted_date', true);
        if (is_array($submitted_date)) {
            $submitted_date = reset($submitted_date);
        }

        // Get completed_date, handling array storage
        $completed_date = get_post_meta($sr_id, 'completed_date', true);
        if (is_array($completed_date)) {
            $completed_date = reset($completed_date);
        }

        return [
            'id' => $sr_id,
            'reference_number' => get_post_meta($sr_id, 'reference_number', true),
            'subject' => get_post_meta($sr_id, 'subject', true),
            'description' => get_post_meta($sr_id, 'description', true),
            'request_status' => get_post_meta($sr_id, 'request_status', true),
            'request_type' => get_post_meta($sr_id, 'request_type', true),
            'priority' => get_post_meta($sr_id, 'priority', true) ?: 'normal',
            'organization' => get_post_meta($sr_id, 'organization', true),
            'submitted_by' => get_post_meta($sr_id, 'submitted_by', true),
            'submitted_date' => $submitted_date ?: $post->post_date,
            'completed_date' => $completed_date,
            'hours' => self::getTotalHours($sr_id),
            'post_date' => $post->post_date,
            'post_modified' => $post->post_modified,
        ];
    }
}
