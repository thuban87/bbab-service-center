<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin;

use BBAB\ServiceCenter\Utils\UserContext;

/**
 * Admin simulation indicator bar.
 *
 * Displays a sticky bar at the top of the WordPress admin when
 * an admin is viewing as a simulated organization.
 */
class AdminSimulationBar {

    /**
     * Register hooks.
     */
    public static function register(): void {
        // Only for admins in simulation mode
        if (!UserContext::isSimulationActive()) {
            return;
        }

        // Add inline styles
        add_action('admin_head', [self::class, 'inlineStyles']);

        // Add the bar to admin footer
        add_action('admin_footer', [self::class, 'render']);
    }

    /**
     * Render the simulation bar.
     */
    public static function render(): void {
        if (!UserContext::isSimulationActive()) {
            return;
        }

        $org = UserContext::getCurrentOrg();
        $org_name = $org ? $org->post_title : 'Unknown Organization';
        $org_id = UserContext::getCurrentOrgId();
        $shortcode = get_post_meta($org_id, 'shortcode', true) ?: get_post_meta($org_id, 'organization_shortcode', true);

        // Build exit URL (returns to current admin page)
        $exit_url = add_query_arg([
            'bbab_sc_exit_simulation' => '1',
            '_wpnonce' => wp_create_nonce('bbab_sc_simulation'),
        ]);

        // Build workbench URL
        $workbench_url = admin_url('admin.php?page=bbab-workbench');

        // Build org edit URL
        $org_edit_url = get_edit_post_link($org_id);

        ?>
        <div id="bbab-admin-simulation-bar">
            <div class="bbab-admin-sim-content">
                <span class="bbab-admin-sim-icon">&#128065;</span>
                <span class="bbab-admin-sim-text">
                    <strong>Simulation Mode:</strong>
                    Viewing as <a href="<?php echo esc_url($org_edit_url); ?>" class="bbab-admin-sim-org-link"><?php echo esc_html($org_name); ?></a>
                    <?php if ($shortcode): ?>
                        <span class="bbab-admin-sim-shortcode">(<?php echo esc_html($shortcode); ?>)</span>
                    <?php endif; ?>
                </span>
                <div class="bbab-admin-sim-actions">
                    <a href="<?php echo esc_url($workbench_url); ?>" class="bbab-admin-sim-btn bbab-admin-sim-btn-secondary">
                        Workbench
                    </a>
                    <a href="<?php echo esc_url($exit_url); ?>" class="bbab-admin-sim-btn bbab-admin-sim-btn-primary">
                        Exit Simulation
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Output inline styles for the admin simulation bar.
     */
    public static function inlineStyles(): void {
        if (!UserContext::isSimulationActive()) {
            return;
        }
        ?>
        <style id="bbab-admin-simulation-bar-styles">
            #bbab-admin-simulation-bar {
                position: fixed;
                top: 32px; /* Below WordPress admin bar */
                left: 0;
                right: 0;
                z-index: 99998;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                padding: 6px 16px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                font-size: 13px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            }

            @media screen and (max-width: 782px) {
                #bbab-admin-simulation-bar {
                    top: 46px;
                }
            }

            /* Push down the admin content area */
            #wpcontent {
                padding-top: 40px !important;
            }

            .bbab-admin-sim-content {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
                max-width: 1200px;
                margin: 0 auto;
                flex-wrap: wrap;
            }

            .bbab-admin-sim-icon {
                font-size: 16px;
            }

            .bbab-admin-sim-text {
                text-align: center;
            }

            .bbab-admin-sim-org-link {
                color: #fff;
                text-decoration: underline;
            }

            .bbab-admin-sim-org-link:hover {
                color: #f0f0f0;
            }

            .bbab-admin-sim-shortcode {
                opacity: 0.8;
                font-size: 11px;
            }

            .bbab-admin-sim-actions {
                display: flex;
                gap: 8px;
            }

            .bbab-admin-sim-btn {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 3px;
                text-decoration: none;
                font-size: 11px;
                font-weight: 500;
                transition: all 0.2s ease;
            }

            .bbab-admin-sim-btn-primary {
                background: #fff;
                color: #764ba2;
            }

            .bbab-admin-sim-btn-primary:hover {
                background: #f0f0f0;
                color: #5a3a7e;
            }

            .bbab-admin-sim-btn-secondary {
                background: rgba(255,255,255,0.2);
                color: #fff;
            }

            .bbab-admin-sim-btn-secondary:hover {
                background: rgba(255,255,255,0.3);
                color: #fff;
            }

            @media screen and (max-width: 600px) {
                .bbab-admin-sim-content {
                    flex-direction: column;
                    gap: 6px;
                }

                .bbab-admin-sim-text {
                    font-size: 11px;
                }
            }
        </style>
        <?php
    }
}
