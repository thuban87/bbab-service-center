<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Dashboard;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Utils\Settings;

/**
 * Support Request Button shortcode.
 *
 * Displays a styled button linking to the support request form.
 * Matches the styling of the KB link button.
 *
 * Shortcode: [dashboard_sr_form_link]
 *
 * Migrated from: WPCode Snippet #2301
 */
class SupportButton extends BaseShortcode {

    protected string $tag = 'dashboard_sr_form_link';

    /**
     * This shortcode doesn't strictly require org - just login.
     */
    protected bool $requires_org = false;

    /**
     * Render the support button output.
     */
    protected function output(array $atts, int $org_id): string {
        $form_url = Settings::get('support_request_form_url', '/support-request-form/');

        ob_start();
        ?>
        <div class="dashboard-sr-link">
            <a href="<?php echo esc_url($form_url); ?>" class="sr-form-btn">
                Submit Support Request
            </a>
        </div>
        <style>
            .dashboard-sr-link {
                margin: 24px 0;
                text-align: center;
            }
            .sr-form-btn {
                display: inline-block;
                font-family: 'Poppins', sans-serif;
                font-size: 16px;
                font-weight: 500;
                color: white !important;
                background: #467FF7;
                padding: 12px 24px;
                border-radius: 8px;
                text-decoration: none;
                transition: background 0.2s;
            }
            .sr-form-btn:hover {
                background: #3366cc;
                color: white !important;
            }
        </style>
        <?php
        return ob_get_clean();
    }
}
