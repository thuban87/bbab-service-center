<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend;

use BBAB\ServiceCenter\Utils\UserContext;
use BBAB\ServiceCenter\Core\SimulationBootstrap;

/**
 * Frontend simulation indicator bar.
 *
 * Displays a sticky bar at the top of the page when an admin
 * is viewing the site as a simulated organization.
 */
class SimulationBar {

    /**
     * Register hooks.
     */
    public function register(): void {
        // Only for admins in simulation mode
        if (!UserContext::isSimulationActive()) {
            return;
        }

        // Add the bar to the page
        add_action('wp_footer', [$this, 'render']);

        // Add inline styles (so it works even if CSS file is missing)
        add_action('wp_head', [$this, 'inlineStyles']);
    }

    /**
     * Render the simulation bar.
     */
    public function render(): void {
        if (!UserContext::isSimulationActive()) {
            return;
        }

        $org = UserContext::getCurrentOrg();
        $org_name = $org ? $org->post_title : 'Unknown Organization';
        $org_id = UserContext::getCurrentOrgId();
        $shortcode = get_post_meta($org_id, 'shortcode', true) ?: get_post_meta($org_id, 'organization_shortcode', true);

        // Build exit URL
        $exit_url = add_query_arg([
            'bbab_sc_exit_simulation' => '1',
            '_wpnonce' => wp_create_nonce('bbab_sc_simulation'),
        ], home_url());

        // Build workbench URL
        $workbench_url = admin_url('admin.php?page=bbab-workbench');

        ?>
        <div id="bbab-simulation-bar">
            <div class="bbab-sim-bar-content">
                <span class="bbab-sim-bar-icon">👁️</span>
                <span class="bbab-sim-bar-text">
                    <strong>Simulation Mode:</strong>
                    Viewing as <strong><?php echo esc_html($org_name); ?></strong>
                    <?php if ($shortcode): ?>
                        <span class="bbab-sim-bar-shortcode">(<?php echo esc_html($shortcode); ?>)</span>
                    <?php endif; ?>
                </span>
                <div class="bbab-sim-bar-actions">
                    <a href="<?php echo esc_url($workbench_url); ?>" class="bbab-sim-bar-btn bbab-sim-bar-btn-secondary">
                        ← Workbench
                    </a>
                    <a href="<?php echo esc_url($exit_url); ?>" class="bbab-sim-bar-btn bbab-sim-bar-btn-primary">
                        Exit Simulation
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Output inline styles for the simulation bar.
     */
    public function inlineStyles(): void {
        if (!UserContext::isSimulationActive()) {
            return;
        }
        ?>
        <style id="bbab-simulation-bar-styles">
            #bbab-simulation-bar {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                z-index: 99999;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                padding: 8px 16px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                font-size: 14px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            }

            /* Push down the admin bar and page content */
            body.admin-bar #bbab-simulation-bar {
                top: 32px;
            }

            @media screen and (max-width: 782px) {
                body.admin-bar #bbab-simulation-bar {
                    top: 46px;
                }
            }

            /* Push down page content */
            body.bbab-simulating {
                padding-top: 48px !important;
            }

            .bbab-sim-bar-content {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
                max-width: 1200px;
                margin: 0 auto;
                flex-wrap: wrap;
            }

            .bbab-sim-bar-icon {
                font-size: 18px;
            }

            .bbab-sim-bar-text {
                flex: 1;
                text-align: center;
            }

            .bbab-sim-bar-shortcode {
                opacity: 0.8;
                font-size: 12px;
            }

            .bbab-sim-bar-actions {
                display: flex;
                gap: 8px;
            }

            .bbab-sim-bar-btn {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 4px;
                text-decoration: none;
                font-size: 12px;
                font-weight: 500;
                transition: all 0.2s ease;
            }

            .bbab-sim-bar-btn-primary {
                background: #fff;
                color: #764ba2;
            }

            .bbab-sim-bar-btn-primary:hover {
                background: #f0f0f0;
                color: #5a3a7e;
            }

            .bbab-sim-bar-btn-secondary {
                background: rgba(255,255,255,0.2);
                color: #fff;
            }

            .bbab-sim-bar-btn-secondary:hover {
                background: rgba(255,255,255,0.3);
            }

            @media screen and (max-width: 600px) {
                .bbab-sim-bar-content {
                    flex-direction: column;
                    gap: 8px;
                }

                .bbab-sim-bar-text {
                    font-size: 12px;
                }
            }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                document.body.classList.add('bbab-simulating');
            });
        </script>
        <?php
    }
}
