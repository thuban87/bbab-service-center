<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Core;

use BBAB\ServiceCenter\Utils\Settings;
use BBAB\ServiceCenter\Utils\UserContext;
use BBAB\ServiceCenter\Utils\Logger;
use BBAB\ServiceCenter\Utils\View;

/**
 * Server-side Portal Access Control.
 *
 * Implements bbab-portal style security: protected content is NEVER sent
 * to the browser for unauthorized users. Instead, a login form is rendered
 * directly on the protected page.
 *
 * Security philosophy:
 * - Use template_redirect hook (server-side, before any content)
 * - Never generate protected content for unauthorized requests
 * - Render login form on the protected URL itself
 * - Handle authentication without redirecting to wp-login.php
 *
 * Protects:
 * - /client-dashboard/ and all child pages
 *
 * Migrated from: WPCode Snippet #1040 (enhanced with bbab-portal security model)
 */
class PortalAccessControl {

    /**
     * Protected page ID (from settings).
     */
    private int $portal_page_id = 0;

    /**
     * Register access control hooks.
     */
    public function register(): void {
        // Check access on template_redirect (before any content is sent)
        add_action('template_redirect', [$this, 'checkAccess'], 5);

        // Handle login form submission
        add_action('template_redirect', [$this, 'handleLoginSubmission'], 4);

        // Handle failed login - redirect back to portal, not wp-login
        add_action('wp_login_failed', [$this, 'handleFailedLogin']);

        Logger::debug('PortalAccessControl', 'Registered portal access control hooks');
    }

    /**
     * Check if current page requires authentication.
     */
    public function checkAccess(): void {
        // Only check on frontend
        if (is_admin()) {
            return;
        }

        // Get protected page ID
        $this->portal_page_id = (int) Settings::get('dashboard_page_id', 0);

        // If not configured, skip (allows setup)
        if ($this->portal_page_id === 0) {
            Logger::debug('PortalAccessControl', 'No dashboard_page_id configured, skipping protection');
            return;
        }

        // Check if current page is protected
        if (!$this->isProtectedPage()) {
            return;
        }

        error_log('[BBAB-SC] checkAccess: Protected page detected, user_id=' . get_current_user_id() . ', logged_in=' . (is_user_logged_in() ? 'yes' : 'no'));

        Logger::debug('PortalAccessControl', 'Protected page access check', [
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'logged_in' => is_user_logged_in(),
            'user_id' => get_current_user_id(),
        ]);

        // Admins always have access (including simulation mode)
        if (current_user_can('manage_options')) {
            error_log('[BBAB-SC] checkAccess: Admin detected, granting access and returning early');
            Logger::debug('PortalAccessControl', 'Admin access granted');
            return;
        }

        // Check if user is authenticated
        if (!is_user_logged_in()) {
            Logger::debug('PortalAccessControl', 'Unauthenticated access to protected page', [
                'page_id' => get_the_ID(),
                'url' => $_SERVER['REQUEST_URI'] ?? '',
            ]);
            $this->renderLoginPage();
            exit;
        }

        // User is logged in - check if they have an organization
        $org_id = UserContext::getCurrentOrgId();
        Logger::debug('PortalAccessControl', 'Checking user org', [
            'user_id' => get_current_user_id(),
            'org_id' => $org_id,
        ]);

        if (!$org_id) {
            Logger::warning('PortalAccessControl', 'Authenticated user has no organization', [
                'user_id' => get_current_user_id(),
            ]);
            error_log('[BBAB-SC] About to call renderAccessDenied()');
            $this->renderAccessDenied('Your account is not associated with a client organization. Please contact support.');
            error_log('[BBAB-SC] After renderAccessDenied() - should not see this if exit works');
            exit;
        }

        // User is authenticated and has an org - allow access
        Logger::debug('PortalAccessControl', 'Access granted', [
            'user_id' => get_current_user_id(),
            'org_id' => $org_id,
        ]);
    }

