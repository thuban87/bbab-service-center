<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Utils;

/**
 * Centralized configuration management.
 *
 * All settings are stored in a single wp_option (bbab_sc_settings).
 * This class provides type-safe access to configuration values.
 *
 * IMPORTANT: IDs are intentionally 0/null to force database configuration.
 * This keeps the plugin portable and avoids hardcoding site-specific values.
 */
class Settings {

    /**
     * Default configuration values.
     * These are fallbacks - actual values MUST come from wp_options.
     */
    private const DEFAULTS = [
        // Page IDs - MUST be configured in admin or database
        'dashboard_page_id' => 0,
        'login_page_id' => 0,
        'projects_page_id' => 0,
        'service_requests_page_id' => 0,

        // Form IDs - MUST be configured in admin or database
        'sr_form_id' => 0,
        'roadmap_form_id' => 0,

        // Pod Names (these are safe to hardcode as they're structural)
        'pod_project' => 'project',
        'pod_milestone' => 'milestone',
        'pod_service_request' => 'service_request',
        'pod_time_entry' => 'time_entry',
        'pod_invoice' => 'invoice',
        'pod_monthly_report' => 'monthly_report',
        'pod_client_org' => 'client_organization',
        'pod_roadmap' => 'roadmap_item',
        'pod_kb_article' => 'kb_article',
        'pod_project_report' => 'project_report',
        'pod_client_task' => 'client_task',

        // Feature Flags
        'debug_mode' => false,
        'simulation_enabled' => true,
        'stripe_test_mode' => true,

        // Business Logic (safe defaults)
        'default_free_hours' => 2.0,
        'hourly_rate' => 30.00,  // Standard hourly rate for support/overage billing
        'billing_day_of_month' => 1,
        'timezone' => 'America/Chicago',

        // Cookie/Session settings
        'simulation_cookie_name' => 'bbab_sc_sim_org',
        'simulation_cookie_expiry' => 3600, // 1 hour

        // Time Tracking Settings
        'forgotten_timer_email' => 'brad@bradsbitsandbytes.com',

        // PDF Generation Settings
        'pdf_logo_url' => '', // Set via admin settings or wp_options

        // Payment Settings
        'zelle_email' => 'wales108@gmail.com', // Zelle payment recipient email
        'cc_fee_percentage' => 0.03, // 3% credit card processing fee

        // Service Request Email Notification
        // Placeholders: {ref}, {org_name}, {user_name}, {user_email}, {type}, {subject}, {description}, {admin_link}
        'sr_notification_email' => 'brad@bradsbitsandbytes.com',
        'sr_notification_subject' => 'New Service Request: {ref} from {org_name}',
        'sr_notification_body' => '<h2>New Service Request Submitted</h2>
<p><strong>Reference:</strong> {ref}</p>
<p><strong>Client:</strong> {org_name}</p>
<p><strong>Submitted by:</strong> {user_name} ({user_email})</p>
<p><strong>Type:</strong> {type}</p>
<p><strong>Subject:</strong> {subject}</p>
<p><strong>Description:</strong></p>
<div style="background:#f5f5f5; padding:15px; border-left:4px solid #467FF7;">{description}</div>
{attachments_note}
<p><a href="{admin_link}" style="display:inline-block; background:#467FF7; color:white; padding:10px 20px; text-decoration:none; border-radius:4px; margin-top:15px;">View Service Request</a></p>',
    ];

    /**
     * Get a setting value.
     *
     * @param string $key     Setting key
     * @param mixed  $default Default value if not found
     * @return mixed
     */
    public static function get(string $key, $default = null) {
        $options = get_option('bbab_sc_settings', []);

        // Check saved options first
        if (isset($options[$key])) {
            return $options[$key];
        }

        // Fall back to defaults
        if (isset(self::DEFAULTS[$key])) {
            return self::DEFAULTS[$key];
        }

        // Finally, use provided default
        return $default;
    }

    /**
     * Update a setting value.
     */
    public static function set(string $key, $value): bool {
        $options = get_option('bbab_sc_settings', []);
        $options[$key] = $value;
        return update_option('bbab_sc_settings', $options);
    }

    /**
     * Bulk update settings (for settings page save).
     */
    public static function setMultiple(array $settings): bool {
        $options = get_option('bbab_sc_settings', []);
        $options = array_merge($options, $settings);
        return update_option('bbab_sc_settings', $options);
    }

    /**
     * Get a Pod name with type safety.
     */
    public static function getPodName(string $type): string {
        $key = 'pod_' . $type;
        return (string) self::get($key, $type);
    }

    /**
     * Check if debug mode is enabled.
     */
    public static function isDebugMode(): bool {
        return (bool) self::get('debug_mode', false);
    }

    /**
     * Check if a required setting is configured.
     * Use this to validate setup is complete.
     */
    public static function isConfigured(string $key): bool {
        $value = self::get($key);
        return !empty($value) && $value !== 0;
    }

    /**
     * Get all settings that need configuration (still at default 0).
     */
    public static function getMissingConfiguration(): array {
        $required = ['dashboard_page_id', 'login_page_id', 'sr_form_id'];
        $missing = [];

        foreach ($required as $key) {
            if (!self::isConfigured($key)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Get all current settings (for admin display).
     */
    public static function getAll(): array {
        $saved = get_option('bbab_sc_settings', []);
        return array_merge(self::DEFAULTS, $saved);
    }
}
