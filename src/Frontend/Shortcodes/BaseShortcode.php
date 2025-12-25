<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes;

use BBAB\ServiceCenter\Utils\UserContext;
use BBAB\ServiceCenter\Utils\ClientError;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Abstract base class for all shortcodes.
 *
 * Provides:
 * - Simulation-aware organization context
 * - Consistent error handling
 * - Common render pattern
 *
 * Usage:
 *   class MyShortcode extends BaseShortcode {
 *       protected string $tag = 'my_shortcode';
 *
 *       protected function output(array $atts, int $org_id): string {
 *           // Your shortcode logic here
 *           return '<div>Hello from org ' . $org_id . '</div>';
 *       }
 *   }
 */
abstract class BaseShortcode {

    /**
     * The shortcode tag (e.g., 'dashboard_overview').
     * Must be set by child classes.
     */
    protected string $tag = '';

    /**
     * Whether this shortcode requires an organization context.
     * Set to false for public shortcodes.
     */
    protected bool $requires_org = true;

    /**
     * Whether this shortcode requires the user to be logged in.
     */
    protected bool $requires_login = true;

    /**
     * Cached organization ID for the current request.
     */
    private ?int $org_id = null;

    /**
     * Register the shortcode.
     */
    public function register(): void {
        if (empty($this->tag)) {
            Logger::error('Shortcode', 'Shortcode tag not set for class: ' . static::class);
            return;
        }

        add_shortcode($this->tag, [$this, 'render']);
    }

    /**
     * Get the shortcode tag.
     */
    public function getTag(): string {
        return $this->tag;
    }

    /**
     * Get the organization ID (simulation-aware).
     */
    protected function getOrgId(): ?int {
        if ($this->org_id === null) {
            $this->org_id = UserContext::getCurrentOrgId();
        }
        return $this->org_id;
    }

    /**
     * Get the organization post object.
     */
    protected function getOrg(): ?\WP_Post {
        return UserContext::getCurrentOrg();
    }

    /**
     * Main render method - handles all the common checks.
     *
     * @param array|string $atts Shortcode attributes
     * @return string Rendered HTML
     */
    public function render($atts = []): string {
        // Normalize attributes
        if (!is_array($atts)) {
            $atts = [];
        }

        try {
            // Check login requirement
            if ($this->requires_login && !is_user_logged_in()) {
                return $this->renderLoginRequired();
            }

            // Check organization requirement
            $org_id = $this->getOrgId();
            if ($this->requires_org && !$org_id) {
                return $this->renderNoOrg();
            }

            // Call child implementation
            return $this->output($atts, $org_id ?? 0);

        } catch (\Exception $e) {
            Logger::error('Shortcode', 'Render failed for [' . $this->tag . ']', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ClientError::generate('Shortcode render failed: ' . $e->getMessage());
        }
    }

    /**
     * Child classes must implement this method.
     *
     * @param array $atts   Shortcode attributes
     * @param int   $org_id Organization ID (may be 0 if requires_org is false)
     * @return string Rendered HTML
     */
    abstract protected function output(array $atts, int $org_id): string;

    /**
     * Render message when login is required.
     */
    protected function renderLoginRequired(): string {
        return '<div class="bbab-notice bbab-notice-info">
            <p>Please <a href="' . esc_url(wp_login_url(get_permalink())) . '">log in</a> to view this content.</p>
        </div>';
    }

    /**
     * Render message when no organization is found.
     */
    protected function renderNoOrg(): string {
        if (!is_user_logged_in()) {
            return $this->renderLoginRequired();
        }

        return '<div class="bbab-notice bbab-notice-warning">
            <p>No organization is associated with your account. Please contact support.</p>
        </div>';
    }

    /**
     * Check if currently in simulation mode.
     */
    protected function isSimulating(): bool {
        return UserContext::isSimulationActive();
    }

    /**
     * Parse shortcode attributes with defaults.
     *
     * @param array $atts     Provided attributes
     * @param array $defaults Default values
     * @return array Merged attributes
     */
    protected function parseAtts(array $atts, array $defaults): array {
        return shortcode_atts($defaults, $atts, $this->tag);
    }
}