    /**
     * Check if current page is protected (portal or child of portal).
     */
    private function isProtectedPage(): bool {
        global $post;

        if (!$post) {
            return false;
        }

        $current_id = (int) $post->ID;

        // Direct match
        if ($current_id === $this->portal_page_id) {
            return true;
        }

        // Check if it's a child page (walk up the ancestor chain)
        $ancestors = get_post_ancestors($current_id);
        if (in_array($this->portal_page_id, $ancestors, true)) {
            return true;
        }

        return false;
    }

    /**
     * Handle login form submission on the portal page.
     */
    public function handleLoginSubmission(): void {
        // Only handle POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        // Check for our specific login action
        if (!isset($_POST['bbab_portal_login'])) {
            return;
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bbab_portal_login')) {
            $this->renderLoginPage('Security verification failed. Please try again.');
            exit;
        }

        $username = sanitize_user($_POST['log'] ?? '');
        $password = $_POST['pwd'] ?? '';
        $remember = !empty($_POST['rememberme']);

        if (empty($username) || empty($password)) {
            $this->renderLoginPage('Please enter both username and password.');
            exit;
        }

        // Attempt authentication
        $user = wp_signon([
            'user_login' => $username,
            'user_password' => $password,
            'remember' => $remember,
        ], is_ssl());

        if (is_wp_error($user)) {
            Logger::warning('PortalAccessControl', 'Login failed', [
                'username' => $username,
                'error' => $user->get_error_message(),
            ]);
            $this->renderLoginPage('Invalid username or password.');
            exit;
        }

        // Login successful
        Logger::info('PortalAccessControl', 'Login successful', [
            'user_id' => $user->ID,
            'username' => $username,
        ]);

        // Set the current user for this request (wp_signon doesn't do this automatically)
        wp_set_current_user($user->ID);

        // Log org check for debugging
        $org_id = get_user_meta($user->ID, 'organization', true);
        Logger::debug('PortalAccessControl', 'Post-login org check', [
            'user_id' => $user->ID,
            'org_id' => $org_id,
            'org_id_type' => gettype($org_id),
            'is_admin' => user_can($user, 'manage_options'),
        ]);

        // Always redirect after login - let checkAccess() handle org validation on next request
        // This ensures cookies are fully set and avoids output buffering issues
        $redirect_to = $_POST['redirect_to'] ?? home_url('/client-dashboard/');
        wp_safe_redirect($redirect_to);
        exit;
    }

    /**
     * Handle failed login attempts - redirect back to portal instead of wp-login.
     */
    public function handleFailedLogin(string $username): void {
        // Only intercept if we were trying to log in from the portal
        $referrer = wp_get_referer();
        if (!$referrer) {
            return;
        }

        // Check if referrer contains our portal URL
        $portal_url = get_permalink($this->portal_page_id ?: (int) Settings::get('dashboard_page_id', 0));
        if (!$portal_url) {
            return;
        }

        // Parse both URLs to compare
        $referrer_host = wp_parse_url($referrer, PHP_URL_HOST);
        $portal_host = wp_parse_url($portal_url, PHP_URL_HOST);

        if ($referrer_host === $portal_host && strpos($referrer, 'client-dashboard') !== false) {
            Logger::debug('PortalAccessControl', 'Redirecting failed login back to portal', [
                'username' => $username,
            ]);
            wp_safe_redirect(add_query_arg('login', 'failed', $referrer));
            exit;
        }
    }

