<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Pages;

use BBAB\ServiceCenter\Utils\Settings;
use BBAB\ServiceCenter\Utils\Logger;
use BBAB\ServiceCenter\Modules\TimeTracking\TEReferenceGenerator;
use BBAB\ServiceCenter\Cron\ForgottenTimerHandler;
use BBAB\ServiceCenter\Cron\DebugAutoDisable;

/**
 * Plugin Settings Page.
 *
 * Provides a centralized settings interface under Brad's Workbench.
 * Uses the Settings utility class for storage (bbab_sc_settings option).
 * Organized into tabs for better UX.
 *
 * Migrated from: WPCode Snippet #2359
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
     * Available tabs.
     */
    private const TABS = [
        'general' => 'General',
        'service-requests' => 'Service Requests',
        'time-tracking' => 'Time Tracking',
        'billing' => 'Billing',
        'stripe' => 'Stripe',
        'maintenance' => 'Maintenance',
    ];

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action('admin_menu', [$this, 'registerMenu'], 99);
        add_action('admin_init', [$this, 'registerSettings']);

        // AJAX handlers for maintenance tools
        add_action('wp_ajax_bbab_sc_check_forgotten_timers', [$this, 'handleForgottenTimerCheck']);
        add_action('wp_ajax_bbab_sc_send_test_sr_email', [$this, 'handleTestSREmail']);
        add_action('wp_ajax_bbab_sc_test_stripe_connection', [$this, 'handleTestStripeConnection']);
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

        // Sanitize General settings
        if (isset($input['service_center_name'])) {
            $existing['service_center_name'] = sanitize_text_field($input['service_center_name']);
        }

        // Debug mode handling (only when saving from General tab)
        if (isset($input['_tab']) && $input['_tab'] === 'general') {
            $was_debug_enabled = $existing['debug_mode'] ?? false;
            $is_debug_enabled = isset($input['debug_mode']) && $input['debug_mode'] === '1';

            $existing['debug_mode'] = $is_debug_enabled;

            // If debug was just enabled, set the timestamp
            if ($is_debug_enabled && !$was_debug_enabled) {
                $existing['debug_enabled_at'] = time();
            }

            // If debug was disabled, clear the timestamp
            if (!$is_debug_enabled) {
                $existing['debug_enabled_at'] = 0;
            }

            // Save auto-disable duration
            if (isset($input['debug_auto_disable'])) {
                $valid_durations = ['1hour', '4hours', '24hours', 'never'];
                $duration = sanitize_text_field($input['debug_auto_disable']);
                if (in_array($duration, $valid_durations, true)) {
                    $existing['debug_auto_disable'] = $duration;
                }
            }
        }

        // Sanitize Service Request settings
        if (isset($input['sr_form_id'])) {
            $existing['sr_form_id'] = absint($input['sr_form_id']);
        }

        if (isset($input['roadmap_form_id'])) {
            $existing['roadmap_form_id'] = absint($input['roadmap_form_id']);
        }

        if (isset($input['support_request_form_url'])) {
            $existing['support_request_form_url'] = sanitize_text_field($input['support_request_form_url']);
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

        if (isset($input['forgotten_timer_threshold'])) {
            $threshold = absint($input['forgotten_timer_threshold']);
            // Clamp between 1 and 24 hours
            $existing['forgotten_timer_threshold'] = max(1, min(24, $threshold));
        }

        // Sanitize Billing settings
        if (isset($input['zelle_email'])) {
            $existing['zelle_email'] = sanitize_email($input['zelle_email']);
        }

        if (isset($input['cc_fee_percentage'])) {
            // Convert from whole number percentage to decimal (3 -> 0.03)
            $existing['cc_fee_percentage'] = floatval($input['cc_fee_percentage']) / 100;
        }

        if (isset($input['hourly_rate'])) {
            $existing['hourly_rate'] = floatval($input['hourly_rate']);
        }

        if (isset($input['pdf_logo_url'])) {
            $existing['pdf_logo_url'] = esc_url_raw($input['pdf_logo_url']);
        }

        // Sanitize Stripe settings (only when saving from Stripe tab)
        // Checkbox handling: unchecked boxes don't submit, so we only update when on Stripe tab
        if (isset($input['_tab']) && $input['_tab'] === 'stripe') {
            $existing['stripe_test_mode'] = isset($input['stripe_test_mode']) && $input['stripe_test_mode'] === '1';
        }

        if (isset($input['stripe_test_publishable_key'])) {
            $existing['stripe_test_publishable_key'] = sanitize_text_field($input['stripe_test_publishable_key']);
        }

        if (isset($input['stripe_test_secret_key'])) {
            $existing['stripe_test_secret_key'] = sanitize_text_field($input['stripe_test_secret_key']);
        }

        if (isset($input['stripe_live_publishable_key'])) {
            $existing['stripe_live_publishable_key'] = sanitize_text_field($input['stripe_live_publishable_key']);
        }

        if (isset($input['stripe_live_secret_key'])) {
            $existing['stripe_live_secret_key'] = sanitize_text_field($input['stripe_live_secret_key']);
        }

        if (isset($input['stripe_webhook_secret'])) {
            $existing['stripe_webhook_secret'] = sanitize_text_field($input['stripe_webhook_secret']);
        }

        Logger::debug('SettingsPage', 'Settings saved');

        return $existing;
    }

    /**
     * Get current active tab.
     *
     * @return string Tab slug.
     */
    private function getCurrentTab(): string {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        return array_key_exists($tab, self::TABS) ? $tab : 'general';
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
        $current_tab = $this->getCurrentTab();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Service Center Settings', 'bbab-service-center'); ?></h1>

            <?php settings_errors('bbab_sc_messages'); ?>

            <nav class="nav-tab-wrapper">
                <?php foreach (self::TABS as $slug => $label): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=bbab-settings&tab=' . $slug)); ?>"
                       class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="bbab-settings-content" style="margin-top: 20px;">
                <?php
                switch ($current_tab) {
                    case 'general':
                        $this->renderGeneralTab($settings);
                        break;
                    case 'service-requests':
                        $this->renderServiceRequestsTab($settings);
                        break;
                    case 'time-tracking':
                        $this->renderTimeTrackingTab($settings);
                        break;
                    case 'billing':
                        $this->renderBillingTab($settings);
                        break;
                    case 'stripe':
                        $this->renderStripeTab($settings);
                        break;
                    case 'maintenance':
                        $this->renderMaintenanceTab($settings);
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render General tab.
     *
     * @param array $settings Current settings.
     */
    private function renderGeneralTab(array $settings): void {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields(self::OPTION_GROUP); ?>
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[_tab]" value="general">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="service_center_name"><?php esc_html_e('Admin Menu Name', 'bbab-service-center'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="service_center_name"
                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[service_center_name]"
                               value="<?php echo esc_attr($settings['service_center_name'] ?? 'Service Center'); ?>"
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('The name displayed in the admin menu for the client portal (default: "Service Center").', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Debug Mode', 'bbab-service-center'); ?></h2>
            <p class="description"><?php esc_html_e('Enable debug mode to log detailed information. Auto-disables after the selected duration to prevent accidentally leaving it on.', 'bbab-service-center'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Enable Debug Mode', 'bbab-service-center'); ?>
                    </th>
                    <td>
                        <?php
                        $debug_enabled = Settings::isDebugMode();
                        $remaining_time = DebugAutoDisable::getRemainingTime();
                        $expiry_display = DebugAutoDisable::getExpiryDisplay();
                        ?>
                        <label>
                            <input type="checkbox"
                                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[debug_mode]"
                                   value="1"
                                   <?php checked($debug_enabled); ?>>
                            <?php esc_html_e('Enable debug logging', 'bbab-service-center'); ?>
                        </label>
                        <?php if ($debug_enabled && $remaining_time): ?>
                            <p class="description" style="color: #b32d2e; margin-top: 8px;">
                                <strong><?php esc_html_e('Debug mode is ON', 'bbab-service-center'); ?></strong> &mdash;
                                <?php echo esc_html(sprintf('Auto-disables in %s (at %s)', $remaining_time, $expiry_display)); ?>
                            </p>
                        <?php elseif ($debug_enabled): ?>
                            <p class="description" style="color: #b32d2e; margin-top: 8px;">
                                <strong><?php esc_html_e('Debug mode is ON', 'bbab-service-center'); ?></strong> &mdash;
                                <?php esc_html_e('No auto-disable configured', 'bbab-service-center'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="debug_auto_disable"><?php esc_html_e('Auto-Disable After', 'bbab-service-center'); ?></label>
                    </th>
                    <td>
                        <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[debug_auto_disable]" id="debug_auto_disable">
                            <option value="1hour" <?php selected($settings['debug_auto_disable'] ?? '4hours', '1hour'); ?>>1 hour</option>
                            <option value="4hours" <?php selected($settings['debug_auto_disable'] ?? '4hours', '4hours'); ?>>4 hours (Recommended)</option>
                            <option value="24hours" <?php selected($settings['debug_auto_disable'] ?? '4hours', '24hours'); ?>>24 hours</option>
                            <option value="never" <?php selected($settings['debug_auto_disable'] ?? '4hours', 'never'); ?>>Never (not recommended)</option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Debug mode will automatically turn off after this duration. You\'ll receive an email notification when it disables.', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>
            </table>

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
                        <td><code>service_center_name</code></td>
                        <td><?php echo esc_html($settings['service_center_name'] ?? 'Service Center'); ?></td>
                    </tr>
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
                        <td><?php echo ($settings['debug_mode'] ?? false) ? 'true' : 'false'; ?></td>
                    </tr>
                    <tr>
                        <td><code>simulation_enabled</code></td>
                        <td><?php echo ($settings['simulation_enabled'] ?? false) ? 'true' : 'false'; ?></td>
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
                        <td><code>stripe_test_mode</code></td>
                        <td><?php echo ($settings['stripe_test_mode'] ?? true) ? 'true (using test keys)' : 'false (LIVE!)'; ?></td>
                    </tr>
                    <tr>
                        <td><code>stripe_configured</code></td>
                        <td><?php echo Settings::isStripeConfigured() ? '<span style="color:#1e8449;">Yes</span>' : '<span style="color:#b32d2e;">No - add API keys</span>'; ?></td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button(__('Save Settings', 'bbab-service-center')); ?>
        </form>
        <?php
    }

    /**
     * Render Service Requests tab.
     *
     * @param array $settings Current settings.
     */
    private function renderServiceRequestsTab(array $settings): void {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields(self::OPTION_GROUP); ?>
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[_tab]" value="service-requests">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="sr_form_id"><?php esc_html_e('Service Request Form ID', 'bbab-service-center'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="sr_form_id"
                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[sr_form_id]"
                               value="<?php echo esc_attr($settings['sr_form_id'] ?? ''); ?>"
                               class="small-text"
                               min="0">
                        <p class="description">
                            <?php esc_html_e('WPForms form ID for Service Request submissions. Find this in WPForms > All Forms.', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="roadmap_form_id"><?php esc_html_e('Roadmap/Feature Request Form ID', 'bbab-service-center'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="roadmap_form_id"
                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[roadmap_form_id]"
                               value="<?php echo esc_attr($settings['roadmap_form_id'] ?? ''); ?>"
                               class="small-text"
                               min="0">
                        <p class="description">
                            <?php esc_html_e('WPForms form ID for Feature Request/Roadmap submissions.', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="support_request_form_url"><?php esc_html_e('Support Request Form URL', 'bbab-service-center'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="support_request_form_url"
                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[support_request_form_url]"
                               value="<?php echo esc_attr($settings['support_request_form_url'] ?? '/support-request-form/'); ?>"
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('URL path to the support request form page (used by dashboard button).', 'bbab-service-center'); ?>
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
            </table>

            <h2><?php esc_html_e('Test Email', 'bbab-service-center'); ?></h2>
            <p class="description"><?php esc_html_e('Send a test email using the configured template above. Save your settings first!', 'bbab-service-center'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Send Test Email', 'bbab-service-center'); ?>
                    </th>
                    <td>
                        <button type="button" id="bbab-send-test-sr-email" class="button button-secondary">
                            <?php esc_html_e('Send Test Email', 'bbab-service-center'); ?>
                        </button>
                        <span id="bbab-test-email-status" style="margin-left: 10px;"></span>
                        <p class="description">
                            <?php esc_html_e('Sends a test email to the notification address with sample data.', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <script>
            jQuery(document).ready(function($) {
                $('#bbab-send-test-sr-email').on('click', function() {
                    var $btn = $(this);
                    var $status = $('#bbab-test-email-status');

                    $btn.prop('disabled', true);
                    $status.html('<span style="color: #666;">Sending...</span>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'bbab_sc_send_test_sr_email',
                            nonce: '<?php echo wp_create_nonce('bbab_sc_send_test_sr_email'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<span style="color: #1e8449; font-weight: 500;">' + response.data.message + '</span>');
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

            <?php submit_button(__('Save Settings', 'bbab-service-center')); ?>
        </form>
        <?php
    }

    /**
     * Render Time Tracking tab.
     *
     * @param array $settings Current settings.
     */
    private function renderTimeTrackingTab(array $settings): void {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields(self::OPTION_GROUP); ?>
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[_tab]" value="time-tracking">

            <table class="form-table" role="presentation">
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
                            <?php esc_html_e('Email address to receive alerts when a timer has been running too long.', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="forgotten_timer_threshold"><?php esc_html_e('Alert Threshold (Hours)', 'bbab-service-center'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="forgotten_timer_threshold"
                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[forgotten_timer_threshold]"
                               value="<?php echo esc_attr($settings['forgotten_timer_threshold'] ?? 4); ?>"
                               class="small-text"
                               min="1"
                               max="24"
                               step="1">
                        <span><?php esc_html_e('hours', 'bbab-service-center'); ?></span>
                        <p class="description">
                            <?php esc_html_e('Send an alert email when a timer has been running for this many hours. Default: 4 hours.', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save Settings', 'bbab-service-center')); ?>
        </form>
        <?php
    }

    /**
     * Render Billing tab.
     *
     * @param array $settings Current settings.
     */
    private function renderBillingTab(array $settings): void {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields(self::OPTION_GROUP); ?>
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[_tab]" value="billing">

            <table class="form-table" role="presentation">
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
                        <?php
                        // Display as whole number (convert from decimal: 0.03 -> 3)
                        $cc_fee_decimal = floatval($settings['cc_fee_percentage'] ?? 0.03);
                        $cc_fee_display = $cc_fee_decimal * 100;
                        ?>
                        <input type="number"
                               id="cc_fee_percentage"
                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[cc_fee_percentage]"
                               value="<?php echo esc_attr($cc_fee_display); ?>"
                               class="small-text"
                               step="0.1"
                               min="0"
                               max="100">
                        <span>%</span>
                        <p class="description">
                            <?php esc_html_e('Credit card processing fee percentage (e.g., 3 for 3%). Applied to card payments.', 'bbab-service-center'); ?>
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
        <?php
    }

    /**
     * Render Stripe tab.
     *
     * @param array $settings Current settings.
     */
    private function renderStripeTab(array $settings): void {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields(self::OPTION_GROUP); ?>
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[_tab]" value="stripe">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Test Mode', 'bbab-service-center'); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[stripe_test_mode]"
                                   value="1"
                                   <?php checked($settings['stripe_test_mode'] ?? true); ?>>
                            <?php esc_html_e('Use Stripe Test Mode (recommended during development)', 'bbab-service-center'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, uses test API keys. Uncheck for live payments.', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="stripe_test_publishable_key"><?php esc_html_e('Test Publishable Key', 'bbab-service-center'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="stripe_test_publishable_key"
                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[stripe_test_publishable_key]"
                               value="<?php echo esc_attr($settings['stripe_test_publishable_key'] ?? ''); ?>"
                               class="large-text"
                               placeholder="pk_test_...">
                        <p class="description">
                            <?php esc_html_e('Starts with pk_test_', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="stripe_test_secret_key"><?php esc_html_e('Test Secret Key', 'bbab-service-center'); ?></label>
                    </th>
                    <td>
                        <?php
                        $test_secret = $settings['stripe_test_secret_key'] ?? '';
                        $test_secret_masked = !empty($test_secret) ? substr($test_secret, 0, 12) . '...' . substr($test_secret, -4) : '';
                        ?>
                        <input type="password"
                               id="stripe_test_secret_key"
                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[stripe_test_secret_key]"
                               value="<?php echo esc_attr($test_secret); ?>"
                               class="large-text"
                               placeholder="sk_test_..."
                               autocomplete="off">
                        <?php if (!empty($test_secret_masked)): ?>
                            <p class="description" style="color: #1e8449;">
                                <?php echo esc_html('Currently set: ' . $test_secret_masked); ?>
                            </p>
                        <?php else: ?>
                            <p class="description">
                                <?php esc_html_e('Starts with sk_test_ - Keep this secret!', 'bbab-service-center'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="stripe_live_publishable_key"><?php esc_html_e('Live Publishable Key', 'bbab-service-center'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="stripe_live_publishable_key"
                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[stripe_live_publishable_key]"
                               value="<?php echo esc_attr($settings['stripe_live_publishable_key'] ?? ''); ?>"
                               class="large-text"
                               placeholder="pk_live_...">
                        <p class="description">
                            <?php esc_html_e('Starts with pk_live_ - For production use', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="stripe_live_secret_key"><?php esc_html_e('Live Secret Key', 'bbab-service-center'); ?></label>
                    </th>
                    <td>
                        <?php
                        $live_secret = $settings['stripe_live_secret_key'] ?? '';
                        $live_secret_masked = !empty($live_secret) ? substr($live_secret, 0, 12) . '...' . substr($live_secret, -4) : '';
                        ?>
                        <input type="password"
                               id="stripe_live_secret_key"
                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[stripe_live_secret_key]"
                               value="<?php echo esc_attr($live_secret); ?>"
                               class="large-text"
                               placeholder="sk_live_..."
                               autocomplete="off">
                        <?php if (!empty($live_secret_masked)): ?>
                            <p class="description" style="color: #1e8449;">
                                <?php echo esc_html('Currently set: ' . $live_secret_masked); ?>
                            </p>
                        <?php else: ?>
                            <p class="description">
                                <?php esc_html_e('Starts with sk_live_ - Keep this secret!', 'bbab-service-center'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="stripe_webhook_secret"><?php esc_html_e('Webhook Signing Secret', 'bbab-service-center'); ?></label>
                    </th>
                    <td>
                        <?php
                        $webhook_secret = $settings['stripe_webhook_secret'] ?? '';
                        $webhook_masked = !empty($webhook_secret) ? substr($webhook_secret, 0, 12) . '...' . substr($webhook_secret, -4) : '';
                        ?>
                        <input type="password"
                               id="stripe_webhook_secret"
                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[stripe_webhook_secret]"
                               value="<?php echo esc_attr($webhook_secret); ?>"
                               class="large-text"
                               placeholder="whsec_..."
                               autocomplete="off">
                        <?php if (!empty($webhook_masked)): ?>
                            <p class="description" style="color: #1e8449;">
                                <?php echo esc_html('Currently set: ' . $webhook_masked); ?>
                            </p>
                        <?php else: ?>
                            <p class="description">
                                <?php esc_html_e('Starts with whsec_ - Get this from Stripe Webhooks settings', 'bbab-service-center'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <?php esc_html_e('Webhook Endpoint', 'bbab-service-center'); ?>
                    </th>
                    <td>
                        <code style="display: block; padding: 8px 12px; background: #f0f0f1; border-radius: 4px;">
                            <?php echo esc_url(rest_url('bbab/v1/stripe-webhook')); ?>
                        </code>
                        <p class="description" style="margin-top: 8px;">
                            <?php esc_html_e('Add this URL in your Stripe Dashboard > Developers > Webhooks. Enable checkout.session.completed and payment_intent.succeeded events.', 'bbab-service-center'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Test Connection', 'bbab-service-center'); ?></h2>
            <p class="description"><?php esc_html_e('Verify your Stripe API keys are working correctly. Save your settings first!', 'bbab-service-center'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Test Keys', 'bbab-service-center'); ?>
                    </th>
                    <td>
                        <button type="button" id="bbab-test-stripe-test" class="button button-secondary">
                            <?php esc_html_e('Test Connection (Test Keys)', 'bbab-service-center'); ?>
                        </button>
                        <span id="bbab-stripe-test-status" style="margin-left: 10px;"></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Live Keys', 'bbab-service-center'); ?>
                    </th>
                    <td>
                        <button type="button" id="bbab-test-stripe-live" class="button button-secondary">
                            <?php esc_html_e('Test Connection (Live Keys)', 'bbab-service-center'); ?>
                        </button>
                        <span id="bbab-stripe-live-status" style="margin-left: 10px;"></span>
                    </td>
                </tr>
            </table>

            <script>
            jQuery(document).ready(function($) {
                function testStripeConnection(mode, $btn, $status) {
                    $btn.prop('disabled', true);
                    $status.html('<span style="color: #666;">Testing...</span>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'bbab_sc_test_stripe_connection',
                            nonce: '<?php echo wp_create_nonce('bbab_sc_test_stripe'); ?>',
                            mode: mode
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<span style="color: #1e8449; font-weight: 500;">' + response.data.message + '</span>');
                            } else {
                                $status.html('<span style="color: #b32d2e;">' + response.data + '</span>');
                            }
                            $btn.prop('disabled', false);
                        },
                        error: function() {
                            $status.html('<span style="color: #b32d2e;">Request failed</span>');
                            $btn.prop('disabled', false);
                        }
                    });
                }

                $('#bbab-test-stripe-test').on('click', function() {
                    testStripeConnection('test', $(this), $('#bbab-stripe-test-status'));
                });

                $('#bbab-test-stripe-live').on('click', function() {
                    testStripeConnection('live', $(this), $('#bbab-stripe-live-status'));
                });
            });
            </script>

            <?php submit_button(__('Save Settings', 'bbab-service-center')); ?>
        </form>
        <?php
    }

    /**
     * Render Maintenance tab.
     *
     * @param array $settings Current settings.
     */
    private function renderMaintenanceTab(array $settings): void {
        ?>
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

        <div style="margin-top: 20px; padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 4px;">
            <strong><?php esc_html_e('Manual Analytics Fetch', 'bbab-service-center'); ?></strong>
            <p style="margin: 8px 0 0 0;">
                <?php esc_html_e('Use the', 'bbab-service-center'); ?>
                <a href="<?php echo esc_url(admin_url('tools.php?page=client-health-dashboard')); ?>">Client Health Dashboard</a>
                <?php esc_html_e('to manually fetch GA4 and PageSpeed data for specific clients.', 'bbab-service-center'); ?>
            </p>
        </div>

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

    /**
     * Handle AJAX request to send test SR notification email.
     */
    public function handleTestSREmail(): void {
        if (!check_ajax_referer('bbab_sc_send_test_sr_email', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }

        $settings = Settings::getAll();

        $recipient = $settings['sr_notification_email'] ?? '';
        if (empty($recipient)) {
            wp_send_json_error('No notification email configured');
            return;
        }

        // Sample data for placeholders
        $placeholders = [
            '{ref}' => 'SR-0042',
            '{org_name}' => 'Test Organization',
            '{user_name}' => 'John Doe',
            '{user_email}' => 'john@example.com',
            '{type}' => 'Support Request',
            '{subject}' => 'Test Service Request Subject',
            '{description}' => 'This is a test description for the service request email template. It demonstrates how the email will look when a real service request is submitted.',
            '{admin_link}' => admin_url('edit.php?post_type=service_request'),
            '{attachments_note}' => '<p><strong>Attachments:</strong> Yes (2 files)</p>',
        ];

        // Build subject and body
        $subject = $settings['sr_notification_subject'] ?? 'New Service Request: {ref} from {org_name}';
        $body = $settings['sr_notification_body'] ?? '';

        // Replace placeholders
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
        $body = str_replace(array_keys($placeholders), array_values($placeholders), $body);

        // Add test notice to subject
        $subject = '[TEST] ' . $subject;

        // Send email
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $sent = wp_mail($recipient, $subject, $body, $headers);

        if ($sent) {
            wp_send_json_success([
                'message' => 'Test email sent to ' . $recipient,
            ]);
        } else {
            wp_send_json_error('Failed to send email. Check your mail configuration.');
        }
    }

    /**
     * Handle AJAX request to test Stripe connection.
     */
    public function handleTestStripeConnection(): void {
        if (!check_ajax_referer('bbab_sc_test_stripe', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }

        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'test';
        $settings = Settings::getAll();

        // Get the appropriate keys based on mode
        if ($mode === 'live') {
            $secret_key = $settings['stripe_live_secret_key'] ?? '';
            $mode_label = 'Live';
        } else {
            $secret_key = $settings['stripe_test_secret_key'] ?? '';
            $mode_label = 'Test';
        }

        if (empty($secret_key)) {
            wp_send_json_error("No {$mode_label} secret key configured");
            return;
        }

        // Test the connection by fetching account info
        $response = wp_remote_get('https://api.stripe.com/v1/account', [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret_key,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Connection failed: ' . $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && isset($body['id'])) {
            $account_name = $body['business_profile']['name'] ?? $body['id'];
            wp_send_json_success([
                'message' => "Connected! Account: {$account_name}",
            ]);
        } elseif (isset($body['error']['message'])) {
            wp_send_json_error('Stripe error: ' . $body['error']['message']);
        } else {
            wp_send_json_error("Connection failed (HTTP {$code})");
        }
    }
}
