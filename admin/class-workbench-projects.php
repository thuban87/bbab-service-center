<?php
/**
 * Brad's Workbench - Projects Sub-Page.
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
 * Class Workbench_Projects
 *
 * Handles the Projects sub-page with enhanced list table.
 *
 * @since 1.0.0
 */
class Workbench_Projects {

    /**
     * Cache instance.
     *
     * @var Cache
     */
    private $cache;

    /**
     * The list table instance.
     *
     * @var Projects_List_Table
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
     * Render the projects page.
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
        $this->list_table = new Projects_List_Table();
        $this->list_table->prepare_items();

        // Get summary stats.
        $stats = $this->get_summary_stats();

        // Get organizations for filter.
        $organizations = $this->get_organizations();

        // Current filters.
        $current_status = isset( $_GET['project_status'] ) ? sanitize_text_field( $_GET['project_status'] ) : '';
        $current_org    = isset( $_GET['organization'] ) ? absint( $_GET['organization'] ) : 0;

        // Load template.
        include BBAB_CORE_PATH . 'admin/partials/workbench-projects.php';
    }

    /**
     * Get summary statistics for projects.
     *
     * @since 1.0.0
     * @return array
     */
    private function get_summary_stats() {
        $cache_key = 'projects_summary_stats_monthly';
        $cached    = $this->cache->get( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        // Current month boundaries.
        $month_start = date( 'Y-m-01 00:00:00' );
        $month_end   = date( 'Y-m-t 23:59:59' );

        // Get all non-completed/cancelled projects for status counts.
        $active_projects = get_posts( array(
            'post_type'      => 'project',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => 'project_status',
                    'value'   => array( 'Active', 'Waiting on Client', 'On Hold' ),
                    'compare' => 'IN',
                ),
            ),
        ) );

        $stats = array(
            'total_active'       => 0,
            'total_waiting'      => 0,
            'total_on_hold'      => 0,
            'hours_this_month'   => 0,
            'budget_this_month'  => 0,
            'completed_this_month' => 0,
            'invoiced_this_month'  => 0,
        );

        // Count active statuses.
        foreach ( $active_projects as $project ) {
            $status = get_post_meta( $project->ID, 'project_status', true );

            switch ( $status ) {
                case 'Active':
                    $stats['total_active']++;
                    break;
                case 'Waiting on Client':
                    $stats['total_waiting']++;
                    break;
                case 'On Hold':
                    $stats['total_on_hold']++;
                    break;
            }
        }

        // Hours this month - get all TEs from this month linked to projects or milestones.
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
                    'relation' => 'OR',
                    array(
                        'key'     => 'related_project',
                        'value'   => '',
                        'compare' => '!=',
                    ),
                    array(
                        'key'     => 'related_milestone',
                        'value'   => '',
                        'compare' => '!=',
                    ),
                ),
            ),
        ) );

        foreach ( $month_tes as $te ) {
            $stats['hours_this_month'] += (float) get_post_meta( $te->ID, 'hours', true );
        }

        // Budget this month - projects created this month.
        $month_projects = get_posts( array(
            'post_type'      => 'project',
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

        foreach ( $month_projects as $project ) {
            $stats['budget_this_month'] += (float) get_post_meta( $project->ID, 'total_budget', true );
        }

        // Completed this month - projects with Completed status modified this month.
        $completed_projects = get_posts( array(
            'post_type'      => 'project',
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
                    'key'     => 'project_status',
                    'value'   => 'Completed',
                    'compare' => '=',
                ),
            ),
        ) );

        $stats['completed_this_month'] = count( $completed_projects );

        // Invoiced this month - sum of invoices created this month linked to projects/milestones.
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
            // Check if linked to project or milestone.
            $linked_project   = get_post_meta( $invoice->ID, 'related_project', true );
            $linked_milestone = get_post_meta( $invoice->ID, 'related_milestone', true );

            if ( ! empty( $linked_project ) || ! empty( $linked_milestone ) ) {
                $stats['invoiced_this_month'] += (float) get_post_meta( $invoice->ID, 'amount', true );
            }
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
 * Class Projects_List_Table
 *
 * Custom WP_List_Table for displaying projects.
 *
 * @since 1.0.0
 */
