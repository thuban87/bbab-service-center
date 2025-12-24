<?php
/**
 * Brad's Workbench - Service Requests Sub-Page.
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
 * Class Workbench_Requests
 *
 * Handles the Service Requests sub-page with enhanced list table.
 *
 * @since 1.0.0
 */
class Workbench_Requests {

    /**
     * Cache instance.
     *
     * @var Cache
     */
    private $cache;

    /**
     * The list table instance.
     *
     * @var Requests_List_Table
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
     * Render the requests page.
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
        $this->list_table = new Requests_List_Table();
        $this->list_table->prepare_items();

        // Get summary stats.
        $stats = $this->get_summary_stats();

        // Get organizations for filter.
        $organizations = $this->get_organizations();

        // Current filters.
        $current_status = isset( $_GET['request_status'] ) ? sanitize_text_field( $_GET['request_status'] ) : '';
        $current_org    = isset( $_GET['organization'] ) ? absint( $_GET['organization'] ) : 0;

        // Load template.
        include BBAB_CORE_PATH . 'admin/partials/workbench-requests.php';
    }

    /**
     * Get summary statistics for service requests.
     *
     * @since 1.0.0
     * @return array
     */
    private function get_summary_stats() {
        $cache_key = 'requests_summary_stats_monthly';
        $cached    = $this->cache->get( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        // Current month boundaries.
        $month_start = date( 'Y-m-01 00:00:00' );
        $month_end   = date( 'Y-m-t 23:59:59' );

        // Get all open service requests.
        $open_requests = get_posts( array(
            'post_type'      => 'service_request',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => 'request_status',
                    'value'   => array( 'Completed', 'Cancelled' ),
                    'compare' => 'NOT IN',
                ),
            ),
        ) );

        $stats = array(
            'total_new'          => 0,
            'total_acknowledged' => 0,
            'total_in_progress'  => 0,
            'total_waiting'      => 0,
            'total_on_hold'      => 0,
            'hours_this_month'   => 0,
            'completed_this_month' => 0,
        );

        // Count statuses.
        foreach ( $open_requests as $sr ) {
            $status = get_post_meta( $sr->ID, 'request_status', true );

            switch ( $status ) {
                case 'New':
                    $stats['total_new']++;
                    break;
                case 'Acknowledged':
                    $stats['total_acknowledged']++;
                    break;
                case 'In Progress':
                    $stats['total_in_progress']++;
                    break;
                case 'Waiting on Client':
                    $stats['total_waiting']++;
                    break;
                case 'On Hold':
                    $stats['total_on_hold']++;
                    break;
            }
        }

        // Hours this month - TEs linked to SRs created this month.
        $month_tes = get_posts( array(
            'post_type'      => 'time_entry',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => 'entry_date',
                    'value'   => array( $month_start, $month_end ),
                    'compare' => 'BETWEEN',
                    'type'    => 'DATETIME',
                ),
                array(
                    'key'     => 'related_service_request',
                    'value'   => '',
                    'compare' => '!=',
                ),
            ),
        ) );

        foreach ( $month_tes as $te ) {
            $stats['hours_this_month'] += (float) get_post_meta( $te->ID, 'hours', true );
        }

        // Completed this month.
        $completed_requests = get_posts( array(
            'post_type'      => 'service_request',
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
                    'key'     => 'request_status',
                    'value'   => 'Completed',
                    'compare' => '=',
                ),
            ),
        ) );

        $stats['completed_this_month'] = count( $completed_requests );

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
 * Class Requests_List_Table
 *
 * Custom WP_List_Table for displaying service requests.
 *
 * @since 1.0.0
 */
