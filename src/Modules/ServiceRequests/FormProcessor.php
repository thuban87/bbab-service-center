<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\ServiceRequests;

use BBAB\ServiceCenter\Utils\Logger;
use BBAB\ServiceCenter\Utils\Settings;

/**
 * WPForms Service Request submission processor.
 *
 * Handles:
 * - Processing WPForms submissions for the SR form
 * - Creating service_request posts with all meta fields
 * - Sending configurable email notifications
 *
 * Migrated from: WPCode Snippet #1732
 */
class FormProcessor {

    /**
     * Register hooks for form processing.
     */
    public static function register(): void {
        // Get form ID from settings
        $form_id = Settings::get('sr_form_id');

        if (empty($form_id) || $form_id === 0) {
            Logger::debug('FormProcessor', 'SR form ID not configured, skipping hook registration');
            return;
        }

        // Hook to specific form completion
        add_action('wpforms_process_complete_' . $form_id, [self::class, 'processSubmission'], 10, 4);

        Logger::debug('FormProcessor', "Registered form processor for form ID {$form_id}");
    }

    /**
     * Process WPForms submission and create Service Request.
     *
     * @param array $fields    Processed form fields.
     * @param array $entry     Form entry data.
     * @param array $form_data Form configuration data.
     * @param int   $entry_id  Entry ID.
     */
    public static function processSubmission(array $fields, array $entry, array $form_data, int $entry_id): void {
        // Verify user is logged in
        if (!is_user_logged_in()) {
            Logger::warning('FormProcessor', 'Service Request submission failed: User not logged in');
            return;
        }

        $user_id = get_current_user_id();
        $org_id = get_user_meta($user_id, 'organization', true);

        // Must have organization assigned
        if (empty($org_id)) {
            Logger::warning('FormProcessor', "Service Request submission failed: User {$user_id} has no organization");
            return;
        }

        // Extract form fields by label (resilient to field ID changes)
        $extracted = self::extractFields($fields);

        // Validate required fields
        if (empty($extracted['subject'])) {
            Logger::warning('FormProcessor', 'Service Request submission failed: Missing subject');
            return;
        }

        if (empty($extracted['description'])) {
            Logger::warning('FormProcessor', 'Service Request submission failed: Missing description');
            return;
        }

        // Create the Service Request post
        $post_id = wp_insert_post([
            'post_type' => 'service_request',
            'post_title' => 'Pending', // Will be updated by ReferenceGenerator hook
            'post_status' => 'publish',
            'post_author' => $user_id,
        ]);

        if (is_wp_error($post_id)) {
            Logger::error('FormProcessor', 'Service Request creation failed', [
                'error' => $post_id->get_error_message(),
            ]);
            return;
        }

        // Save all the fields
        update_post_meta($post_id, 'subject', $extracted['subject']);
        update_post_meta($post_id, 'description', $extracted['description']);
        update_post_meta($post_id, 'request_type', $extracted['request_type']);
        update_post_meta($post_id, 'request_status', 'New');
        update_post_meta($post_id, 'priority', 'Normal');
        update_post_meta($post_id, 'organization', $org_id);
        update_post_meta($post_id, 'submitted_by', $user_id);
        update_post_meta($post_id, 'submitted_date', current_time('m/d/Y'));

        // Handle attachments if present
        if (!empty($extracted['attachments'])) {
            self::saveAttachments($post_id, $extracted['attachments']);
        }

        // Force cache flush to ensure meta is available for reference generation
        wp_cache_flush();

        // Get the generated reference number (may have been set by ReferenceGenerator hook)
        $ref = get_post_meta($post_id, 'reference_number', true);

        // If ref still empty, generate it manually (belt and suspenders)
        if (empty($ref)) {
            $ref = ReferenceGenerator::generate();
            update_post_meta($post_id, 'reference_number', $ref);
            ReferenceGenerator::regenerateTitle($post_id);
        }

        // Send notification email
        self::sendNotificationEmail($post_id, [
            'ref' => $ref,
            'subject' => $extracted['subject'],
            'description' => $extracted['description'],
            'request_type' => $extracted['request_type'],
            'org_id' => $org_id,
            'user_id' => $user_id,
            'has_attachments' => !empty($extracted['attachments']),
        ]);

        Logger::info('FormProcessor', "Service Request created: {$ref} by user {$user_id} for org {$org_id}");
    }

