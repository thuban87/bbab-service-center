<?php
/**
 * Brad's Workbench - Invoices Sub-Page.
 *
 * @package BBAB\Core\Admin
 * @since   1.0.0
 */

namespace BBAB\Core\Admin;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Load WP_List_Table if not already loaded.
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Workbench_Invoices
 *
 * Handles the Invoices sub-page with enhanced list table.
 *
 * @since 1.0.0
 */
class Workbench_Invoices {

    /**
     * Cache instance.
     *
     * @var Cache
     */
    private $cache;

    /**
     * The list table instance.
     *
     * @var Invoices_List_Table
     */
    private $list_table;

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->cache = new Cache();
    }

    /**
     * Render the invoices page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_page() {
        // Security check.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bbab-core' ) );
        }

        // Initialize the list table.
        $this->list_table = new Invoices_List_Table();
        $this->list_table->prepare_items();

        // Get summary stats.
        $stats = $this->get_summary_stats();

        // Get organizations for filter.
        $organizations = $this->get_organizations();

        // Current filters.
        $current_status = isset( $_GET['invoice_status'] ) ? sanitize_text_field( $_GET['invoice_status'] ) : '';
        $current_org    = isset( $_GET['organization'] ) ? absint( $_GET['organization'] ) : 0;

        // Load template.
        include BBAB_CORE_PATH . 'admin/partials/workbench-invoices.php';
    }

    /**
     * Get summary statistics for invoices.
     *
     * @since 1.0.0
     * @return array
     */
    private function get_summary_stats() {
        $cache_key = 'invoices_summary_stats_monthly';
        $cached    = $this->cache->get( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        // Current month boundaries.
        $month_start = date( 'Y-m-01 00:00:00' );
        $month_end   = date( 'Y-m-t 23:59:59' );

        // Get all invoices.
        $all_invoices = get_posts( array(
            'post_type'      => 'invoice',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ) );

        $stats = array(
            'total_draft'        => 0,
            'total_pending'      => 0,
            'total_partial'      => 0,
            'total_overdue'      => 0,
            'outstanding_amount' => 0,
            'paid_this_month'    => 0,
            'invoiced_this_month' => 0,
        );

        $today = strtotime( 'today' );

        // Count statuses and outstanding.
        foreach ( $all_invoices as $invoice ) {
            $status   = get_post_meta( $invoice->ID, 'invoice_status', true );
            $amount   = (float) get_post_meta( $invoice->ID, 'amount', true );
            $paid     = (float) get_post_meta( $invoice->ID, 'amount_paid', true );
            $due_date = get_post_meta( $invoice->ID, 'due_date', true );

            switch ( $status ) {
                case 'Draft':
                    $stats['total_draft']++;
                    break;
                case 'Pending':
                    $stats['total_pending']++;
                    $stats['outstanding_amount'] += ( $amount - $paid );
                    break;
                case 'Partial':
                    $stats['total_partial']++;
                    $stats['outstanding_amount'] += ( $amount - $paid );
                    break;
                case 'Overdue':
                    $stats['total_overdue']++;
                    $stats['outstanding_amount'] += ( $amount - $paid );
                    break;
            }

            // Check if pending/partial is actually overdue.
            if ( in_array( $status, array( 'Pending', 'Partial' ), true ) && ! empty( $due_date ) ) {
                if ( strtotime( $due_date ) < $today ) {
                    $stats['total_overdue']++;
                    // Subtract from pending/partial since it's actually overdue.
                    if ( $status === 'Pending' ) {
                        $stats['total_pending']--;
                    } else {
                        $stats['total_partial']--;
                    }
                }
            }
        }

        // Paid this month - invoices marked as Paid with payment this month.
        $paid_invoices = get_posts( array(
            'post_type'      => 'invoice',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'date_query'     => array(
                array(
                    'column'    => 'post_modified',
                    'after'     => $month_start,
                    'before'    => $month_end,
                    'inclusive' => true,
                ),
            ),
            'meta_query'     => array(
                array(
                    'key'     => 'invoice_status',
                    'value'   => 'Paid',
                    'compare' => '=',
                ),
            ),
        ) );

        foreach ( $paid_invoices as $invoice ) {
            $stats['paid_this_month'] += (float) get_post_meta( $invoice->ID, 'amount', true );
        }

        // Invoiced this month - invoices created this month.
        $month_invoices = get_posts( array(
            'post_type'      => 'invoice',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'date_query'     => array(
                array(
                    'after'     => $month_start,
                    'before'    => $month_end,
                    'inclusive' => true,
                ),
            ),
        ) );

        foreach ( $month_invoices as $invoice ) {
            $stats['invoiced_this_month'] += (float) get_post_meta( $invoice->ID, 'amount', true );
        }

        $this->cache->set( $cache_key, $stats, HOUR_IN_SECONDS );

        return $stats;
    }

    /**
     * Get all organizations for filter dropdown.
     *
     * @since 1.0.0
     * @return array
     */
    private function get_organizations() {
        $orgs = get_posts( array(
            'post_type'      => 'client_organization',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        $result = array();
        foreach ( $orgs as $org ) {
            $shortcode = get_post_meta( $org->ID, 'organization_shortcode', true );
            $result[] = array(
                'id'        => $org->ID,
                'name'      => $org->post_title,
                'shortcode' => $shortcode,
            );
        }

        return $result;
    }
}

/**
 * Class Invoices_List_Table
 *
 * Custom WP_List_Table for displaying invoices.
 *
 * @since 1.0.0
 */
class Invoices_List_Table extends \WP_List_Table {

    /**
     * Cache instance.
     *
     * @var Cache
     */
    private $cache;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct( array(
            'singular' => 'invoice',
            'plural'   => 'invoices',
            'ajax'     => false,
        ) );

        $this->cache = new Cache();
    }

    /**
     * Get columns.
     *
     * @return array
     */
    public function get_columns() {
        return array(
            'invoice_number' => __( 'Invoice #', 'bbab-core' ),
            'organization'   => __( 'Client', 'bbab-core' ),
            'amount'         => __( 'Amount', 'bbab-core' ),
            'amount_paid'    => __( 'Paid', 'bbab-core' ),
            'status'         => __( 'Status', 'bbab-core' ),
            'due_date'       => __( 'Due Date', 'bbab-core' ),
            'related_to'     => __( 'Related To', 'bbab-core' ),
        );
    }

    /**
     * Get sortable columns.
     *
     * @return array
     */
    public function get_sortable_columns() {
        return array(
            'invoice_number' => array( 'invoice_number', false ),
            'organization'   => array( 'organization', false ),
            'amount'         => array( 'amount', false ),
            'status'         => array( 'invoice_status', false ),
            'due_date'       => array( 'due_date', true ),
        );
    }

    /**
     * Prepare items for display.
     *
     * @return void
     */
    public function prepare_items() {
        $columns  = $this->get_columns();
        $hidden   = array();
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array( $columns, $hidden, $sortable );

        // Get filter values.
        $status_filter = isset( $_GET['invoice_status'] ) ? sanitize_text_field( $_GET['invoice_status'] ) : '';
        $org_filter    = isset( $_GET['organization'] ) ? absint( $_GET['organization'] ) : 0;
        $search        = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

        // Build query args.
        $args = array(
            'post_type'      => 'invoice',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        );

        // Status filter.
        if ( ! empty( $status_filter ) ) {
            $args['meta_query'][] = array(
                'key'     => 'invoice_status',
                'value'   => $status_filter,
                'compare' => '=',
            );
        } else {
            // Default: show unpaid invoices only.
            $args['meta_query'][] = array(
                'key'     => 'invoice_status',
                'value'   => array( 'Paid', 'Cancelled' ),
                'compare' => 'NOT IN',
            );
        }

        // Org filter.
        if ( ! empty( $org_filter ) ) {
            $args['meta_query'][] = array(
                'key'     => 'organization',
                'value'   => $org_filter,
                'compare' => '=',
            );
        }

        // Ensure meta_query has relation if multiple conditions.
        if ( isset( $args['meta_query'] ) && count( $args['meta_query'] ) > 1 ) {
            $args['meta_query']['relation'] = 'AND';
        }

        $invoices = get_posts( $args );

        // Custom search - filter results by meta fields if search term provided.
        if ( ! empty( $search ) ) {
            $search_lower = strtolower( $search );
            $invoices = array_filter( $invoices, function( $invoice ) use ( $search_lower ) {
                // Search in invoice_number.
                $inv_num = strtolower( get_post_meta( $invoice->ID, 'invoice_number', true ) );
                if ( strpos( $inv_num, $search_lower ) !== false ) {
                    return true;
                }

                // Search in organization name/shortcode.
                $org_id = get_post_meta( $invoice->ID, 'organization', true );
                if ( ! empty( $org_id ) ) {
                    if ( is_array( $org_id ) ) {
                        $org_id = reset( $org_id );
                    }
                    $org_name = strtolower( get_the_title( $org_id ) );
                    $org_shortcode = strtolower( get_post_meta( $org_id, 'organization_shortcode', true ) );
                    if ( strpos( $org_name, $search_lower ) !== false || strpos( $org_shortcode, $search_lower ) !== false ) {
                        return true;
                    }
                }

                // Search in related project reference number.
                $project_id = get_post_meta( $invoice->ID, 'related_project', true );
                if ( ! empty( $project_id ) ) {
                    if ( is_array( $project_id ) ) {
                        $project_id = reset( $project_id );
                    }
                    $ref = strtolower( get_post_meta( $project_id, 'reference_number', true ) );
                    if ( strpos( $ref, $search_lower ) !== false ) {
                        return true;
                    }
                }

                // Search in related milestone reference number.
                $milestone_id = get_post_meta( $invoice->ID, 'related_milestone', true );
                if ( ! empty( $milestone_id ) ) {
                    if ( is_array( $milestone_id ) ) {
                        $milestone_id = reset( $milestone_id );
                    }
                    $ref = strtolower( get_post_meta( $milestone_id, 'reference_number', true ) );
                    if ( strpos( $ref, $search_lower ) !== false ) {
                        return true;
                    }
                }

                // Search in related service request reference number.
                $sr_id = get_post_meta( $invoice->ID, 'related_service_request', true );
                if ( ! empty( $sr_id ) ) {
                    if ( is_array( $sr_id ) ) {
                        $sr_id = reset( $sr_id );
                    }
                    $ref = strtolower( get_post_meta( $sr_id, 'reference_number', true ) );
                    if ( strpos( $ref, $search_lower ) !== false ) {
                        return true;
                    }
                }

                return false;
            } );
            $invoices = array_values( $invoices ); // Re-index array.
        }

        // Sort by status priority (overdue first), then by due date.
        $status_order = array(
            'Overdue'   => 1,
            'Pending'   => 2,
            'Partial'   => 3,
            'Draft'     => 4,
            'Paid'      => 5,
            'Cancelled' => 6,
        );

        $today = strtotime( 'today' );

        usort( $invoices, function( $a, $b ) use ( $status_order, $today ) {
            $status_a = get_post_meta( $a->ID, 'invoice_status', true );
            $status_b = get_post_meta( $b->ID, 'invoice_status', true );
            $due_a    = get_post_meta( $a->ID, 'due_date', true );
            $due_b    = get_post_meta( $b->ID, 'due_date', true );

            // Check if actually overdue.
            if ( in_array( $status_a, array( 'Pending', 'Partial' ), true ) && ! empty( $due_a ) && strtotime( $due_a ) < $today ) {
                $status_a = 'Overdue';
            }
            if ( in_array( $status_b, array( 'Pending', 'Partial' ), true ) && ! empty( $due_b ) && strtotime( $due_b ) < $today ) {
                $status_b = 'Overdue';
            }

            $order_a = isset( $status_order[ $status_a ] ) ? $status_order[ $status_a ] : 99;
            $order_b = isset( $status_order[ $status_b ] ) ? $status_order[ $status_b ] : 99;

            if ( $order_a !== $order_b ) {
                return $order_a - $order_b;
            }

            // Same status, sort by due date (earliest first).
            $due_time_a = ! empty( $due_a ) ? strtotime( $due_a ) : PHP_INT_MAX;
            $due_time_b = ! empty( $due_b ) ? strtotime( $due_b ) : PHP_INT_MAX;

            return $due_time_a - $due_time_b;
        } );

        // Pagination.
        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $total_items  = count( $invoices );

        $this->items = array_slice( $invoices, ( $current_page - 1 ) * $per_page, $per_page );

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ) );
    }

    /**
     * Render the invoice number column.
     *
     * @param \WP_Post $item The invoice post.
     * @return string
     */
    public function column_invoice_number( $item ) {
        $inv_number = get_post_meta( $item->ID, 'invoice_number', true );
        $edit_link  = get_edit_post_link( $item->ID, 'raw' );

        // Row actions.
        $actions = array(
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                esc_url( $edit_link ),
                __( 'Edit', 'bbab-core' )
            ),
        );

        return sprintf(
            '<a href="%s" class="bbab-ref-link"><strong>%s</strong></a>%s',
            esc_url( $edit_link ),
            esc_html( $inv_number ),
            $this->row_actions( $actions )
        );
    }

    /**
     * Render the organization column.
     *
     * @param \WP_Post $item The invoice post.
     * @return string
     */
    public function column_organization( $item ) {
        $org_id = get_post_meta( $item->ID, 'organization', true );

        if ( empty( $org_id ) ) {
            return '<span class="bbab-text-muted">—</span>';
        }

        if ( is_array( $org_id ) ) {
            $org_id = reset( $org_id );
        }

        $shortcode = get_post_meta( $org_id, 'organization_shortcode', true );
        $name      = get_the_title( $org_id );

        return sprintf(
            '<span class="bbab-org-badge" title="%s">%s</span>',
            esc_attr( $name ),
            esc_html( $shortcode ?: $name )
        );
    }

    /**
     * Render the amount column.
     *
     * @param \WP_Post $item The invoice post.
     * @return string
     */
    public function column_amount( $item ) {
        $amount = (float) get_post_meta( $item->ID, 'amount', true );

        return '<strong>$' . number_format( $amount, 2 ) . '</strong>';
    }

    /**
     * Render the amount paid column.
     *
     * @param \WP_Post $item The invoice post.
     * @return string
     */
    public function column_amount_paid( $item ) {
        $paid = (float) get_post_meta( $item->ID, 'amount_paid', true );

        if ( $paid == 0 ) {
            return '<span class="bbab-text-muted">$0.00</span>';
        }

        return '$' . number_format( $paid, 2 );
    }

    /**
     * Render the status column.
     *
     * @param \WP_Post $item The invoice post.
     * @return string
     */
    public function column_status( $item ) {
        $status   = get_post_meta( $item->ID, 'invoice_status', true );
        $due_date = get_post_meta( $item->ID, 'due_date', true );
        $today    = strtotime( 'today' );

        // Check if actually overdue.
        if ( in_array( $status, array( 'Pending', 'Partial' ), true ) && ! empty( $due_date ) && strtotime( $due_date ) < $today ) {
            $status = 'Overdue';
        }

        $css_class = 'status-' . sanitize_title( $status );

        return sprintf(
            '<span class="bbab-status-badge %s">%s</span>',
            esc_attr( $css_class ),
            esc_html( $status )
        );
    }

    /**
     * Render the due date column.
     *
     * @param \WP_Post $item The invoice post.
     * @return string
     */
    public function column_due_date( $item ) {
        $due_date = get_post_meta( $item->ID, 'due_date', true );
        $status   = get_post_meta( $item->ID, 'invoice_status', true );

        if ( empty( $due_date ) ) {
            return '<span class="bbab-text-muted">—</span>';
        }

        $due_timestamp = strtotime( $due_date );
        $display       = date_i18n( 'M j, Y', $due_timestamp );
        $today         = strtotime( 'today' );

        // Add overdue indicator.
        if ( $due_timestamp < $today && ! in_array( $status, array( 'Paid', 'Cancelled' ), true ) ) {
            $days_overdue = floor( ( $today - $due_timestamp ) / DAY_IN_SECONDS );
            return sprintf(
                '<span class="bbab-overdue">%s <small>(%dd overdue)</small></span>',
                esc_html( $display ),
                $days_overdue
            );
        }

        return esc_html( $display );
    }

    /**
     * Render the related to column.
     *
     * @param \WP_Post $item The invoice post.
     * @return string
     */
    public function column_related_to( $item ) {
        $related = array();

        // Check for project.
        $project_id = get_post_meta( $item->ID, 'related_project', true );
        if ( ! empty( $project_id ) ) {
            if ( is_array( $project_id ) ) {
                $project_id = reset( $project_id );
            }
            $ref = get_post_meta( $project_id, 'reference_number', true );
            $related[] = sprintf(
                '<a href="%s" class="bbab-ref-link" title="%s">%s</a>',
                esc_url( get_edit_post_link( $project_id, 'raw' ) ),
                esc_attr__( 'Project', 'bbab-core' ),
                esc_html( $ref )
            );
        }

        // Check for milestone.
        $milestone_id = get_post_meta( $item->ID, 'related_milestone', true );
        if ( ! empty( $milestone_id ) ) {
            if ( is_array( $milestone_id ) ) {
                $milestone_id = reset( $milestone_id );
            }
            $ref = get_post_meta( $milestone_id, 'reference_number', true );
            $related[] = sprintf(
                '<a href="%s" class="bbab-ref-link" title="%s">%s</a>',
                esc_url( get_edit_post_link( $milestone_id, 'raw' ) ),
                esc_attr__( 'Milestone', 'bbab-core' ),
                esc_html( $ref )
            );
        }

        // Check for service request.
        $sr_id = get_post_meta( $item->ID, 'related_service_request', true );
        if ( ! empty( $sr_id ) ) {
            if ( is_array( $sr_id ) ) {
                $sr_id = reset( $sr_id );
            }
            $ref = get_post_meta( $sr_id, 'reference_number', true );
            $related[] = sprintf(
                '<a href="%s" class="bbab-ref-link" title="%s">%s</a>',
                esc_url( get_edit_post_link( $sr_id, 'raw' ) ),
                esc_attr__( 'Service Request', 'bbab-core' ),
                esc_html( $ref )
            );
        }

        if ( empty( $related ) ) {
            return '<span class="bbab-text-muted">—</span>';
        }

        return implode( ', ', $related );
    }

    /**
     * Default column renderer.
     *
     * @param \WP_Post $item        The invoice post.
     * @param string   $column_name Column name.
     * @return string
     */
    public function column_default( $item, $column_name ) {
        return '';
    }

    /**
     * Message when no items found.
     *
     * @return void
     */
    public function no_items() {
        esc_html_e( 'No invoices found.', 'bbab-core' );
    }
}
