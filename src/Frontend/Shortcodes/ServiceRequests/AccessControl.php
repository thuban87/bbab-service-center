<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\ServiceRequests;

use BBAB\ServiceCenter\Utils\UserContext;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Service Request Access Control.
 *
 * Restricts single service request page access by organization.
 * - Admins can see all SRs
 * - Users can only see SRs belonging to their organization
 * - Simulation mode is respected
 *
 * Migrated from: WPCode Snippet #1843
 */
class AccessControl {

    /**
     * Register the access control hook.
     */
    public static function register(): void {
        add_action('template_redirect', [self::class, 'restrictAccess']);
        Logger::debug('AccessControl', 'Registered SR access control hook');
    }

    /**
     * Restrict access to single service request pages.
     */
    public static function restrictAccess(): void {
        // Only check on single SR pages
        if (!is_singular('service_request')) {
            return;
        }

        // Admins can see everything (including simulation mode)
        if (current_user_can('administrator')) {
            return;
        }

        // Must be logged in
        if (!is_user_logged_in()) {
            self::redirectToArchive();
            return;
        }

        global $post;

        // Get SR's organization
        $sr_org_id = get_post_meta($post->ID, 'organization', true);

        // Get user's organization (simulation-aware via UserContext)
        $user_org_id = UserContext::getCurrentOrgId();

        // No org match means no access
        if (empty($user_org_id) || $sr_org_id != $user_org_id) {
            Logger::warning('AccessControl', 'SR access denied', [
                'sr_id' => $post->ID,
                'sr_org' => $sr_org_id,
                'user_org' => $user_org_id,
                'user_id' => get_current_user_id(),
            ]);
            self::redirectToArchive();
            return;
        }
    }

    /**
     * Redirect to the service requests archive page.
     */
    private static function redirectToArchive(): void {
        wp_redirect(home_url('/service-requests/'));
        exit;
    }
}
