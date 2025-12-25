<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Core;

use BBAB\ServiceCenter\Utils\Settings;

/**
 * Bootstraps simulation mode EARLY in the WordPress lifecycle.
 *
 * This class MUST be instantiated on plugins_loaded with priority 1.
 * It reads a cookie to determine if simulation is active and defines
 * the constant BEFORE any other plugin code runs.
 *
 * Flow:
 * 1. Admin clicks "Simulate" in Workbench -> sets cookie via AJAX/redirect
 * 2. On next page load, this class reads cookie on plugins_loaded (priority 1)
 * 3. If valid cookie + admin user, defines BBAB_SC_SIMULATED_ORG_ID constant
 * 4. All subsequent code (including Pods queries) sees the simulated org
 */
class SimulationBootstrap {

    private const COOKIE_NAME = 'bbab_sc_sim_org';

    /**
     * Initialize simulation check.
     * Call this on plugins_loaded with priority 1.
     */
    public static function init(): void {
        // Don't run on AJAX requests that SET the simulation (chicken/egg)
        // Those will set the cookie, and NEXT request will use it
        if (self::isSettingSimulation()) {
            return;
        }

        $org_id = self::getSimulatedOrgFromCookie();

        if ($org_id && self::isCurrentUserAdmin()) {
            define('BBAB_SC_SIMULATED_ORG_ID', $org_id);
        } else {
            // Define as 0 so the constant exists (avoids repeated defined() checks)
            define('BBAB_SC_SIMULATED_ORG_ID', 0);
        }
    }

    /**
     * Check if this request is setting simulation (not reading it).
     */
    private static function isSettingSimulation(): bool {
        return isset($_POST['bbab_sc_set_simulation']) ||
               isset($_GET['bbab_sc_set_simulation']);
    }

    /**
     * Get org ID from cookie if valid.
     */
    private static function getSimulatedOrgFromCookie(): ?int {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            return null;
        }

        $value = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]));

        // Cookie format: "org_id|nonce_hash"
        $parts = explode('|', $value);
        if (count($parts) !== 2) {
            return null;
        }

        $org_id = (int) $parts[0];
        $hash = $parts[1];

        // Verify hash (prevents tampering)
        $expected_hash = self::generateHash($org_id);
        if (!hash_equals($expected_hash, $hash)) {
            // Invalid hash - cookie was tampered with
            self::clearSimulation();
            return null;
        }

        // Verify org exists
        if (!get_post($org_id) || get_post_type($org_id) !== 'client_organization') {
            self::clearSimulation();
            return null;
        }

        return $org_id;
    }

    /**
     * Check if current user is admin.
     * Note: We can't use current_user_can() this early reliably,
     * so we check the user's roles directly.
     */
    private static function isCurrentUserAdmin(): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        $user = wp_get_current_user();
        return in_array('administrator', (array) $user->roles, true);
    }

    /**
     * Set simulation mode (call this from Workbench UI).
     */
    public static function setSimulation(int $org_id): bool {
        if (!current_user_can('manage_options')) {
            return false;
        }

        $hash = self::generateHash($org_id);
        $value = $org_id . '|' . $hash;
        $expiry = time() + (int) Settings::get('simulation_cookie_expiry', 3600);

        // Set cookie for frontend
        setcookie(
            self::COOKIE_NAME,
            $value,
            [
                'expires' => $expiry,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Strict'
            ]
        );

        return true;
    }

    /**
     * Clear simulation mode.
     */
    public static function clearSimulation(): void {
        setcookie(
            self::COOKIE_NAME,
            '',
            [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Strict'
            ]
        );

        // Also unset from current request
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /**
     * Generate secure hash for cookie validation.
     */
    private static function generateHash(int $org_id): string {
        // Use WordPress auth salt for security
        return hash_hmac('sha256', (string) $org_id, wp_salt('auth'));
    }

    /**
     * Check if simulation is currently set (cookie exists).
     * Use this in admin UI to show current state.
     */
    public static function isSimulationSet(): bool {
        return isset($_COOKIE[self::COOKIE_NAME]);
    }

    /**
     * Get the currently simulated org ID (for admin UI display).
     */
    public static function getCurrentSimulatedOrgId(): ?int {
        return self::getSimulatedOrgFromCookie();
    }

    /**
     * Handle simulation exit request from URL.
     * Call this on 'init' hook.
     */
    public static function handleExitRequest(): void {
        // Check for exit request
        if (!isset($_GET['bbab_sc_exit_simulation'])) {
            return;
        }

        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'bbab_sc_simulation')) {
            return;
        }

        // Must be admin
        if (!current_user_can('manage_options')) {
            return;
        }

        // Clear the simulation
        self::clearSimulation();

        // Redirect to remove query args
        $redirect = remove_query_arg(['bbab_sc_exit_simulation', '_wpnonce']);
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Handle simulation start request from URL.
     * Call this on 'init' hook.
     */
    public static function handleStartRequest(): void {
        // Check for start request
        if (!isset($_GET['bbab_sc_simulate_org'])) {
            return;
        }

        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'bbab_sc_simulation')) {
            return;
        }

        // Must be admin
        if (!current_user_can('manage_options')) {
            return;
        }

        $org_id = absint($_GET['bbab_sc_simulate_org']);

        // Verify org exists
        $org = get_post($org_id);
        if (!$org || $org->post_type !== 'client_organization') {
            return;
        }

        // Set simulation
        self::setSimulation($org_id);

        // Redirect to remove query args
        $redirect = remove_query_arg(['bbab_sc_simulate_org', '_wpnonce']);
        wp_safe_redirect($redirect);
        exit;
    }
}
