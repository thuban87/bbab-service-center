<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin;

use BBAB\ServiceCenter\Utils\Logger;

/**
 * Cascading Dropdown Filters for Admin CPT Edit Screens.
 *
 * Provides AJAX endpoints and script enqueuing for:
 * - Service Request: Filter "Submitted By" contacts by selected Organization
 * - Invoice: Filter "Related Project" by selected Organization
 * - Invoice: Filter "Related Milestone" by selected Project
 *
 * Phase 8.4b
 */
class CascadingDropdowns {

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Enqueue script on relevant edit screens
        add_action('admin_enqueue_scripts', [self::class, 'enqueueScripts']);

        // AJAX handlers
        add_action('wp_ajax_bbab_get_org_contacts', [self::class, 'handleGetOrgContacts']);
        add_action('wp_ajax_bbab_cascade_get_projects', [self::class, 'handleGetOrgProjects']);
        add_action('wp_ajax_bbab_get_project_milestones', [self::class, 'handleGetProjectMilestones']);

        Logger::debug('CascadingDropdowns', 'Registered cascading dropdown hooks');
    }

    /**
     * Enqueue the cascading dropdowns script on relevant screens.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueueScripts(string $hook): void {
        // Only on post edit screens
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Only on service_request, invoice, and time_entry edit screens
        if (!in_array($screen->post_type, ['service_request', 'invoice', 'time_entry'], true)) {
            return;
        }

        wp_enqueue_script(
            'bbab-cascading-dropdowns',
            BBAB_SC_URL . 'assets/js/admin-cascading-dropdowns.js',
            ['jquery'],
            BBAB_SC_VERSION,
            true
        );

        wp_localize_script('bbab-cascading-dropdowns', 'bbabCascading', [
            'nonce' => wp_create_nonce('bbab_cascading_nonce'),
        ]);
    }

    /**
     * AJAX: Get contacts (users) for an organization.
     *
     * Used by SR screen to filter "Submitted By" dropdown.
     */
    public static function handleGetOrgContacts(): void {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bbab_cascading_nonce')) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        // Verify capability
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }

        $org_id = isset($_POST['org_id']) ? absint($_POST['org_id']) : 0;

        if (!$org_id) {
            wp_send_json_error(['message' => 'No organization specified.']);
            return;
        }

        // Get users associated with this organization
        // Users are linked via 'organization' user meta
        $users = get_users([
            'meta_key' => 'organization',
            'meta_value' => $org_id,
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);

        $contacts = [];
        foreach ($users as $user) {
            $contacts[$user->ID] = $user->display_name;
        }

        Logger::debug('CascadingDropdowns', 'Fetched org contacts', [
            'org_id' => $org_id,
            'count' => count($contacts),
        ]);

        wp_send_json_success(['contacts' => $contacts]);
    }

    /**
     * AJAX: Get projects for an organization.
     *
     * Used by Invoice screen to filter "Related Project" dropdown.
     */
    public static function handleGetOrgProjects(): void {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bbab_cascading_nonce')) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        // Verify capability
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }

        $org_id = isset($_POST['org_id']) ? absint($_POST['org_id']) : 0;

        if (!$org_id) {
            wp_send_json_error(['message' => 'No organization specified.']);
            return;
        }

        // Get projects for this organization
        $projects = get_posts([
            'post_type' => 'project',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_key' => 'organization',
            'meta_value' => $org_id,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $result = [];
        foreach ($projects as $project) {
            $ref = get_post_meta($project->ID, 'reference_number', true);
            $name = get_post_meta($project->ID, 'project_name', true) ?: $project->post_title;
            $display = $ref ? "{$ref} - {$name}" : $name;
            $result[$project->ID] = $display;
        }

        Logger::debug('CascadingDropdowns', 'Fetched org projects', [
            'org_id' => $org_id,
            'count' => count($result),
        ]);

        wp_send_json_success(['projects' => $result]);
    }

    /**
     * AJAX: Get milestones for a project.
     *
     * Used by Invoice screen to filter "Related Milestone" dropdown.
     */
    public static function handleGetProjectMilestones(): void {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bbab_cascading_nonce')) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        // Verify capability
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }

        $project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;

        if (!$project_id) {
            wp_send_json_error(['message' => 'No project specified.']);
            return;
        }

        // Get milestones for this project
        $milestones = get_posts([
            'post_type' => 'milestone',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_key' => 'related_project',
            'meta_value' => $project_id,
            'orderby' => 'menu_order',
            'order' => 'ASC',
        ]);

        $result = [];
        foreach ($milestones as $milestone) {
            $ref = get_post_meta($milestone->ID, 'reference_number', true);
            $name = get_post_meta($milestone->ID, 'milestone_name', true) ?: $milestone->post_title;
            $display = $ref ? "{$ref} - {$name}" : $name;
            $result[$milestone->ID] = $display;
        }

        Logger::debug('CascadingDropdowns', 'Fetched project milestones', [
            'project_id' => $project_id,
            'count' => count($result),
        ]);

        wp_send_json_success(['milestones' => $result]);
    }
}