class Projects_List_Table extends \WP_List_Table {

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
            'singular' => 'project',
            'plural'   => 'projects',
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
            'project_name' => __( 'Project', 'bbab-core' ),
            'organization' => __( 'Client', 'bbab-core' ),
            'status'       => __( 'Status', 'bbab-core' ),
            'milestones'   => __( 'Milestones', 'bbab-core' ),
            'hours'        => __( 'Hours', 'bbab-core' ),
            'invoices'     => __( 'Invoices', 'bbab-core' ),
            'budget'       => __( 'Budget', 'bbab-core' ),
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
            'project_name' => array( 'project_name', false ),
            'organization' => array( 'organization', false ),
            'status'       => array( 'project_status', false ),
            'hours'        => array( 'hours', false ),
            'budget'       => array( 'budget', false ),
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
        $status_filter = isset( $_GET['project_status'] ) ? sanitize_text_field( $_GET['project_status'] ) : '';
        $org_filter    = isset( $_GET['organization'] ) ? absint( $_GET['organization'] ) : 0;
        $search        = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

        // Build query args.
        $args = array(
            'post_type'      => 'project',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        );

        // Status filter.
        if ( ! empty( $status_filter ) ) {
            $args['meta_query'][] = array(
                'key'     => 'project_status',
                'value'   => $status_filter,
                'compare' => '=',
            );
        } else {
            // Default: show active statuses only.
            $args['meta_query'][] = array(
                'key'     => 'project_status',
                'value'   => array( 'Active', 'Waiting on Client', 'On Hold' ),
                'compare' => 'IN',
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

        // Search.
        if ( ! empty( $search ) ) {
            $args['s'] = $search;
        }

        // Ensure meta_query has relation if multiple conditions.
        if ( isset( $args['meta_query'] ) && count( $args['meta_query'] ) > 1 ) {
            $args['meta_query']['relation'] = 'AND';
        }

        $projects = get_posts( $args );

        // Sort by status priority, then by date.
        $status_order = array(
            'Active'            => 1,
            'Waiting on Client' => 2,
            'On Hold'           => 3,
            'Completed'         => 4,
            'Cancelled'         => 5,
        );

        usort( $projects, function( $a, $b ) use ( $status_order ) {
            $status_a = get_post_meta( $a->ID, 'project_status', true );
            $status_b = get_post_meta( $b->ID, 'project_status', true );

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
        $total_items  = count( $projects );

        $this->items = array_slice( $projects, ( $current_page - 1 ) * $per_page, $per_page );

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ) );
    }

    /**
     * Render the ref number column.
     *
     * @param \WP_Post $item The project post.
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
     * Render the project name column.
     *
     * @param \WP_Post $item The project post.
     * @return string
     */
    public function column_project_name( $item ) {
        $name = get_post_meta( $item->ID, 'project_name', true );
        if ( empty( $name ) ) {
            $name = $item->post_title;
        }

        $edit_link = get_edit_post_link( $item->ID, 'raw' );

        // Row actions.
        $actions = array(
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                esc_url( $edit_link ),
                __( 'Edit', 'bbab-core' )
            ),
            'milestones' => sprintf(
                '<a href="%s">%s</a>',
                esc_url( add_query_arg( array(
                    'post_type'       => 'milestone',
                    'bbab_project_id' => $item->ID,
                ), admin_url( 'edit.php' ) ) ),
                __( 'Milestones', 'bbab-core' )
            ),
            'add_time_entry' => sprintf(
                '<a href="%s">%s</a>',
                esc_url( add_query_arg( array(
                    'post_type'       => 'time_entry',
                    'related_project' => $item->ID,
                ), admin_url( 'post-new.php' ) ) ),
                __( 'Add Time Entry', 'bbab-core' )
            ),
            'time_entries' => sprintf(
                '<a href="%s">%s</a>',
                esc_url( add_query_arg( array(
                    'post_type'       => 'time_entry',
                    'bbab_project_id' => $item->ID,
                ), admin_url( 'edit.php' ) ) ),
                __( 'View Time Entries', 'bbab-core' )
            ),
        );

        return sprintf(
            '<strong>%s</strong>%s',
            esc_html( $name ),
            $this->row_actions( $actions )
        );
    }

    /**
     * Render the organization column.
     *
     * @param \WP_Post $item The project post.
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
     * @param \WP_Post $item The project post.
     * @return string
     */
    public function column_status( $item ) {
        $status = get_post_meta( $item->ID, 'project_status', true );
        $css_class = 'status-' . sanitize_title( $status );

        return sprintf(
            '<span class="bbab-status-badge %s">%s</span>',
            esc_attr( $css_class ),
            esc_html( $status )
        );
    }

