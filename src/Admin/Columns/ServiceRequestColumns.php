<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Modules\ServiceRequests\ServiceRequestService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Custom admin columns and filters for Service Requests.
 *
 * Handles:
 * - Custom column definitions and rendering
 * - Admin list filters (Org, Status, Priority)
 * - Quick status change dropdown in row actions
 * - Column styles
 *
 * Migrated from: WPCode Snippets #1716, #1717, #1844 (partial)
 */
class ServiceRequestColumns {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Column definition and rendering
        add_filter('manage_service_request_posts_columns', [self::class, 'defineColumns']);
        add_action('manage_service_request_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
        add_filter('manage_edit-service_request_sortable_columns', [self::class, 'sortableColumns']);

        // Filters
        add_action('restrict_manage_posts', [self::class, 'renderFilters']);
        add_filter('pre_get_posts', [self::class, 'applyFilters']);

        // Quick status change in row actions
        add_filter('post_row_actions', [self::class, 'addStatusChangeAction'], 10, 2);

        // Quick status change AJAX handler
        add_action('wp_ajax_bbab_change_sr_status', [self::class, 'handleStatusChangeAjax']);

        // Admin styles and scripts
        add_action('admin_head', [self::class, 'renderStyles']);
        add_action('admin_footer', [self::class, 'renderStatusChangeScript']);

        Logger::debug('ServiceRequestColumns', 'Registered SR column hooks');
    }

    /**
     * Define custom columns.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public static function defineColumns(array $columns): array {
        $new_columns = [];
        $new_columns['cb'] = $columns['cb'];
        $new_columns['reference'] = 'Ref #';
        $new_columns['subject'] = 'Subject';
        $new_columns['request_type'] = 'Type';
        $new_columns['organization'] = 'Client';
        $new_columns['request_status'] = 'Status';
        $new_columns['priority'] = 'Priority';
        $new_columns['hours'] = 'Hours';
        $new_columns['attachments'] = 'Files';
        $new_columns['submitted_by'] = 'Submitted By';
        $new_columns['date'] = 'Submitted';

        return $new_columns;
    }

    /**
     * Render column content.
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public static function renderColumn(string $column, int $post_id): void {
        switch ($column) {
            case 'reference':
                $ref = get_post_meta($post_id, 'reference_number', true);
                if ($ref) {
                    $edit_url = get_edit_post_link($post_id);
                    echo '<a href="' . esc_url($edit_url) . '" class="sr-ref-link">' . esc_html($ref) . '</a>';
                } else {
                    echo '—';
                }
                break;

            case 'subject':
                $subject = get_post_meta($post_id, 'subject', true);
                echo esc_html($subject);
                break;

            case 'request_type':
                $type = get_post_meta($post_id, 'request_type', true);
                echo ServiceRequestService::getTypeBadgeHtml($type ?: '');
                break;

            case 'organization':
                $org_id = get_post_meta($post_id, 'organization', true);
                if ($org_id) {
                    echo esc_html(get_the_title($org_id));
                } else {
                    echo '—';
                }
                break;

            case 'request_status':
                $status = get_post_meta($post_id, 'request_status', true);
                echo ServiceRequestService::getStatusBadgeHtml($status ?: 'New');
                break;

            case 'priority':
                $priority = get_post_meta($post_id, 'priority', true);
                echo ServiceRequestService::getPriorityBadgeHtml($priority ?: 'normal');
                break;

            case 'hours':
                $hours = ServiceRequestService::getTotalHours($post_id);
                echo $hours > 0 ? number_format($hours, 2) : '—';
                break;

            case 'attachments':
                self::renderAttachmentsColumn($post_id);
                break;

            case 'submitted_by':
                $user_id = get_post_meta($post_id, 'submitted_by', true);
                if ($user_id) {
                    $user = get_userdata((int) $user_id);
                    if ($user) {
                        echo esc_html($user->display_name);
                    } else {
                        echo '—';
                    }
                } else {
                    echo '—';
                }
                break;
        }
    }

    /**
     * Render attachments column content.
     *
     * @param int $post_id Post ID.
     */
    private static function renderAttachmentsColumn(int $post_id): void {
        // Try getting attachments multiple ways (Pods stores files weirdly)
        $attachments = get_post_meta($post_id, 'attachments', true);

        // First try: Direct meta value
        if (empty($attachments)) {
            $attachments = get_post_meta($post_id, 'attachments', false);
        }

        // Second try: Use Pods API
        if (empty($attachments) && function_exists('pods')) {
            $pod = pods('service_request', $post_id);
            if ($pod && $pod->exists()) {
                $attachments = $pod->field('attachments');
            }
        }

        if (!empty($attachments)) {
            // Handle various storage formats
            if (is_string($attachments)) {
                $attachments = maybe_unserialize($attachments);
            }

            // If it's a single file, wrap in array
            if (!is_array($attachments)) {
                $attachments = [$attachments];
            }

            // Count valid attachments
            $valid_attachments = [];
            foreach ($attachments as $att) {
                if (is_array($att) && isset($att['guid'])) {
                    $valid_attachments[] = $att['guid'];
                } elseif (is_numeric($att)) {
                    $url = wp_get_attachment_url((int) $att);
                    if ($url) {
                        $valid_attachments[] = $url;
                    }
                } elseif (is_string($att) && filter_var($att, FILTER_VALIDATE_URL)) {
                    $valid_attachments[] = $att;
                }
            }

            if (!empty($valid_attachments)) {
                $count = count($valid_attachments);
                $first = $valid_attachments[0];

                echo '<a href="' . esc_url($first) . '" target="_blank" class="sr-attachment-badge">';
                echo '<span class="dashicons dashicons-paperclip"></span> ' . $count;
                echo '</a>';
            } else {
                echo '—';
            }
        } else {
            echo '—';
        }
    }

