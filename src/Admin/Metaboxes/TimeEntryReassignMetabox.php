<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Metaboxes;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Quick Reassign metabox for Time Entry edit screen.
 *
 * Allows reassigning time entries to different SR/Project/Milestone
 * via cascading dropdowns.
 *
 * Migrated from: WPCode Snippet #2305
 */
class TimeEntryReassignMetabox {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        add_action('add_meta_boxes', [self::class, 'registerMetabox']);
        add_action('admin_head', [self::class, 'renderStyles']);
        add_action('admin_footer', [self::class, 'renderScripts']);

        // AJAX handlers
        add_action('wp_ajax_bbab_get_reassign_items', [self::class, 'handleGetItems']);
        add_action('wp_ajax_bbab_save_reassignment', [self::class, 'handleSaveReassignment']);

        Logger::debug('TimeEntryReassignMetabox', 'Registered reassign metabox');
    }

    /**
     * Register the metabox.
     */
    public static function registerMetabox(): void {
        add_meta_box(
            'bbab_te_quick_reassign',
            '&#128260; Quick Reassign',
            [self::class, 'renderMetabox'],
            'time_entry',
            'side',
            'high'
        );
    }

    /**
     * Render the metabox.
     *
     * @param \WP_Post $post Current post object.
     */
    public static function renderMetabox(\WP_Post $post): void {
        $te_id = $post->ID;

        // Get current assignments
        $current_sr = get_post_meta($te_id, 'related_service_request', true);
        $current_project = get_post_meta($te_id, 'related_project', true);
        $current_milestone = get_post_meta($te_id, 'related_milestone', true);

        // Determine current type and item
        $current_type = '';
        $current_item_id = 0;
        $current_org_id = 0;
        $current_display = 'None (Orphan!)';

        if (!empty($current_sr)) {
            $current_type = 'service_request';
            $current_item_id = (int) $current_sr;

            $sr_post = get_post($current_item_id);
            if ($sr_post) {
                $ref = get_post_meta($current_item_id, 'reference_number', true);
                $subject = get_post_meta($current_item_id, 'subject', true);
                $sr_edit_link = get_edit_post_link($current_item_id);
                $current_display = "&#128203; <a href=\"{$sr_edit_link}\" target=\"_blank\">{$ref} - {$subject}</a>";
                $current_org_id = (int) get_post_meta($current_item_id, 'organization', true);
            }
        } elseif (!empty($current_project)) {
            $current_type = 'project';
            $current_item_id = (int) $current_project;

            $proj_post = get_post($current_item_id);
            if ($proj_post) {
                $name = get_post_meta($current_item_id, 'project_name', true);
                $proj_edit_link = get_edit_post_link($current_item_id);
                $current_display = "&#128193; <a href=\"{$proj_edit_link}\" target=\"_blank\">{$name}</a>";
                $current_org_id = (int) get_post_meta($current_item_id, 'organization', true);
            }
        } elseif (!empty($current_milestone)) {
            $current_type = 'milestone';
            $current_item_id = (int) $current_milestone;

            $ms_post = get_post($current_milestone);
            if ($ms_post) {
                $name = get_post_meta($current_item_id, 'milestone_name', true);
                $ms_edit_link = get_edit_post_link($current_item_id);
                $current_display = "&#127937; <a href=\"{$ms_edit_link}\" target=\"_blank\">{$name}</a>";

                $project_id = (int) get_post_meta($current_item_id, 'related_project', true);
                if ($project_id) {
                    $current_org_id = (int) get_post_meta($project_id, 'organization', true);
                }
            }
        }

        // Get current org name
        $current_org_name = 'Unknown';
        if ($current_org_id) {
            $org_post = get_post($current_org_id);
            if ($org_post) {
                $current_org_name = $org_post->post_title;
            }
        }

        // Get all organizations
        $orgs = get_posts([
            'post_type' => 'client_organization',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
        ]);

        // Generate nonce
        $ajax_nonce = wp_create_nonce('bbab_te_reassign');
        ?>

        <div class="bbab-reassign-wrapper">
            <!-- Current Assignment Display -->
            <div class="bbab-current-assignment">
                <label>Currently Linked To:</label>
                <div class="bbab-current-value">
                    <?php if ($current_org_id): ?>
                        <span class="bbab-current-org"><?php echo esc_html($current_org_name); ?></span>
                    <?php endif; ?>
                    <span class="bbab-current-item"><?php echo wp_kses($current_display, ['a' => ['href' => [], 'target' => []]]); ?></span>
                </div>
            </div>

            <hr style="margin: 12px 0; border: none; border-top: 1px solid #ddd;">

            <!-- Reassign Form -->
            <div class="bbab-reassign-form">
                <label>Reassign To:</label>

                <!-- Organization Dropdown -->
                <select id="bbab-reassign-org" class="bbab-reassign-select" style="width: 100%; margin-bottom: 8px;">
                    <option value="">— Select Organization —</option>
                    <?php foreach ($orgs as $org): ?>
                        <option value="<?php echo esc_attr((string) $org->ID); ?>"
                                <?php selected($org->ID, $current_org_id); ?>>
                            <?php echo esc_html($org->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Type Selector -->
                <div class="bbab-type-selector" style="margin-bottom: 8px;">
                    <label style="display: inline-block; margin-right: 10px; font-weight: normal; cursor: pointer;">
                        <input type="radio" id="bbab_type_sr" name="bbab_reassign_type_radio" value="service_request"
                               <?php checked($current_type, 'service_request'); ?>> SR
                    </label>
                    <label style="display: inline-block; margin-right: 10px; font-weight: normal; cursor: pointer;">
                        <input type="radio" id="bbab_type_project" name="bbab_reassign_type_radio" value="project"
                               <?php checked($current_type, 'project'); ?>> Project
                    </label>
                    <label style="display: inline-block; font-weight: normal; cursor: pointer;">
                        <input type="radio" id="bbab_type_milestone" name="bbab_reassign_type_radio" value="milestone"
                               <?php checked($current_type, 'milestone'); ?>> Milestone
                    </label>
                </div>

                <!-- Item Dropdown -->
                <select id="bbab-reassign-item" class="bbab-reassign-select" style="width: 100%; margin-bottom: 10px;" disabled>
                    <option value="">— Select org & type first —</option>
                </select>

                <!-- Save Button -->
                <button type="button" id="bbab-reassign-save" class="button button-primary" style="width: 100%;" disabled>
                    Save Reassignment
                </button>

                <!-- Status Messages -->
                <div id="bbab-reassign-status" style="margin-top: 8px;"></div>
            </div>
        </div>

        <script type="text/javascript">
            var bbabReassignData = {
                postId: <?php echo intval($te_id); ?>,
                currentItemId: <?php echo intval($current_item_id); ?>,
                nonce: '<?php echo esc_js($ajax_nonce); ?>'
            };
        </script>
        <?php
    }

    /**
     * Handle AJAX request to get items by org and type.
     */
    public static function handleGetItems(): void {
        // Verify nonce
        if (!check_ajax_referer('bbab_te_reassign', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }

        $org_id = isset($_POST['org_id']) ? intval($_POST['org_id']) : 0;
        $item_type = isset($_POST['item_type']) ? sanitize_text_field($_POST['item_type']) : '';

        if (!$org_id || !$item_type) {
            wp_send_json_error(['message' => 'Missing org or type']);
            return;
        }

        $items = [];

        if ($item_type === 'service_request') {
            $posts = get_posts([
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
            ]);

            foreach ($posts as $post) {
                $ref = get_post_meta($post->ID, 'reference_number', true);
                $subject = get_post_meta($post->ID, 'subject', true);
                $status = get_post_meta($post->ID, 'request_status', true);
                $items[] = [
                    'id' => $post->ID,
                    'label' => "{$ref} - {$subject} [{$status}]",
                ];
            }
        } elseif ($item_type === 'project') {
            $posts = get_posts([
                'post_type' => 'project',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [
                    [
                        'key' => 'organization',
                        'value' => $org_id,
                        'compare' => '=',
                    ],
                ],
                'orderby' => 'title',
                'order' => 'ASC',
            ]);

            foreach ($posts as $post) {
                $name = get_post_meta($post->ID, 'project_name', true);
                $status = get_post_meta($post->ID, 'project_status', true);
                $items[] = [
                    'id' => $post->ID,
                    'label' => "{$name} [{$status}]",
                ];
            }
        } elseif ($item_type === 'milestone') {
            // First get all projects for this org
            $project_ids = get_posts([
                'post_type' => 'project',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'organization',
                        'value' => $org_id,
                        'compare' => '=',
                    ],
                ],
            ]);

            if (!empty($project_ids)) {
                $milestones = get_posts([
                    'post_type' => 'milestone',
                    'posts_per_page' => -1,
                    'post_status' => 'publish',
                    'meta_query' => [
                        [
                            'key' => 'related_project',
                            'value' => $project_ids,
                            'compare' => 'IN',
                        ],
                    ],
                    'orderby' => 'title',
                    'order' => 'ASC',
                ]);

                foreach ($milestones as $ms) {
                    $name = get_post_meta($ms->ID, 'milestone_name', true);
                    $status = get_post_meta($ms->ID, 'milestone_status', true);
                    $project_id = get_post_meta($ms->ID, 'related_project', true);
                    $project_name = get_post_meta($project_id, 'project_name', true);
                    $items[] = [
                        'id' => $ms->ID,
                        'label' => "{$name} [{$status}] (via {$project_name})",
                    ];
                }
            }
        }

        wp_send_json_success(['items' => $items]);
    }

    /**
     * Handle AJAX request to save reassignment.
     */
    public static function handleSaveReassignment(): void {
        // Verify nonce
        if (!check_ajax_referer('bbab_te_reassign', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $item_type = isset($_POST['item_type']) ? sanitize_text_field($_POST['item_type']) : '';
        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;

        // Validate
        if (!$post_id || !$item_type || !$item_id) {
            wp_send_json_error(['message' => 'Missing required fields']);
            return;
        }

        // Verify post exists and is a time entry
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'time_entry') {
            wp_send_json_error(['message' => 'Invalid time entry']);
            return;
        }

        // Verify user can edit this post
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }

        // Validate the target item exists
        $valid_types = ['service_request', 'project', 'milestone'];
        if (!in_array($item_type, $valid_types, true)) {
            wp_send_json_error(['message' => 'Invalid item type']);
            return;
        }

        $target = get_post($item_id);
        if (!$target || $target->post_type !== $item_type) {
            wp_send_json_error(['message' => 'Target item not found']);
            return;
        }

        // Clear all three relationship fields
        delete_post_meta($post_id, 'related_service_request');
        delete_post_meta($post_id, 'related_project');
        delete_post_meta($post_id, 'related_milestone');

        // Set the new relationship
        $meta_key_map = [
            'service_request' => 'related_service_request',
            'project' => 'related_project',
            'milestone' => 'related_milestone',
        ];

        update_post_meta($post_id, $meta_key_map[$item_type], $item_id);

        Logger::debug('TimeEntryReassignMetabox', "Reassigned TE {$post_id} to {$item_type} {$item_id}");

        // Build success message
        $type_labels = [
            'service_request' => 'Service Request',
            'project' => 'Project',
            'milestone' => 'Milestone',
        ];

        $target_title = $target->post_title;

        wp_send_json_success([
            'message' => "Reassigned to {$type_labels[$item_type]}: {$target_title}",
        ]);
    }

    /**
     * Render metabox styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'time_entry') {
            return;
        }

        echo '<style>
            .bbab-reassign-wrapper {
                padding: 5px 0;
            }
            .bbab-current-assignment label {
                display: block;
                font-weight: 600;
                margin-bottom: 4px;
                color: #1d2327;
            }
            .bbab-current-value {
                background: #f0f6fc;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 8px 10px;
            }
            .bbab-current-org {
                display: block;
                font-size: 11px;
                color: #666;
                margin-bottom: 2px;
            }
            .bbab-current-item {
                display: block;
                font-weight: 500;
                color: #2271b1;
                word-break: break-word;
            }
            .bbab-reassign-form label {
                display: block;
                font-weight: 600;
                margin-bottom: 6px;
                color: #1d2327;
            }
            .bbab-reassign-select {
                max-width: 100%;
            }
            .bbab-type-selector {
                background: #f6f7f7;
                padding: 8px;
                border-radius: 4px;
            }
            #bbab-reassign-status {
                font-size: 12px;
                min-height: 20px;
            }
        </style>';
    }

    /**
     * Render JavaScript for cascading dropdowns.
     */
    public static function renderScripts(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'time_entry') {
            return;
        }

        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Bail if our data isn't available
            if (typeof bbabReassignData === 'undefined') {
                console.error('BBAB Reassign: Data not found');
                return;
            }

            var $org = $('#bbab-reassign-org');
            var $typeRadios = $('input[name="bbab_reassign_type_radio"]');
            var $item = $('#bbab-reassign-item');
            var $saveBtn = $('#bbab-reassign-save');
            var $status = $('#bbab-reassign-status');
            var postId = bbabReassignData.postId;
            var currentItemId = bbabReassignData.currentItemId;
            var nonce = bbabReassignData.nonce;

            // Function to load items based on org + type
            function loadItems() {
                var orgId = $org.val();
                var itemType = $('input[name="bbab_reassign_type_radio"]:checked').val();

                if (!orgId || !itemType) {
                    $item.html('<option value="">— Select org & type first —</option>').prop('disabled', true);
                    $saveBtn.prop('disabled', true);
                    return;
                }

                $item.html('<option value="">Loading...</option>').prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'bbab_get_reassign_items',
                    nonce: nonce,
                    org_id: orgId,
                    item_type: itemType
                }, function(response) {
                    if (response.success && response.data.items) {
                        var options = '<option value="">— Select —</option>';
                        $.each(response.data.items, function(i, item) {
                            var selected = (item.id == currentItemId) ? ' selected' : '';
                            options += '<option value="' + item.id + '"' + selected + '>' + escapeHtml(item.label) + '</option>';
                        });
                        $item.html(options).prop('disabled', false);
                        updateSaveButton();
                    } else {
                        $item.html('<option value="">Error loading items</option>');
                    }
                }).fail(function() {
                    $item.html('<option value="">Error loading items</option>');
                });
            }

            // Helper to escape HTML in labels
            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            // Function to update save button state
            function updateSaveButton() {
                var itemId = $item.val();
                $saveBtn.prop('disabled', !itemId || itemId == currentItemId);
            }

            // Event listeners
            $org.on('change', loadItems);
            $typeRadios.on('change', loadItems);
            $item.on('change', updateSaveButton);

            // Save button click
            $saveBtn.on('click', function() {
                var itemType = $('input[name="bbab_reassign_type_radio"]:checked').val();
                var itemId = $item.val();

                if (!itemType || !itemId) {
                    $status.html('<span style="color: #dc3232;">Please select an item</span>');
                    return;
                }

                $saveBtn.prop('disabled', true).text('Saving...');
                $status.html('<span style="color: #666;">Saving...</span>');

                $.post(ajaxurl, {
                    action: 'bbab_save_reassignment',
                    nonce: nonce,
                    post_id: postId,
                    item_type: itemType,
                    item_id: itemId
                }, function(response) {
                    if (response.success) {
                        $status.html('<span style="color: #46b450;">&#10003; ' + response.data.message + '</span>');
                        currentItemId = itemId;
                        $saveBtn.text('Save Reassignment');
                        updateSaveButton();

                        // Refresh page to update display
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $status.html('<span style="color: #dc3232;">&#10007; ' + (response.data.message || 'Error saving') + '</span>');
                        $saveBtn.prop('disabled', false).text('Save Reassignment');
                    }
                }).fail(function() {
                    $status.html('<span style="color: #dc3232;">&#10007; Network error</span>');
                    $saveBtn.prop('disabled', false).text('Save Reassignment');
                });
            });

            // Initial load if org and type are pre-selected
            if ($org.val() && $('input[name="bbab_reassign_type_radio"]:checked').length) {
                loadItems();
            }
        });
        </script>
        <?php
    }
}
