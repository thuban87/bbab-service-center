<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Admin\Columns;

use BBAB\ServiceCenter\Modules\Billing\InvoiceService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Custom admin columns and filters for Invoice Line Items.
 *
 * Handles:
 * - Custom column definitions and rendering
 * - Admin list filters (Invoice, Line Type)
 * - Sortable columns
 * - Column styles with color-coded type badges
 *
 * Migrated from snippets: 1992, 1993
 */
class LineItemColumns {

    /**
     * Line type colors for badges.
     * Maps actual line_type values from the system.
     */
    private const TYPE_COLORS = [
        // Hosting
        'Hosting Fee' => ['bg' => '#e3f2fd', 'text' => '#1565c0'],
        'Hosting' => ['bg' => '#e3f2fd', 'text' => '#1565c0'],

        // Support (green)
        'Support' => ['bg' => '#e8f5e9', 'text' => '#2e7d32'],
        'Support (Non-Billable)' => ['bg' => '#f1f8e9', 'text' => '#558b2f'],

        // Credits (teal - negative amounts)
        'Free Hours Credit' => ['bg' => '#e0f2f1', 'text' => '#00796b'],
        'Credit' => ['bg' => '#e0f2f1', 'text' => '#00796b'],
        'Previous Payment' => ['bg' => '#e0f2f1', 'text' => '#00796b'],

        // Project work (purple family)
        'Project Milestone' => ['bg' => '#f3e5f5', 'text' => '#7b1fa2'],
        'Project Deposit' => ['bg' => '#fce4ec', 'text' => '#c2185b'],
        'Project Work' => ['bg' => '#ede7f6', 'text' => '#5e35b1'],
        'Project (Non-Billable)' => ['bg' => '#f5f5f5', 'text' => '#757575'],

        // Feature (orange)
        'Feature' => ['bg' => '#fff3e0', 'text' => '#e65100'],

        // Discounts (red)
        'Discount' => ['bg' => '#ffebee', 'text' => '#c62828'],

        // Tax (grey)
        'Tax' => ['bg' => '#eceff1', 'text' => '#455a64'],

        // Fallback
        'Other' => ['bg' => '#f5f5f5', 'text' => '#616161'],
    ];