    /**
     * Extract form fields by label.
     *
     * This approach is resilient to field ID changes in WPForms.
     *
     * @param array $fields Form fields data.
     * @return array Extracted field values.
     */
    private static function extractFields(array $fields): array {
        $extracted = [
            'subject' => '',
            'description' => '',
            'request_type' => 'Support', // Default
            'attachments' => null,
        ];

        foreach ($fields as $field_id => $field_data) {
            $label = strtolower($field_data['name'] ?? '');

            // Match fields by label (case-insensitive, flexible)
            if (stripos($label, 'subject') !== false) {
                $extracted['subject'] = sanitize_text_field($field_data['value'] ?? '');
            } elseif (stripos($label, 'description') !== false) {
                $extracted['description'] = wp_kses_post($field_data['value'] ?? '');
            } elseif (stripos($label, 'type') !== false || stripos($label, 'request type') !== false) {
                $extracted['request_type'] = sanitize_text_field($field_data['value'] ?? 'Support');
            } elseif (stripos($label, 'attachment') !== false || stripos($label, 'file') !== false || stripos($label, 'helpful') !== false) {
                $extracted['attachments'] = $field_data['value'] ?? null;
            }
        }

        return $extracted;
    }

    /**
     * Save attachments to post meta.
     *
     * Handles various WPForms file upload formats.
     *
     * @param int   $post_id     Post ID.
     * @param mixed $attachments Attachments data from form.
     */
    private static function saveAttachments(int $post_id, $attachments): void {
        if (is_array($attachments)) {
            update_post_meta($post_id, 'attachments', $attachments);
        } elseif (is_string($attachments)) {
            // Might be serialized, JSON, or single URL
            $attachment_data = json_decode($attachments, true) ?: maybe_unserialize($attachments);

            if ($attachment_data) {
                update_post_meta($post_id, 'attachments', $attachment_data);
            } else {
                // Single file URL
                update_post_meta($post_id, 'attachments', [$attachments]);
            }
        }
    }

    /**
     * Send notification email for new Service Request.
     *
     * Uses configurable templates from Settings.
     *
     * @param int   $post_id Post ID of the new SR.
     * @param array $data    Data for email placeholders.
     */
    public static function sendNotificationEmail(int $post_id, array $data): void {
        // Get email settings
        $to = Settings::get('sr_notification_email');
        $subject_template = Settings::get('sr_notification_subject');
        $body_template = Settings::get('sr_notification_body');

        if (empty($to)) {
            Logger::warning('FormProcessor', 'No notification email configured, skipping');
            return;
        }

        // Get additional data for placeholders
        $org_name = get_the_title($data['org_id']);
        $user = get_userdata($data['user_id']);
        $user_name = $user ? $user->display_name : 'Unknown';
        $user_email = $user ? $user->user_email : '';
        $admin_link = admin_url("post.php?post={$post_id}&action=edit");

        // Attachments note
        $attachments_note = !empty($data['has_attachments'])
            ? '<p><strong>Attachments:</strong> Yes (see admin panel)</p>'
            : '';

        // Placeholder replacements
        $replacements = [
            '{ref}' => $data['ref'],
            '{org_name}' => $org_name,
            '{user_name}' => $user_name,
            '{user_email}' => $user_email,
            '{type}' => $data['request_type'],
            '{subject}' => $data['subject'],
            '{description}' => $data['description'],
            '{admin_link}' => $admin_link,
            '{attachments_note}' => $attachments_note,
        ];

        // Build email
        $email_subject = str_replace(array_keys($replacements), array_values($replacements), $subject_template);
        $email_body = str_replace(array_keys($replacements), array_values($replacements), $body_template);

        // Headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: Brad\'s Bits and Bytes Portal <brad@bradsbitsandbytes.com>',
        ];

        // Send
        $sent = wp_mail($to, $email_subject, $email_body, $headers);

        if ($sent) {
            Logger::debug('FormProcessor', "Notification email sent for {$data['ref']}");
        } else {
            Logger::warning('FormProcessor', "Failed to send notification email for {$data['ref']}");
        }
    }
}
