<?php
/**
 * Main Workbench Dashboard Template
 *
 * Variables available:
 * - $service_requests    Array of open service request posts
 * - $projects            Array of active project posts
 * - $invoices            Array of pending invoice posts
 * - $sr_total_count      Total count of open service requests
 * - $project_total_count Total count of active projects
 * - $invoice_total_count Total count of pending invoices
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
                <span class="bbab-box-count <?php echo $sr_total_count === 0 ? 'count-zero' : ''; ?>">
                    <?php echo esc_html( $sr_total_count ); ?>
                </span>
            </div>
            <div class="bbab-box-content">
                <?php if ( empty( $service_requests ) ) : ?>
                    <div class="bbab-empty-state">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <p><?php esc_html_e( 'No open service requests. Nice work!', 'bbab-core' ); ?></p>
                    </div>
                <?php else : ?>
                    <ul class="bbab-item-list">
                        <?php foreach ( $service_requests as $sr ) :
                            $ref_number = get_post_meta( $sr->ID, 'reference_number', true );
                            $status     = get_post_meta( $sr->ID, 'request_status', true );
                            $subject    = get_post_meta( $sr->ID, 'subject', true );
                            $org_code   = $this->get_org_shortcode( $sr->ID );
                            $te_count   = $this->get_sr_time_entry_count( $sr->ID );
                            $edit_link  = $this->get_edit_link( $sr->ID );

                            // Truncate subject if too long.
                            $subject_display = mb_strlen( $subject ) > 40 ? mb_substr( $subject, 0, 40 ) . '...' : $subject;
                        ?>
                            <li class="bbab-item">
                                <span class="bbab-item-ref"><?php echo esc_html( $ref_number ); ?></span>
                                <span class="bbab-item-title">
                                    <a href="<?php echo esc_url( $edit_link ); ?>" title="<?php echo esc_attr( $subject ); ?>">
                                        <?php echo esc_html( $subject_display ); ?>
                                    </a>
                                </span>
                                <?php if ( $org_code ) : ?>
                                    <span class="bbab-item-org"><?php echo esc_html( $org_code ); ?></span>
                                <?php endif; ?>
                                <span class="bbab-item-meta">
                                    <?php if ( $te_count > 0 ) : ?>
                                        <a href="<?php echo esc_url( $this->get_time_entries_by_sr_url( $sr->ID ) ); ?>" class="bbab-te-count" title="<?php esc_attr_e( 'View Time Entries', 'bbab-core' ); ?>">
                                            <span class="dashicons dashicons-clock"></span><?php echo esc_html( $te_count ); ?>
                                        </a>
                                    <?php endif; ?>
                                </span>
                                <?php echo $this->render_status_badge( $status, 'sr' ); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="bbab-box-footer">
                <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=service_request' ) ); ?>" class="button">
                    <?php esc_html_e( 'View All', 'bbab-core' ); ?>
                </a>
            </div>
        </div>

        <!-- Projects Box -->
        <div class="bbab-box" data-box-type="projects">
            <div class="bbab-box-header">
                <h2 class="bbab-box-title">
                    <span class="dashicons dashicons-portfolio"></span>
                    <?php esc_html_e( 'Active Projects', 'bbab-core' ); ?>
                </h2>
                <span class="bbab-box-count <?php echo $project_total_count === 0 ? 'count-zero' : ''; ?>">
                    <?php echo esc_html( $project_total_count ); ?>
                </span>
            </div>
            <div class="bbab-box-content">
                <?php if ( empty( $projects ) ) : ?>
                    <div class="bbab-empty-state">
                        <span class="dashicons dashicons-portfolio"></span>
                        <p><?php esc_html_e( 'No active projects at the moment.', 'bbab-core' ); ?></p>
                    </div>
                <?php else : ?>
                    <ul class="bbab-item-list">
                        <?php foreach ( $projects as $project ) :
                            $ref_number      = get_post_meta( $project->ID, 'reference_number', true );
                            $status          = get_post_meta( $project->ID, 'project_status', true );
                            $project_name    = get_post_meta( $project->ID, 'project_name', true );
                            $org_code        = $this->get_org_shortcode( $project->ID );
                            $milestone_count = $this->get_project_milestone_count( $project->ID );
                            $te_count        = $this->get_project_time_entry_count( $project->ID );
                            $edit_link       = $this->get_edit_link( $project->ID );

                            // Use project_name if set, otherwise post title.
                            $display_name = ! empty( $project_name ) ? $project_name : $project->post_title;
                            $name_display = mb_strlen( $display_name ) > 35 ? mb_substr( $display_name, 0, 35 ) . '...' : $display_name;
                        ?>
                            <li class="bbab-item">
                                <span class="bbab-item-ref"><?php echo esc_html( $ref_number ); ?></span>
                                <span class="bbab-item-title">
                                    <a href="<?php echo esc_url( $edit_link ); ?>" title="<?php echo esc_attr( $display_name ); ?>">
                                        <?php echo esc_html( $name_display ); ?>
                                    </a>
                                </span>
                                <?php if ( $org_code ) : ?>
                                    <span class="bbab-item-org"><?php echo esc_html( $org_code ); ?></span>
                                <?php endif; ?>
                                <span class="bbab-item-meta">
                                    <?php if ( $milestone_count > 0 ) : ?>
                                        <a href="<?php echo esc_url( $this->get_milestones_by_project_url( $project->ID ) ); ?>" class="bbab-milestone-count" title="<?php esc_attr_e( 'View Milestones', 'bbab-core' ); ?>">
                                            <span class="dashicons dashicons-flag"></span><?php echo esc_html( $milestone_count ); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ( $te_count > 0 ) : ?>
                                        <a href="<?php echo esc_url( $this->get_time_entries_by_project_url( $project->ID ) ); ?>" class="bbab-te-count" title="<?php esc_attr_e( 'View Time Entries', 'bbab-core' ); ?>">
                                            <span class="dashicons dashicons-clock"></span><?php echo esc_html( $te_count ); ?>
                                        </a>
                                    <?php endif; ?>
                                </span>
                                <?php echo $this->render_status_badge( $status, 'project' ); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="bbab-box-footer">
                <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=project' ) ); ?>" class="button">
                    <?php esc_html_e( 'View All', 'bbab-core' ); ?>
                </a>
            </div>
        </div>

        <!-- Invoices Box -->
        <div class="bbab-box" data-box-type="invoices">
            <div class="bbab-box-header">
                <h2 class="bbab-box-title">
                    <span class="dashicons dashicons-media-text"></span>
                    <?php esc_html_e( 'Pending Invoices', 'bbab-core' ); ?>
                </h2>
                <span class="bbab-box-count <?php echo $invoice_total_count === 0 ? 'count-zero' : ''; ?>">
                    <?php echo esc_html( $invoice_total_count ); ?>
                </span>
            </div>
            <div class="bbab-box-content">
                <?php if ( empty( $invoices ) ) : ?>
                    <div class="bbab-empty-state">
                        <span class="dashicons dashicons-money-alt"></span>
                        <p><?php esc_html_e( 'All invoices are paid. Cash flow looking good!', 'bbab-core' ); ?></p>
                    </div>
                <?php else : ?>
                    <ul class="bbab-item-list">
                        <?php foreach ( $invoices as $invoice ) :
                            $inv_number = get_post_meta( $invoice->ID, 'invoice_number', true );
                            $status     = get_post_meta( $invoice->ID, 'invoice_status', true );
                            $amount     = get_post_meta( $invoice->ID, 'amount', true );
                            $due_date   = get_post_meta( $invoice->ID, 'due_date', true );
                            $org_code   = $this->get_org_shortcode( $invoice->ID );
                            $edit_link  = $this->get_edit_link( $invoice->ID );

                            // Format due date.
                            $due_display = '';
                            if ( $due_date ) {
                                $due_timestamp = strtotime( $due_date );
                                $due_display = date_i18n( 'M j', $due_timestamp );

                                // Check if overdue.
                                if ( $due_timestamp < time() && $status !== 'Paid' ) {
                                    $days_overdue = floor( ( time() - $due_timestamp ) / DAY_IN_SECONDS );
                                    $due_display .= ' (' . $days_overdue . 'd overdue)';
                                }
                            }
                        ?>
                            <li class="bbab-item">
                                <span class="bbab-item-ref">
                                    <a href="<?php echo esc_url( $edit_link ); ?>">
                                        <?php echo esc_html( $inv_number ); ?>
                                    </a>
                                </span>
                                <span class="bbab-item-title bbab-item-amount">
                                    <?php echo esc_html( $this->format_currency( $amount ) ); ?>
                                </span>
                                <?php if ( $org_code ) : ?>
                                    <span class="bbab-item-org"><?php echo esc_html( $org_code ); ?></span>
                                <?php endif; ?>
                                <span class="bbab-item-meta">
                                    <?php if ( $due_display ) : ?>
                                        <span class="bbab-due-date" title="<?php esc_attr_e( 'Due Date', 'bbab-core' ); ?>">
                                            <?php echo esc_html( $due_display ); ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <?php echo $this->render_status_badge( $status, 'invoice' ); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="bbab-box-footer">
                <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=invoice' ) ); ?>" class="button">
                    <?php esc_html_e( 'View All', 'bbab-core' ); ?>
                </a>
            </div>
        </div>

    </div><!-- .bbab-workbench-grid -->

</div><!-- .bbab-workbench-wrap -->