    /**
     * Register all hooks.
     */
    public static function register(): void {
        // Column definition and rendering
        add_filter('manage_invoice_line_item_posts_columns', [self::class, 'defineColumns']);
        add_action('manage_invoice_line_item_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
        add_filter('manage_edit-invoice_line_item_sortable_columns', [self::class, 'sortableColumns']);

        // Filters
        add_action('restrict_manage_posts', [self::class, 'renderFilters']);
        add_action('pre_get_posts', [self::class, 'applyFilters']);

        // Admin styles
        add_action('admin_head', [self::class, 'renderStyles']);

        Logger::debug('LineItemColumns', 'Registered line item column hooks');
    }

    /**
     * Define custom columns.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public static function defineColumns(array $columns): array {
        $new_columns = [];
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = 'Line Item';
        $new_columns['invoice'] = 'Invoice';
        $new_columns['line_type'] = 'Type';
        $new_columns['description'] = 'Description';
        $new_columns['quantity'] = 'Qty';
        $new_columns['rate'] = 'Rate';
        $new_columns['amount'] = 'Amount';
        $new_columns['display_order'] = 'Order';

        return $new_columns;
    }

    /**
     * Render column content.
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public static function renderColumn(string $column, int $post_id): void {
        switch ($column) {
            case 'invoice':
                $invoice_id = get_post_meta($post_id, 'related_invoice', true);
                if (!empty($invoice_id)) {
                    $invoice_number = InvoiceService::getNumber((int) $invoice_id);
                    $edit_link = get_edit_post_link((int) $invoice_id);
                    if ($edit_link && $invoice_number) {
                        echo '<a href="' . esc_url($edit_link) . '" class="invoice-link">' . esc_html($invoice_number) . '</a>';
                    } elseif ($invoice_number) {
                        echo esc_html($invoice_number);
                    } else {
                        echo '<span class="no-invoice">#' . esc_html($invoice_id) . '</span>';
                    }
                } else {
                    echo '<span class="no-invoice">—</span>';
                }
                break;

            case 'line_type':
                $type = get_post_meta($post_id, 'line_type', true) ?: 'Other';
                $colors = self::TYPE_COLORS[$type] ?? self::TYPE_COLORS['Other'];
                printf(
                    '<span class="line-type-badge" style="background:%s;color:%s;">%s</span>',
                    esc_attr($colors['bg']),
                    esc_attr($colors['text']),
                    esc_html($type)
                );
                break;

            case 'description':
                $desc = get_post_meta($post_id, 'description', true);
                if (!empty($desc)) {
                    // Truncate long descriptions
                    $max_length = 60;
                    if (strlen($desc) > $max_length) {
                        echo '<span title="' . esc_attr($desc) . '">' . esc_html(substr($desc, 0, $max_length)) . '...</span>';
                    } else {
                        echo esc_html($desc);
                    }
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                break;

            case 'quantity':
                $qty = get_post_meta($post_id, 'quantity', true);
                if (!empty($qty) && is_numeric($qty)) {
                    echo esc_html(rtrim(rtrim(number_format((float) $qty, 2), '0'), '.'));
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                break;

            case 'rate':
                $rate = get_post_meta($post_id, 'rate', true);
                if (!empty($rate) && is_numeric($rate)) {
                    echo '$' . number_format((float) $rate, 2);
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                break;

            case 'amount':
                $amount = get_post_meta($post_id, 'amount', true);
                if (!empty($amount) && is_numeric($amount)) {
                    $value = (float) $amount;
                    if ($value < 0) {
                        echo '<span style="color: #c62828;">-$' . number_format(abs($value), 2) . '</span>';
                    } else {
                        echo '<span style="color: #1e8449;">$' . number_format($value, 2) . '</span>';
                    }
                } else {
                    echo '<span style="color: #999;">$0.00</span>';
                }
                break;

            case 'display_order':
                $order = get_post_meta($post_id, 'display_order', true);
                echo esc_html($order ?: '0');
                break;
        }
    }

    /**
     * Define sortable columns.
     *
     * @param array $columns Sortable columns.
     * @return array Modified sortable columns.
     */
    public static function sortableColumns(array $columns): array {
        $columns['invoice'] = 'related_invoice';
        $columns['line_type'] = 'line_type';
        $columns['amount'] = 'amount';
        $columns['display_order'] = 'display_order';
        return $columns;
    }

    /**
     * Render filter dropdowns.
     *
     * @param string $post_type Current post type.
     */
    public static function renderFilters(string $post_type): void {
        if ($post_type !== 'invoice_line_item') {
            return;
        }

        // Line Type filter - using actual values from the system
        $types = [
            'Hosting Fee',
            'Support',
            'Support (Non-Billable)',
            'Free Hours Credit',
            'Project Milestone',
            'Project Deposit',
            'Project Work',
            'Project (Non-Billable)',
            'Previous Payment',
            'Feature',
            'Discount',
            'Credit',
            'Tax',
        ];
        $selected_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';

        echo '<select name="filter_type">';
        echo '<option value="">All Types</option>';
        foreach ($types as $type) {
            $selected = selected($selected_type, $type, false);
            echo '<option value="' . esc_attr($type) . '"' . $selected . '>' . esc_html($type) . '</option>';
        }
        echo '</select>';
    }

    /**
     * Apply filters to the query.
     *
     * @param \WP_Query $query The query object.
     */
    public static function applyFilters(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== 'invoice_line_item') {
            return;
        }

        $meta_query = $query->get('meta_query') ?: [];

        // Line Type filter
        if (!empty($_GET['filter_type'])) {
            $meta_query[] = [
                'key' => 'line_type',
                'value' => sanitize_text_field($_GET['filter_type']),
            ];
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }

        // Handle sorting
        $orderby = $query->get('orderby');
        if ($orderby === 'related_invoice') {
            $query->set('meta_key', 'related_invoice');
            $query->set('orderby', 'meta_value_num');
        } elseif ($orderby === 'line_type') {
            $query->set('meta_key', 'line_type');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'amount') {
            $query->set('meta_key', 'amount');
            $query->set('orderby', 'meta_value_num');
        } elseif ($orderby === 'display_order') {
            $query->set('meta_key', 'display_order');
            $query->set('orderby', 'meta_value_num');
        }
    }

    /**
     * Render column styles.
     */
    public static function renderStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'invoice_line_item') {
            return;
        }

        echo '<style>
            /* Invoice link styling */
            .invoice-link {
                font-family: monospace;
                font-weight: 600;
                color: #467FF7;
            }
            .no-invoice {
                color: #999;
            }

            /* Line type badge */
            .line-type-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }

            /* Column widths */
            .column-title { width: 180px; }
            .column-invoice { width: 100px; }
            .column-line_type { width: 120px; }
            .column-description {
                width: 250px;
                max-width: 300px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .column-quantity { width: 60px; text-align: right; }
            .column-rate { width: 70px; text-align: right; }
            .column-amount { width: 90px; text-align: right; }
            .column-display_order { width: 50px; text-align: center; }

            /* Right-align numeric columns */
            .column-quantity,
            .column-rate,
            .column-amount {
                text-align: right !important;
            }

            /* Center order column */
            .column-display_order {
                text-align: center !important;
            }
        </style>';
    }
}