    /**
     * Render the login page.
     * This completely takes over the response - protected content is never generated.
     */
    private function renderLoginPage(string $error = ''): void {
        // Check for error from redirect
        if (empty($error) && isset($_GET['login']) && $_GET['login'] === 'failed') {
            $error = 'Invalid username or password.';
        }

        // Get logo URL from settings or use site logo
        $logo_url = Settings::get('pdf_logo_url', '');
        if (empty($logo_url)) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');
            }
        }

        // Site info
        $site_name = get_bloginfo('name');
        $current_url = home_url($_SERVER['REQUEST_URI'] ?? '/client-dashboard/');

        // Output the complete login page
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo esc_attr($site_name); ?></title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: linear-gradient(135deg, #1e3a5f 0%, #467ff7 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 40px;
            width: 100%;
            max-width: 400px;
        }

        .login-logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-logo img {
            max-width: 200px;
            max-height: 80px;
            width: auto;
            height: auto;
        }

        .login-logo h1 {
            font-size: 24px;
            color: #1e3a5f;
            margin-top: 15px;
        }

        .login-form label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }

        .login-form input[type="text"],
        .login-form input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e1e5eb;
            border-radius: 6px;
            font-size: 16px;
            margin-bottom: 20px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .login-form input[type="text"]:focus,
        .login-form input[type="password"]:focus {
            outline: none;
            border-color: #467ff7;
            box-shadow: 0 0 0 3px rgba(70, 127, 247, 0.15);
        }

        .login-form .remember-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
        }

        .login-form .remember-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #467ff7;
        }

        .login-form .remember-row label {
            margin: 0;
            font-weight: normal;
            color: #666;
        }

        .login-form button {
            width: 100%;
            padding: 14px;
            background: #467ff7;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }

        .login-form button:hover {
            background: #3366cc;
        }

        .login-form button:active {
            transform: scale(0.98);
        }

        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .login-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e1e5eb;
        }

        .login-footer a {
            color: #467ff7;
            text-decoration: none;
            font-size: 14px;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }

        .portal-title {
            text-align: center;
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
    </style>
    <?php wp_head(); ?>
</head>
<body class="bbab-portal-login">
    <div class="login-container">
        <div class="login-logo">
            <?php if ($logo_url): ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($site_name); ?>">
            <?php endif; ?>
            <h1><?php echo esc_html($site_name); ?></h1>
        </div>

        <p class="portal-title">Client Portal</p>

        <?php if ($error): ?>
            <div class="error-message">
                <?php echo esc_html($error); ?>
            </div>
        <?php endif; ?>

        <form class="login-form" method="post" action="<?php echo esc_url($current_url); ?>">
            <?php wp_nonce_field('bbab_portal_login'); ?>
            <input type="hidden" name="bbab_portal_login" value="1">
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>">

            <label for="log">Username or Email</label>
            <input type="text" name="log" id="log" autocomplete="username" required>

            <label for="pwd">Password</label>
            <input type="password" name="pwd" id="pwd" autocomplete="current-password" required>

            <div class="remember-row">
                <input type="checkbox" name="rememberme" id="rememberme" value="forever">
                <label for="rememberme">Remember me</label>
            </div>

            <button type="submit">Sign In</button>
        </form>

        <div class="login-footer">
            <a href="<?php echo esc_url(wp_lostpassword_url($current_url)); ?>">Forgot your password?</a>
        </div>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
        <?php
    }

    /**
     * Render access denied page.
     */
    private function renderAccessDenied(string $message): void {
        // Direct error_log for debugging (bypasses debug mode setting)
        error_log('[BBAB-SC] renderAccessDenied() called with message: ' . $message);

        Logger::debug('PortalAccessControl', 'Rendering access denied page', ['message' => $message]);

        // Clean any output buffers to ensure clean render
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Set proper headers
        if (!headers_sent()) {
            status_header(403);
            nocache_headers();
        }

        $site_name = get_bloginfo('name');

        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - <?php echo esc_attr($site_name); ?></title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: linear-gradient(135deg, #1e3a5f 0%, #467ff7 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .access-denied {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 40px;
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        .access-denied h1 {
            color: #dc2626;
            font-size: 24px;
            margin-bottom: 16px;
        }

        .access-denied p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .access-denied a {
            display: inline-block;
            background: #467ff7;
            color: #fff;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
        }

        .access-denied a:hover {
            background: #3366cc;
        }
    </style>
</head>
<body>
    <div class="access-denied">
        <h1>Access Denied</h1>
        <p><?php echo esc_html($message); ?></p>
        <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>">Logout and Try Again</a>
    </div>
</body>
</html>
        <?php
    }
}