    /**
     * Define sortable columns.
     *
     * @param array $columns Sortable columns.
     * @return array Modified sortable columns.
     */
    public static function sortableColumns(array $columns): array {
        $columns['submitted'] = 'submitted_date';
        $columns['request_status'] = 'request_status';
        $columns['priority'] = 'priority';
        return $columns;
    }

    /**
     * Render filter dropdowns.
     */
    public static function renderFilters(): void {
        global $typenow;

        if ($typenow !== 'service_request') {
            return;
        }

        // Organization filter
        $orgs = get_posts([
            'post_type' => 'client_organization',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
        ]);

        $selected_org = isset($_GET['filter_org']) ? sanitize_text_field($_GET['filter_org']) : '';

        echo '<select name="filter_org">';
        echo '<option value="">All Clients</option>';
        foreach ($orgs as $org) {
            $selected = selected($selected_org, (string) $org->ID, false);
            echo '<option value="' . esc_attr($org->ID) . '" ' . $selected . '>' .
                 esc_html($org->post_title) . '</option>';
        }
        echo '</select>';

        // Status filter
        $all_statuses = ServiceRequestService::STATUSES;
        $selected_status = isset($_GET['filter_request_status']) ? sanitize_text_field($_GET['filter_request_status']) : '';

        echo '<select name="filter_request_status">';
        echo '<option value="">All Statuses</option>';
        foreach ($all_statuses as $status) {
            $selected = selected($selected_status, $status, false);
            echo '<option value="' . esc_attr($status) . '" ' . $selected . '>' .
                 esc_html($status) . '</option>';
        }
        echo '</select>';

        // Priority filter
        $priorities = ServiceRequestService::PRIORITIES;
        $selected_priority = isset($_GET['filter_priority']) ? sanitize_text_field($_GET['filter_priority']) : '';

        echo '<select name="filter_priority">';
        echo '<option value="">All Priorities</option>';
        foreach ($priorities as $key => $label) {
            $selected = selected($selected_priority, $key, false);
            echo '<option value="' . esc_attr($key) . '" ' . $selected . '>' .
                 esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    /**
     * Apply filters to the query.
     *
     * @param \WP_Query $query The query object.
     */
    public static function applyFilters(\WP_Query $query): void {
        global $pagenow, $typenow;

        if ($pagenow !== 'edit.php' || $typenow !== 'service_request') {
            return;
        }

        if (!$query->is_admin || !$query->is_main_query()) {
            return;
        }

        $meta_query = [];

        // Organization filter
        if (!empty($_GET['filter_org'])) {
            $meta_query[] = [
                'key' => 'organization',
                'value' => intval($_GET['filter_org']),
            ];
        }

        // Status filter
        if (!empty($_GET['filter_request_status'])) {
            $status_value = sanitize_text_field($_GET['filter_request_status']);
            $meta_query[] = [
                'key' => 'request_status',
                'value' => $status_value,
                'compare' => '=',
            ];
        }

        // Priority filter
        if (!empty($_GET['filter_priority'])) {
            $meta_query[] = [
                'key' => 'priority',
                'value' => sanitize_text_field($_GET['filter_priority']),
            ];
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
    }

    /**
     * Add quick status change dropdown to row actions.
     *
     * @param array    $actions Existing row actions.
     * @param \WP_Post $post    Post object.
     * @return array Modified row actions.
     */
    public static function addStatusChangeAction(array $actions, \WP_Post $post): array {
        if ($post->post_type !== 'service_request') {
            return $actions;
        }

        $current_status = get_post_meta($post->ID, 'request_status', true);
        $statuses = ServiceRequestService::STATUSES;

        $status_options = '';
        foreach ($statuses as $status) {
            $selected = ($current_status === $status) ? 'selected' : '';
            $status_options .= sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr($status),
                $selected,
                esc_html($status)
            );
        }

        $actions['change_status'] = sprintf(
            '<span class="sr-status-change">
                <select class="sr-status-select" data-post-id="%d" data-nonce="%s">
                    %s
                </select>
            </span>',
            $post->ID,
            wp_create_nonce('bbab_sc_sr_status_' . $post->ID),
            $status_options
        );

        return $actions;
    }

    /**
     * Handle AJAX request for quick status change.
     *
     * Migrated from: WPCode Snippet #1844
     */
    public static function handleStatusChangeAjax(): void {
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $new_status = isset($_POST['new_status']) ? sanitize_text_field($_POST['new_status']) : '';
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

        // Verify nonce
        if (!wp_verify_nonce($nonce, 'bbab_sc_sr_status_' . $post_id)) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }

        // Verify user can edit
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }

        // Use ServiceRequestService to update status
        $result = ServiceRequestService::updateStatus($post_id, $new_status);

        if (!$result) {
            wp_send_json_error(['message' => 'Failed to update status']);
            return;
        }

        // Generate new badge HTML for list display
        $badge_html = ServiceRequestService::getStatusBadgeHtml($new_status);

        wp_send_json_success([
            'badge_html' => $badge_html,
            'new_status' => $new_status,
        ]);
    }

