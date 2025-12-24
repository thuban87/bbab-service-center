<?php
/**
 * Projects Sub-Page Template
 *
 * Variables available:
 * - $stats           Summary statistics array
 * - $organizations   Array of organizations for filter
 * - $current_status  Currently selected status filter
 * - $current_org     Currently selected organization filter
 *
 * @package BBAB\Core\Admin
 * @since   1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

$statuses = array(
    ''                  => __( 'All Active', 'bbab-core' ),
    'Active'            => __( 'Active', 'bbab-core' ),
    'Waiting on Client' => __( 'Waiting on Client', 'bbab-core' ),
    'On Hold'           => __( 'On Hold', 'bbab-core' ),
    'Completed'         => __( 'Completed', 'bbab-core' ),
    'Cancelled'         => __( 'Cancelled', 'bbab-core' ),
);

$current_month = date_i18n( 'F' );
?>
<div class="wrap bbab-workbench-wrap">
    <div class="bbab-workbench-header">
        <h1>
            <span class="dashicons dashicons-portfolio"></span>
            <?php esc_html_e( 'Projects', 'bbab-core' ); ?>
        </h1>
        <p class="bbab-text-muted">
            <?php esc_html_e( 'View and manage all projects.', 'bbab-core' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bbab-workbench' ) ); ?>">
                &larr; <?php esc_html_e( 'Back to Workbench', 'bbab-core' ); ?>
            </a>
        </p>
    </div>

    <!-- Summary Stats Bar -->
    <div class="bbab-stats-bar">
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html( $stats['total_active'] ); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e( 'Active', 'bbab-core' ); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html( $stats['total_waiting'] ); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e( 'Waiting', 'bbab-core' ); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html( $stats['total_on_hold'] ); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e( 'On Hold', 'bbab-core' ); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html( $stats['completed_this_month'] ); ?></span>
            <span class="bbab-stat-label"><?php echo esc_html( sprintf( __( 'Completed in %s', 'bbab-core' ), $current_month ) ); ?></span>
        </div>
        <div class="bbab-stat-box bbab-stat-divider">
            <span class="bbab-stat-number"><?php echo esc_html( number_format( $stats['hours_this_month'], 1 ) ); ?></span>
            <span class="bbab-stat-label"><?php echo esc_html( sprintf( __( 'Hours in %s', 'bbab-core' ), $current_month ) ); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number">$<?php echo esc_html( number_format( $stats['budget_this_month'], 0 ) ); ?></span>
            <span class="bbab-stat-label"><?php echo esc_html( sprintf( __( 'Budgeted in %s', 'bbab-core' ), $current_month ) ); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number">$<?php echo esc_html( number_format( $stats['invoiced_this_month'], 0 ) ); ?></span>
            <span class="bbab-stat-label"><?php echo esc_html( sprintf( __( 'Invoiced in %s', 'bbab-core' ), $current_month ) ); ?></span>
        </div>
    </div>

    <!-- Filters -->
    <div class="bbab-filters-bar">
        <form method="get" action="">
            <input type="hidden" name="page" value="bbab-projects" />

            <!-- Status Filter Pills -->
            <div class="bbab-filter-group">
                <label class="bbab-filter-label"><?php esc_html_e( 'Status:', 'bbab-core' ); ?></label>
                <div class="bbab-status-pills">
                    <?php foreach ( $statuses as $value => $label ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( array(
                            'page'           => 'bbab-projects',
                            'project_status' => $value,
                            'organization'   => $current_org ?: null,
                        ), admin_url( 'admin.php' ) ) ); ?>"
                           class="bbab-status-pill <?php echo $current_status === $value ? 'active' : ''; ?>">
                            <?php echo esc_html( $label ); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Organization Filter -->
            <div class="bbab-filter-group">
                <label class="bbab-filter-label" for="organization"><?php esc_html_e( 'Client:', 'bbab-core' ); ?></label>
                <select name="organization" id="organization" class="bbab-client-select" onchange="this.form.submit()">
                    <option value=""><?php esc_html_e( 'All Clients', 'bbab-core' ); ?></option>
                    <?php foreach ( $organizations as $org ) : ?>
                        <option value="<?php echo esc_attr( $org['id'] ); ?>" <?php selected( $current_org, $org['id'] ); ?>>
                            <?php echo esc_html( $org['shortcode'] ? $org['shortcode'] . ' - ' . $org['name'] : $org['name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Search -->
            <div class="bbab-filter-group bbab-filter-search">
                <?php $this->list_table->search_box( __( 'Search Projects', 'bbab-core' ), 'project' ); ?>
            </div>
        </form>
    </div>

    <!-- Projects Table -->
    <div class="bbab-list-table-wrap">
        <form method="get" action="">
            <input type="hidden" name="page" value="bbab-projects" />
            <?php if ( $current_status ) : ?>
                <input type="hidden" name="project_status" value="<?php echo esc_attr( $current_status ); ?>" />
            <?php endif; ?>
            <?php if ( $current_org ) : ?>
                <input type="hidden" name="organization" value="<?php echo esc_attr( $current_org ); ?>" />
            <?php endif; ?>

            <?php $this->list_table->display(); ?>
        </form>
    </div>

    <!-- Quick Actions -->
    <div class="bbab-quick-actions">
        <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=project' ) ); ?>" class="button button-primary">
            <span class="dashicons dashicons-plus-alt2"></span>
            <?php esc_html_e( 'New Project', 'bbab-core' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=project' ) ); ?>" class="button">
            <?php esc_html_e( 'Native WP List', 'bbab-core' ); ?>
        </a>
    </div>

</div><!-- .bbab-workbench-wrap -->
