<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Pages;

use BBAB\ServiceCenter\Utils\Settings;
use BBAB\ServiceCenter\Utils\Logger;
use BBAB\ServiceCenter\Modules\TimeTracking\TEReferenceGenerator;
use BBAB\ServiceCenter\Cron\ForgottenTimerHandler;

/**
 * Plugin Settings Page.
 *
 * Provides a centralized settings interface under Brad's Workbench.
 * Uses the Settings utility class for storage (bbab_sc_settings option).
 *
 * Partially migrated from: WPCode Snippet #2359
 */
class SettingsPage {

    /**
     * Option group name for settings API.
     */
    private const OPTION_GROUP = 'bbab_sc_settings_group';

    /**
     * Option name (matches Settings utility class).
     */
    private const OPTION_NAME = 'bbab_sc_settings';

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action('admin_menu', [$this, 'registerMenu'], 99);
        add_action('admin_init', [$this, 'registerSettings']);

        // AJAX handlers for maintenance tools
        add_action('wp_ajax_bbab_sc_check_forgotten_timers', [$this, 'handleForgottenTimerCheck']);
    }

    /**
     * Register the settings submenu under Workbench.
     */
    public function registerMenu(): void {
        add_submenu_page(
            'bbab-workbench',
            __('Settings', 'bbab-service-center'),
            __('Settings', 'bbab-service-center'),
            'manage_options',
            'bbab-settings',
            [$this, 'renderPage']
        );
    }

    /**
     * Register settings with WordPress Settings API.
     */
    public function registerSettings(): void {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitizeSettings'],
            ]
        );
    }

    /**
     * Sanitize settings on save.
     *
     * @param array $input Raw input from form.
     * @return array Sanitized settings.
     */
    public function sanitizeSettings(array $input): array {
        // Get existing settings to preserve values we're not editing
        $existing = get_option(self::OPTION_NAME, []);

        // Sanitize Service Request settings
        if (isset($input['sr_form_id'])) {
            $existing['sr_form_id'] = absint($input['sr_form_id']);
        }

        if (isset($input['sr_notification_email'])) {
            $existing['sr_notification_email'] = sanitize_email($input['sr_notification_email']);
        }

        if (isset($input['sr_notification_subject'])) {
            $existing['sr_notification_subject'] = sanitize_text_field($input['sr_notification_subject']);
        }

        if (isset($input['sr_notification_body'])) {
            // Allow HTML in email body but sanitize it
            $existing['sr_notification_body'] = wp_kses_post($input['sr_notification_body']);
        }

        // Sanitize Time Tracking settings
        if (isset($input['forgotten_timer_email'])) {
            $existing['forgotten_timer_email'] = sanitize_email($input['forgotten_timer_email']);
        }

        // Sanitize Billing settings
        if (isset($input['zelle_email'])) {
            $existing['zelle_email'] = sanitize_email($input['zelle_email']);
        }

        if (isset($input['cc_fee_percentage'])) {
            $existing['cc_fee_percentage'] = floatval($input['cc_fee_percentage']);
        }

        if (isset($input['hourly_rate'])) {
            $existing['hourly_rate'] = floatval($input['hourly_rate']);
        }

        if (isset($input['pdf_logo_url'])) {
            $existing['pdf_logo_url'] = esc_url_raw($input['pdf_logo_url']);
        }

        Logger::debug('SettingsPage', 'Settings saved');

        return $existing;
    }

    /**
     * Render the settings page.
     */
    public function renderPage(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Check if settings were saved
        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'bbab_sc_messages',
                'bbab_sc_message',
                __('Settings saved.', 'bbab-service-center'),
                'updated'
            );
        }

        $settings = Settings::getAll();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Service Center Settings', 'bbab-service-center'); ?></h1>

            <?php settings_errors('bbab_sc_messages'); ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>

                <table class="form-table" role="presentation">

                    <!-- Service Requests Section -->
                    <tr>
                        <th colspan="2" style="padding-bottom: 0;">
                            <h2 style="margin: 0; padding: 10px 0; border-bottom: 1px solid #ccc;">
                                Service Requests
                            </h2>
                        </th>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="sr_form_id"><?php esc_html_e('WPForms Form ID', 'bbab-service-center'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   id="sr_form_id"
                                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[sr_form_id]"
                                   value="<?php echo esc_attr($settings['sr_form_id'] ?? ''); ?>"
                                   class="small-text"
                                   min="0">
                            <p class="description">
                                <?php esc_html_e('The WPForms form ID for Service Request submissions. Find this in WPForms > All Forms.', 'bbab-service-center'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="sr_notification_email"><?php esc_html_e('Notification Email', 'bbab-service-center'); ?></label>
                        </th>
                        <td>
                            <input type="email"
                                   id="sr_notification_email"
                                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[sr_notification_email]"
                                   value="<?php echo esc_attr($settings['sr_notification_email'] ?? ''); ?>"
                                   class="regular-text">
                            <p class="description">
                                <?php esc_html_e('Email address to receive new Service Request notifications.', 'bbab-service-center'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="sr_notification_subject"><?php esc_html_e('Email Subject', 'bbab-service-center'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="sr_notification_subject"
                                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[sr_notification_subject]"
                                   value="<?php echo esc_attr($settings['sr_notification_subject'] ?? ''); ?>"
                                   class="large-text">
                            <p class="description">
                                <?php esc_html_e('Placeholders: {ref}, {org_name}, {user_name}, {type}, {subject}', 'bbab-service-center'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="sr_notification_body"><?php esc_html_e('Email Body', 'bbab-service-center'); ?></label>
                        </th>
                        <td>
                            <?php
                            wp_editor(
                                $settings['sr_notification_body'] ?? '',
                                'sr_notification_body',
                                [
                                    'textarea_name' => self::OPTION_NAME . '[sr_notification_body]',
                                    'textarea_rows' => 15,
                                    'media_buttons' => false,
                                    'teeny' => false,
                                    'quicktags' => true,
                                ]
                            );
                            ?>
                            <p class="description" style="margin-top: 10px;">
                                <?php esc_html_e('Placeholders you can use:', 'bbab-service-center'); ?><br>
                                <code>{ref}</code> - Reference number (e.g., SR-0042)<br>
                                <code>{org_name}</code> - Client organization name<br>
                                <code>{user_name}</code> - Submitter's display name<br>
                                <code>{user_email}</code> - Submitter's email<br>
                                <code>{type}</code> - Request type (Support, Feature Request, etc.)<br>
                                <code>{subject}</code> - Request subject line<br>
                                <code>{description}</code> - Full request description<br>
                                <code>{admin_link}</code> - Link to edit the SR in admin<br>
                                <code>{attachments_note}</code> - Shows "Attachments: Yes" if files attached
                            </p>
                        </td>
                    </tr>

                    <!-- General Section (Placeholder) -->
                    <tr>
                        <th colspan="2" style="padding-bottom: 0;">
                            <h2 style="margin: 20px 0 0 0; padding: 10px 0; border-bottom: 1px solid #ccc;">
                                General
                                <span style="font-size: 12px; font-weight: normal; color: #666; margin-left: 10px;">
                                    (Available after Phase 7 migration)
                                </span>
                            </h2>
                        </th>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e('Admin Menu Name', 'bbab-service-center'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   value="Service Center"
                                   class="regular-text"
                                   disabled>
                            <p class="description" style="color: #999;">
                                <?php esc_html_e('This setting is currently managed by snippet #2359. It will be migrated in Phase 7.', 'bbab-service-center'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Time Tracking Section -->
                    <tr>
                        <th colspan="2" style="padding-bottom: 0;">
                            <h2 style="margin: 20px 0 0 0; padding: 10px 0; border-bottom: 1px solid #ccc;">
                                Time Tracking
                            </h2>
                        </th>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="forgotten_timer_email"><?php esc_html_e('Forgotten Timer Email', 'bbab-service-center'); ?></label>
                        </th>
                        <td>
                            <input type="email"
                                   id="forgotten_timer_email"
                                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[forgotten_timer_email]"
                                   value="<?php echo esc_attr($settings['forgotten_timer_email'] ?? ''); ?>"
                                   class="regular-text">
                            <p class="description">
                                <?php esc_html_e('Email address to receive alerts when a timer has been running 4+ hours.', 'bbab-service-center'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Billing Section -->
                    <tr>
                        <th colspan="2" style="padding-bottom: 0;">
                            <h2 style="margin: 20px 0 0 0; padding: 10px 0; border-bottom: 1px solid #ccc;">
                                Billing & Payments
                            </h2>
                        </th>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="zelle_email"><?php esc_html_e('Zelle Email', 'bbab-service-center'); ?></label>
                        </th>
                        <td>
                            <input type="email"
                                   id="zelle_email"
                                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[zelle_email]"
                                   value="<?php echo esc_attr($settings['zelle_email'] ?? 'wales108@gmail.com'); ?>"
                                   class="regular-text">
                            <p class="description">
                                <?php esc_html_e('Email address displayed on invoices for Zelle payments.', 'bbab-service-center'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="cc_fee_percentage"><?php esc_html_e('Credit Card Fee %', 'bbab-service-center'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   id="cc_fee_percentage"
                                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[cc_fee_percentage]"
                                   value="<?php echo esc_attr($settings['cc_fee_percentage'] ?? 0.03); ?>"
                                   class="small-text"
                                   step="0.001"
                                   min="0"
                                   max="1">
                            <p class="description">
                                <?php esc_html_e('Credit card processing fee as decimal (e.g., 0.03 = 3%). Applied to card payments.', 'bbab-service-center'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="hourly_rate"><?php esc_html_e('Default Hourly Rate', 'bbab-service-center'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   id="hourly_rate"
                                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[hourly_rate]"
                                   value="<?php echo esc_attr($settings['hourly_rate'] ?? 30); ?>"
                                   class="small-text"
                                   step="0.01"
                                   min="0">
                            <p class="description">
                                <?php esc_html_e('Default hourly rate for support/overage billing (per hour).', 'bbab-service-center'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="pdf_logo_url"><?php esc_html_e('PDF Logo URL', 'bbab-service-center'); ?></label>
                        </th>
                        <td>
                            <input type="url"
                                   id="pdf_logo_url"
                                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[pdf_logo_url]"
                                   value="<?php echo esc_attr($settings['pdf_logo_url'] ?? ''); ?>"
                                   class="large-text">
                            <p class="description">
                                <?php esc_html_e('Full URL to logo image for invoice PDFs. Leave blank for default.', 'bbab-service-center'); ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <?php submit_button(__('Save Settings', 'bbab-service-center')); ?>
            </form>

            <!-- Maintenance Section (separate from main form) -->
            <hr style="margin-top: 40px;">

            <h2><?php esc_html_e('Maintenance', 'bbab-service-center'); ?></h2>
            <p class="description"><?php esc_html_e('One-time maintenance tasks for data migrations and cleanup.', 'bbab-service-center'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Time Entry References', 'bbab-service-center'); ?>
                    </th>
                    <td>
                        <?php
                        $te_count = TEReferenceGenerator::getCountWithoutReference();
                        ?>
                        <p style="margin-bottom: 10px;">
                            <?php if ($te_count > 0): ?>
                                <span style="color: #b32d2e; font-weight: 500;">
                                    <?php echo esc_html(sprintf('%d time entries need reference numbers.', $te_count)); ?>
                                </span>
                            <?php else: ?>
                                <span style="color: #1e8449;">
                                    <?php esc_html_e('All time entries have reference numbers.', 'bbab-service-center'); ?>
                                </span>
                            <?php endif; ?>
                        </p>

                        <button type="button"
                                id="bbab-backfill-te-refs"
                                class="button button-secondary"
                                <?php echo $te_count === 0 ? 'disabled' : ''; ?>>
                            <?php esc_html_e('Backfill TE References', 'bbab-service-center'); ?>
                        </button>
                        <span id="bbab-backfill-status" style="margin-left: 10px;"></span>

                        <p class="description" style="margin-top: 8px;">
                            <?php esc_html_e('Assigns TE-XXXX reference numbers to existing time entries that don\'t have one. Numbers are assigned chronologically (oldest entry gets the lowest number).', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>

                <?php
                $orphaned_count = TEReferenceGenerator::getOrphanedTeReferenceCount();
                if ($orphaned_count > 0):
                ?>
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Cleanup Orphaned Meta', 'bbab-service-center'); ?>
                    </th>
                    <td>
                        <p style="margin-bottom: 10px;">
                            <span style="color: #b32d2e; font-weight: 500;">
                                <?php echo esc_html(sprintf('%d orphaned te_reference meta values found.', $orphaned_count)); ?>
                            </span>
                        </p>

                        <button type="button"
                                id="bbab-cleanup-te-refs"
                                class="button button-secondary">
                            <?php esc_html_e('Delete Orphaned Meta', 'bbab-service-center'); ?>
                        </button>
                        <span id="bbab-cleanup-status" style="margin-left: 10px;"></span>

                        <p class="description" style="margin-top: 8px;">
                            <?php esc_html_e('Removes incorrectly created te_reference meta values. This is safe - these values are not used.', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>

                <tr>
                    <th scope="row">
                        <?php esc_html_e('Forgotten Timer Check', 'bbab-service-center'); ?>
                    </th>
                    <td>
                        <p style="margin-bottom: 10px;">
                            <?php esc_html_e('Manually run the forgotten timer check (normally runs every 30 minutes).', 'bbab-service-center'); ?>
                        </p>

                        <button type="button"
                                id="bbab-check-forgotten-timers"
                                class="button button-secondary">
                            <?php esc_html_e('Check for Forgotten Timers', 'bbab-service-center'); ?>
                        </button>
                        <span id="bbab-forgotten-timer-status" style="margin-left: 10px;"></span>

                        <p class="description" style="margin-top: 8px;">
                            <?php esc_html_e('Checks for timers running 4+ hours and sends an alert email if any are found.', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <script>
            jQuery(document).ready(function($) {
                $('#bbab-backfill-te-refs').on('click', function() {
                    var $btn = $(this);
                    var $status = $('#bbab-backfill-status');

                    if (!confirm('This will assign reference numbers to all time entries without one. Continue?')) {
                        return;
                    }

                    $btn.prop('disabled', true);
                    $status.html('<span style="color: #666;">Processing...</span>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'bbab_sc_backfill_te_refs',
                            nonce: '<?php echo wp_create_nonce('bbab_sc_backfill_te_refs'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<span style="color: #1e8449; font-weight: 500;">' + response.data.message + '</span>');
                                // Keep button disabled since work is done
                                if (response.data.processed > 0) {
                                    setTimeout(function() {
                                        location.reload();
                                    }, 2000);
                                }
                            } else {
                                $status.html('<span style="color: #b32d2e;">Error: ' + response.data.message + '</span>');
                                $btn.prop('disabled', false);
                            }
                        },
                        error: function() {
                            $status.html('<span style="color: #b32d2e;">Request failed. Check console for details.</span>');
                            $btn.prop('disabled', false);
                        }
                    });
                });

                // Cleanup orphaned te_reference values
                $('#bbab-cleanup-te-refs').on('click', function() {
                    var $btn = $(this);
                    var $status = $('#bbab-cleanup-status');

                    if (!confirm('This will delete orphaned te_reference meta values. Continue?')) {
                        return;
                    }

                    $btn.prop('disabled', true);
                    $status.html('<span style="color: #666;">Cleaning up...</span>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'bbab_sc_cleanup_te_refs',
                            nonce: '<?php echo wp_create_nonce('bbab_sc_cleanup_te_refs'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<span style="color: #1e8449; font-weight: 500;">' + response.data.message + '</span>');
                                if (response.data.deleted > 0) {
                                    setTimeout(function() {
                                        location.reload();
                                    }, 2000);
                                }
                            } else {
                                $status.html('<span style="color: #b32d2e;">Error: ' + response.data.message + '</span>');
                                $btn.prop('disabled', false);
                            }
                        },
                        error: function() {
                            $status.html('<span style="color: #b32d2e;">Request failed. Check console for details.</span>');
                            $btn.prop('disabled', false);
                        }
                    });
                });

                // Check for forgotten timers
                $('#bbab-check-forgotten-timers').on('click', function() {
                    var $btn = $(this);
                    var $status = $('#bbab-forgotten-timer-status');

                    $btn.prop('disabled', true);
                    $status.html('<span style="color: #666;">Checking...</span>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'bbab_sc_check_forgotten_timers',
                            nonce: '<?php echo wp_create_nonce('bbab_sc_check_forgotten_timers'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                var color = response.data.count > 0 ? '#b32d2e' : '#1e8449';
                                $status.html('<span style="color: ' + color + '; font-weight: 500;">' + response.data.message + '</span>');
                            } else {
                                $status.html('<span style="color: #b32d2e;">Error: ' + response.data + '</span>');
                            }
                            $btn.prop('disabled', false);
                        },
                        error: function() {
                            $status.html('<span style="color: #b32d2e;">Request failed. Check console for details.</span>');
                            $btn.prop('disabled', false);
                        }
                    });
                });
            });
            </script>

            <hr style="margin-top: 40px;">

            <h2><?php esc_html_e('Current Configuration', 'bbab-service-center'); ?></h2>
            <p class="description"><?php esc_html_e('Quick reference for debugging. These values are read from the database.', 'bbab-service-center'); ?></p>

            <table class="widefat" style="max-width: 600px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Setting', 'bbab-service-center'); ?></th>
                        <th><?php esc_html_e('Value', 'bbab-service-center'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>sr_form_id</code></td>
                        <td><?php echo esc_html($settings['sr_form_id'] ?: '(not set)'); ?></td>
                    </tr>
                    <tr>
                        <td><code>sr_notification_email</code></td>
                        <td><?php echo esc_html($settings['sr_notification_email'] ?: '(not set)'); ?></td>
                    </tr>
                    <tr>
                        <td><code>forgotten_timer_email</code></td>
                        <td><?php echo esc_html($settings['forgotten_timer_email'] ?: '(not set)'); ?></td>
                    </tr>
                    <tr>
                        <td><code>debug_mode</code></td>
                        <td><?php echo $settings['debug_mode'] ? 'true' : 'false'; ?></td>
                    </tr>
                    <tr>
                        <td><code>simulation_enabled</code></td>
                        <td><?php echo $settings['simulation_enabled'] ? 'true' : 'false'; ?></td>
                    </tr>
                    <tr>
                        <td><code>zelle_email</code></td>
                        <td><?php echo esc_html($settings['zelle_email'] ?: '(not set)'); ?></td>
                    </tr>
                    <tr>
                        <td><code>cc_fee_percentage</code></td>
                        <td><?php echo esc_html($settings['cc_fee_percentage'] ?? '0.03'); ?></td>
                    </tr>
                    <tr>
                        <td><code>hourly_rate</code></td>
                        <td><?php echo esc_html($settings['hourly_rate'] ?? '30'); ?></td>
                    </tr>
                    <tr>
                        <td><code>pdf_logo_url</code></td>
                        <td><?php echo esc_html($settings['pdf_logo_url'] ?: '(default)'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Handle AJAX request to manually check for forgotten timers.
     */
    public function handleForgottenTimerCheck(): void {
        if (!check_ajax_referer('bbab_sc_check_forgotten_timers', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }

        $result = ForgottenTimerHandler::manualCheck();

        wp_send_json_success($result);
    }
}