    /**
     * Render the milestones column.
     *
     * @param \WP_Post $item The project post.
     * @return string
     */
    public function column_milestones( $item ) {
        $milestones = get_posts( array(
            'post_type'      => 'milestone',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => 'related_project',
                    'value'   => $item->ID,
                    'compare' => '=',
                ),
            ),
        ) );

        $count = count( $milestones );

        if ( $count === 0 ) {
            return '<span class="bbab-text-muted">0</span>';
        }

        $url = add_query_arg( array(
            'post_type'       => 'milestone',
            'bbab_project_id' => $item->ID,
        ), admin_url( 'edit.php' ) );

        return sprintf(
            '<a href="%s" class="bbab-count-link" title="%s">%d</a>',
            esc_url( $url ),
            esc_attr__( 'View Milestones', 'bbab-core' ),
            $count
        );
    }

    /**
     * Render the hours column.
     *
     * @param \WP_Post $item The project post.
     * @return string
     */
    public function column_hours( $item ) {
        $cache_key = 'project_hours_' . $item->ID;
        $hours     = $this->cache->get( $cache_key );

        if ( false === $hours ) {
            $hours = 0;

            // Direct TEs.
            $direct_tes = get_posts( array(
                'post_type'      => 'time_entry',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    array(
                        'key'     => 'related_project',
                        'value'   => $item->ID,
                        'compare' => '=',
                    ),
                ),
            ) );

            foreach ( $direct_tes as $te ) {
                $hours += (float) get_post_meta( $te->ID, 'hours', true );
            }

            // Milestone TEs.
            $milestones = get_posts( array(
                'post_type'      => 'milestone',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => 'related_project',
                        'value'   => $item->ID,
                        'compare' => '=',
                    ),
                ),
            ) );

            foreach ( $milestones as $ms_id ) {
                $ms_tes = get_posts( array(
                    'post_type'      => 'time_entry',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'meta_query'     => array(
                        array(
                            'key'     => 'related_milestone',
                            'value'   => $ms_id,
                            'compare' => '=',
                        ),
                    ),
                ) );

                foreach ( $ms_tes as $te ) {
                    $hours += (float) get_post_meta( $te->ID, 'hours', true );
                }
            }

            $this->cache->set( $cache_key, $hours, HOUR_IN_SECONDS );
        }

        if ( $hours == 0 ) {
            return '<span class="bbab-text-muted">0</span>';
        }

        $url = add_query_arg( array(
            'post_type'       => 'time_entry',
            'bbab_project_id' => $item->ID,
        ), admin_url( 'edit.php' ) );

        return sprintf(
            '<a href="%s" class="bbab-count-link" title="%s">%s</a>',
            esc_url( $url ),
            esc_attr__( 'View Time Entries', 'bbab-core' ),
            number_format( $hours, 1 )
        );
    }

    /**
     * Render the invoices column.
     *
     * @param \WP_Post $item The project post.
     * @return string
     */
    public function column_invoices( $item ) {
        // Get invoices directly linked to project.
        $direct_invoices = get_posts( array(
            'post_type'      => 'invoice',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => 'related_project',
                    'value'   => $item->ID,
                    'compare' => '=',
                ),
            ),
        ) );

        // Get milestones for this project.
        $milestones = get_posts( array(
            'post_type'      => 'milestone',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => 'related_project',
                    'value'   => $item->ID,
                    'compare' => '=',
                ),
            ),
        ) );

        // Get invoices linked to milestones.
        $milestone_invoices = array();
        if ( ! empty( $milestones ) ) {
            $milestone_invoices = get_posts( array(
                'post_type'      => 'invoice',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => 'related_milestone',
                        'value'   => $milestones,
                        'compare' => 'IN',
                    ),
                ),
            ) );
        }

        // Combine and dedupe.
        $all_invoices = array_unique( array_merge( $direct_invoices, $milestone_invoices ) );
        $count = count( $all_invoices );

        if ( $count === 0 ) {
            return '<span class="bbab-text-muted">0</span>';
        }

        // Link to invoice list filtered by project.
        $url = add_query_arg( array(
            'post_type'       => 'invoice',
            'bbab_project_id' => $item->ID,
        ), admin_url( 'edit.php' ) );

        return sprintf(
            '<a href="%s" class="bbab-count-link" title="%s">%d</a>',
            esc_url( $url ),
            esc_attr__( 'View Invoices', 'bbab-core' ),
            $count
        );
    }

    /**
     * Render the budget column.
     *
     * @param \WP_Post $item The project post.
     * @return string
     */
    public function column_budget( $item ) {
        $budget = (float) get_post_meta( $item->ID, 'total_budget', true );

        if ( $budget == 0 ) {
            return '<span class="bbab-text-muted">—</span>';
        }

        return '$' . number_format( $budget, 2 );
    }

    /**
     * Default column renderer.
     *
     * @param \WP_Post $item        The project post.
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
        esc_html_e( 'No projects found.', 'bbab-core' );
    }
}
