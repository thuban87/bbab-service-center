<?php
/**
 * Main Workbench Dashboard Template
 *
 * @package BBAB\Core\Admin
 * @since   1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}
?>
<div class="wrap bbab-workbench-wrap">
    <div class="bbab-workbench-header">
        <h1>
            <span class="dashicons dashicons-desktop"></span>
            <?php esc_html_e( "Brad's Workbench", 'bbab-core' ); ?>
        </h1>
        <p class="bbab-text-muted">
            <?php esc_html_e( 'Your command center for the BBAB Service Center.', 'bbab-core' ); ?>
        </p>
    </div>

    <div class="bbab-workbench-grid">

        <!-- Service Requests Box -->
        <div class="bbab-box" data-box-type="service-requests">
            <div class="bbab-box-header">
                <h2 class="bbab-box-title">
                    <span class="dashicons dashicons-sos"></span>
                    <?php esc_html_e( 'Open Service Requests', 'bbab-core' ); ?>
                </h2>
                <span class="bbab-box-count count-zero">0</span>
            </div>
            <div class="bbab-box-content">
                <div class="bbab-empty-state">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <p><?php esc_html_e( 'No open service requests. Nice work!', 'bbab-core' ); ?></p>
                </div>
            </div>
            <div class="bbab-box-footer">
                <a href="#" class="button"><?php esc_html_e( 'View All', 'bbab-core' ); ?></a>
            </div>
        </div>

        <!-- Projects Box -->
        <div class="bbab-box" data-box-type="projects">
            <div class="bbab-box-header">
                <h2 class="bbab-box-title">
                    <span class="dashicons dashicons-portfolio"></span>
                    <?php esc_html_e( 'Active Projects', 'bbab-core' ); ?>
                </h2>
                <span class="bbab-box-count count-zero">0</span>
            </div>
            <div class="bbab-box-content">
                <div class="bbab-empty-state">
                    <span class="dashicons dashicons-portfolio"></span>
                    <p><?php esc_html_e( 'No active projects at the moment.', 'bbab-core' ); ?></p>
                </div>
            </div>
            <div class="bbab-box-footer">
                <a href="#" class="button"><?php esc_html_e( 'View All', 'bbab-core' ); ?></a>
            </div>
        </div>

        <!-- Invoices Box -->
        <div class="bbab-box" data-box-type="invoices">
            <div class="bbab-box-header">
                <h2 class="bbab-box-title">
                    <span class="dashicons dashicons-media-text"></span>
                    <?php esc_html_e( 'Pending Invoices', 'bbab-core' ); ?>
                </h2>
                <span class="bbab-box-count count-zero">0</span>
            </div>
            <div class="bbab-box-content">
                <div class="bbab-empty-state">
                    <span class="dashicons dashicons-money-alt"></span>
                    <p><?php esc_html_e( 'All invoices are paid. Cash flow looking good!', 'bbab-core' ); ?></p>
                </div>
            </div>
            <div class="bbab-box-footer">
                <a href="#" class="button"><?php esc_html_e( 'View All', 'bbab-core' ); ?></a>
            </div>
        </div>

    </div><!-- .bbab-workbench-grid -->

</div><!-- .bbab-workbench-wrap -->