class Requests_List_Table extends \WP_List_Table {

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
            'singular' => 'request',
            'plural'   => 'requests',
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
            'ref_number'   => __( 'Ref #', 'bbab-core' ),
            'subject'      => __( 'Subject', 'bbab-core' ),
            'organization' => __( 'Client', 'bbab-core' ),
            'status'       => __( 'Status', 'bbab-core' ),
            'request_type' => __( 'Type', 'bbab-core' ),
            'priority'     => __( 'Priority', 'bbab-core' ),
            'hours'        => __( 'Hours', 'bbab-core' ),
            'created'      => __( 'Created', 'bbab-core' ),
        );
    }

    /**
     * Get sortable columns.
     *
     * @return array
     */
    public function get_sortable_columns() {
        return array(
            'ref_number'   => array( 'reference_number', false ),
            'subject'      => array( 'subject', false ),
            'organization' => array( 'organization', false ),
            'status'       => array( 'request_status', false ),
            'priority'     => array( 'priority', false ),
            'hours'        => array( 'hours', false ),
            'created'      => array( 'post_date', true ),
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
        $status_filter = isset( $_GET['request_status'] ) ? sanitize_text_field( $_GET['request_status'] ) : '';
        $org_filter    = isset( $_GET['organization'] ) ? absint( $_GET['organization'] ) : 0;
        $search        = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

        // Build query args.
        $args = array(
            'post_type'      => 'service_request',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        );

        // Status filter.
        if ( ! empty( $status_filter ) ) {
            $args['meta_query'][] = array(
                'key'     => 'request_status',
                'value'   => $status_filter,
                'compare' => '=',
            );
        } else {
            // Default: show open requests only.
            $args['meta_query'][] = array(
                'key'     => 'request_status',
                'value'   => array( 'Completed', 'Cancelled' ),
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

        $requests = get_posts( $args );

        // Custom search - filter results by meta fields if search term provided.
        if ( ! empty( $search ) ) {
            $search_lower = strtolower( $search );
            $requests = array_filter( $requests, function( $sr ) use ( $search_lower ) {
                // Search in reference_number.
                $ref = strtolower( get_post_meta( $sr->ID, 'reference_number', true ) );
                if ( strpos( $ref, $search_lower ) !== false ) {
                    return true;
                }

                // Search in subject.
                $subject = strtolower( get_post_meta( $sr->ID, 'subject', true ) );
                if ( strpos( $subject, $search_lower ) !== false ) {
                    return true;
                }

                // Search in post title as fallback.
                if ( strpos( strtolower( $sr->post_title ), $search_lower ) !== false ) {
                    return true;
                }

                // Search in organization name/shortcode.
                $org_id = get_post_meta( $sr->ID, 'organization', true );
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

                // Search in request_type.
                $type = strtolower( get_post_meta( $sr->ID, 'request_type', true ) );
                if ( strpos( $type, $search_lower ) !== false ) {
                    return true;
                }

                // Search in priority.
                $priority = strtolower( get_post_meta( $sr->ID, 'priority', true ) );
                if ( strpos( $priority, $search_lower ) !== false ) {
                    return true;
                }

                return false;
            } );
            $requests = array_values( $requests ); // Re-index array.
        }

        // Sort by status priority, then by date.
        $status_order = array(
            'New'               => 1,
            'Acknowledged'      => 2,
            'In Progress'       => 3,
            'Waiting on Client' => 4,
            'On Hold'           => 5,
            'Completed'         => 6,
            'Cancelled'         => 7,
        );

        usort( $requests, function( $a, $b ) use ( $status_order ) {
            $status_a = get_post_meta( $a->ID, 'request_status', true );
            $status_b = get_post_meta( $b->ID, 'request_status', true );

            $order_a = isset( $status_order[ $status_a ] ) ? $status_order[ $status_a ] : 99;
            $order_b = isset( $status_order[ $status_b ] ) ? $status_order[ $status_b ] : 99;

            if ( $order_a !== $order_b ) {
                return $order_a - $order_b;
            }

            return strtotime( $b->post_date ) - strtotime( $a->post_date );
        } );

        // Pagination.
        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $total_items  = count( $requests );

        $this->items = array_slice( $requests, ( $current_page - 1 ) * $per_page, $per_page );

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ) );
    }

    /**
     * Render the ref number column.
     *
     * @param \WP_Post $item The service request post.
     * @return string
     */
    public function column_ref_number( $item ) {
        $ref = get_post_meta( $item->ID, 'reference_number', true );
        $edit_link = get_edit_post_link( $item->ID, 'raw' );

        return sprintf(
            '<a href="%s" class="bbab-ref-link"><strong>%s</strong></a>',
            esc_url( $edit_link ),
            esc_html( $ref )
        );
    }

    /**
     * Render the subject column.
     *
     * @param \WP_Post $item The service request post.
     * @return string
     */
    public function column_subject( $item ) {
        $subject = get_post_meta( $item->ID, 'subject', true );
        if ( empty( $subject ) ) {
            $subject = $item->post_title;
        }

        $edit_link = get_edit_post_link( $item->ID, 'raw' );

        // Row actions.
        $actions = array(
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                esc_url( $edit_link ),
                __( 'Edit', 'bbab-core' )
            ),
            'add_time_entry' => sprintf(
                '<a href="%s">%s</a>',
                esc_url( add_query_arg( array(
                    'post_type'                 => 'time_entry',
                    'related_service_request'   => $item->ID,
                ), admin_url( 'post-new.php' ) ) ),
                __( 'Add Time Entry', 'bbab-core' )
            ),
            'time_entries' => sprintf(
                '<a href="%s">%s</a>',
                esc_url( add_query_arg( array(
                    'post_type'  => 'time_entry',
                    'bbab_sr_id' => $item->ID,
                ), admin_url( 'edit.php' ) ) ),
                __( 'View Time Entries', 'bbab-core' )
            ),
        );

        return sprintf(
            '<strong>%s</strong>%s',
            esc_html( $subject ),
            $this->row_actions( $actions )
        );
    }

    /**
     * Render the organization column.
     *
     * @param \WP_Post $item The service request post.
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
     * Render the status column.
     *
     * @param \WP_Post $item The service request post.
     * @return string
     */
    public function column_status( $item ) {
        $status = get_post_meta( $item->ID, 'request_status', true );
        $css_class = 'status-' . sanitize_title( $status );

        return sprintf(
            '<span class="bbab-status-badge %s">%s</span>',
            esc_attr( $css_class ),
            esc_html( $status )
        );
    }

    /**
     * Render the request type column.
     *
     * @param \WP_Post $item The service request post.
     * @return string
     */
    public function column_request_type( $item ) {
        $type = get_post_meta( $item->ID, 'request_type', true );

        if ( empty( $type ) ) {
            return '<span class="bbab-text-muted">—</span>';
        }

        return esc_html( $type );
    }

    /**
     * Render the priority column.
     *
     * @param \WP_Post $item The service request post.
     * @return string
     */
    public function column_priority( $item ) {
        $priority = get_post_meta( $item->ID, 'priority', true );

        if ( empty( $priority ) ) {
            return '<span class="bbab-text-muted">—</span>';
        }

        $class = '';
        if ( $priority === 'High' || $priority === 'Urgent' ) {
            $class = 'bbab-priority-high';
        }

        return sprintf(
            '<span class="%s">%s</span>',
            esc_attr( $class ),
            esc_html( $priority )
        );
    }

    /**
     * Render the hours column.
     *
     * @param \WP_Post $item The service request post.
     * @return string
     */
    public function column_hours( $item ) {
        $cache_key = 'sr_hours_' . $item->ID;
        $hours     = $this->cache->get( $cache_key );

        if ( false === $hours ) {
            $hours = 0;

            $tes = get_posts( array(
                'post_type'      => 'time_entry',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    array(
                        'key'     => 'related_service_request',
                        'value'   => $item->ID,
                        'compare' => '=',
                    ),
                ),
            ) );

            foreach ( $tes as $te ) {
                $hours += (float) get_post_meta( $te->ID, 'hours', true );
            }

            $this->cache->set( $cache_key, $hours, HOUR_IN_SECONDS );
        }

        if ( $hours == 0 ) {
            return '<span class="bbab-text-muted">0</span>';
        }

        $url = add_query_arg( array(
            'post_type'  => 'time_entry',
            'bbab_sr_id' => $item->ID,
        ), admin_url( 'edit.php' ) );

        return sprintf(
            '<a href="%s" class="bbab-count-link" title="%s">%s</a>',
            esc_url( $url ),
            esc_attr__( 'View Time Entries', 'bbab-core' ),
            number_format( $hours, 1 )
        );
    }

    /**
     * Render the created column.
     *
     * @param \WP_Post $item The service request post.
     * @return string
     */
    public function column_created( $item ) {
        $submitted_date = get_post_meta( $item->ID, 'submitted_date', true );

        if ( ! empty( $submitted_date ) ) {
            return date_i18n( 'M j, Y', strtotime( $submitted_date ) );
        }

        return date_i18n( 'M j, Y', strtotime( $item->post_date ) );
    }

    /**
     * Default column renderer.
     *
     * @param \WP_Post $item        The service request post.
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
        esc_html_e( 'No service requests found.', 'bbab-core' );
    }
}