    /**
     * Render JavaScript for quick status change.
     */
    public static function renderStatusChangeScript(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-service_request') {
            return;
        }

        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.sr-status-select').on('change', function() {
                var $select = $(this);
                var postId = $select.data('post-id');
                var newStatus = $select.val();
                var nonce = $select.data('nonce');
                var $row = $select.closest('tr');

                $select.prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bbab_change_sr_status',
                        post_id: postId,
                        new_status: newStatus,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update the status badge in the list
                            var $statusCell = $row.find('.column-request_status');
                            if ($statusCell.length) {
                                $statusCell.html(response.data.badge_html);
                            }

                            // Show success message briefly
                            $select.css('border', '2px solid #46b450');
                            setTimeout(function() {
                                $select.css('border', '');
                                $select.prop('disabled', false);
                            }, 1000);
                        } else {
                            alert('Error: ' + (response.data.message || 'Unknown error'));
                            $select.prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('Failed to update status. Please try again.');
                        $select.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render column and badge styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'service_request') {
            return;
        }

        echo '<style>
            /* Status badges */
            .sr-status {
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 500;
                white-space: nowrap;
                display: inline-block;
            }

            /* Priority badges */
            .sr-priority {
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 500;
                display: inline-block;
            }

            /* Type badges */
            .sr-type {
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                display: inline-block;
            }

            /* Attachment badge */
            .sr-attachment-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3px 8px;
                background: #e8f5e9;
                color: #388e3c;
                border-radius: 3px;
                text-decoration: none;
                font-size: 11px;
            }
            .sr-attachment-badge:hover {
                background: #c8e6c9;
            }
            .sr-attachment-badge .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
            }

            /* Quick status change dropdown */
            .sr-status-select {
                padding: 4px 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 13px;
                background: white;
                cursor: pointer;
            }
            .sr-status-select:hover {
                border-color: #467FF7;
            }

            /* Reference number link */
            .sr-ref-link {
                font-family: monospace;
                font-weight: 600;
                color: #467FF7;
                text-decoration: none;
            }
            .sr-ref-link:hover {
                text-decoration: underline;
            }
        </style>';
    }
}
