<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\ServiceRequests;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Modules\ServiceRequests\ServiceRequestService;

/**
 * Service Request Archive shortcode.
 *
 * Full listing page with filters, sorting, and pagination.
 *
 * Shortcode: [service_request_archive]
 * Migrated from: WPCode Snippet #1813
 */
class Archive extends BaseShortcode {

    protected string $tag = 'service_request_archive';

    /**
     * Render the archive output.
     */
    protected function output(array $atts, int $org_id): string {
        // Get filter parameters
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
        $has_hours = isset($_GET['has_hours']) ? sanitize_text_field($_GET['has_hours']) : '';
        $month_filter = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : '';
        $sort_by = isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : 'date_desc';
        $page = isset($_GET['sr_page']) ? max(1, intval($_GET['sr_page'])) : 1;
        $per_page = 20;

        // Get all requests for this org using ServiceRequestService
        $all_requests = ServiceRequestService::getForOrg($org_id);

        // Process and filter requests
        $filtered_requests = [];
        foreach ($all_requests as $request) {
            $sr_data = ServiceRequestService::getData($request->ID);
            $status = $sr_data['request_status'];
            $type = $sr_data['request_type'];
            $submitted_date = $sr_data['submitted_date'];
            $hours = $sr_data['hours'];

            // Get submitter info
            $submitter_name = 'Unknown';
            if (!empty($sr_data['submitted_by'])) {
                $submitter = get_userdata((int) $sr_data['submitted_by']);
                $submitter_name = $submitter ? $submitter->display_name : 'Unknown';
            }

            // Apply filters
            if (!empty($status_filter) && $status !== $status_filter) {
                continue;
            }
            if (!empty($type_filter) && $type !== $type_filter) {
                continue;
            }
            if ($has_hours === 'yes' && $hours <= 0) {
                continue;
            }
            if ($has_hours === 'no' && $hours > 0) {
                continue;
            }

            // Month filter (format: YYYY-MM)
            if (!empty($month_filter)) {
                $request_month = date('Y-m', strtotime($submitted_date));
                if ($request_month !== $month_filter) {
                    continue;
                }
            }

            $filtered_requests[] = [
                'id' => $request->ID,
                'ref' => $sr_data['reference_number'],
                'subject' => $sr_data['subject'],
                'status' => $status,
                'type' => $type,
                'submitted_date' => $submitted_date,
                'post_date' => $sr_data['post_date'],
                'modified_date' => $sr_data['post_modified'],
                'submitter' => $submitter_name,
                'hours' => $hours,
            ];
        }

        // Sort results
        usort($filtered_requests, function ($a, $b) use ($sort_by) {
            switch ($sort_by) {
                case 'ref_asc':
                    return strcmp($a['ref'], $b['ref']);
                case 'ref_desc':
                    return strcmp($b['ref'], $a['ref']);
                case 'date_asc':
                    return strtotime($a['submitted_date']) - strtotime($b['submitted_date']);
                case 'activity':
                    return strtotime($b['modified_date']) - strtotime($a['modified_date']);
                case 'hours_desc':
                    return $b['hours'] <=> $a['hours'];
                case 'hours_asc':
                    return $a['hours'] <=> $b['hours'];
                case 'date_desc':
                default:
                    return strtotime($b['submitted_date']) - strtotime($a['submitted_date']);
            }
        });

        $total_filtered = count($filtered_requests);
        $total_pages = (int) ceil($total_filtered / $per_page);

        // Paginate filtered results
        $paged_requests = array_slice($filtered_requests, ($page - 1) * $per_page, $per_page);

        // Generate month options for filter (last 12 months)
        $month_options = [];
        for ($i = 0; $i < 12; $i++) {
            $month_value = date('Y-m', strtotime("-{$i} months"));
            $month_label = date('F Y', strtotime("-{$i} months"));
            $month_options[] = ['value' => $month_value, 'label' => $month_label];
        }

        ob_start();
        ?>
        <div class="sr-archive-page">
            <div class="archive-header">
                <h2>Service Request History</h2>
                <a href="/support-request-form/" class="new-request-btn">+ New Request</a>
            </div>

            <!-- Filters -->
            <div class="archive-filters">
                <form method="get" class="filter-form">
                    <div class="filter-group">
                        <label for="status-filter">Status:</label>
                        <select name="status" id="status-filter">
                            <option value="">All Statuses</option>
                            <?php foreach (ServiceRequestService::STATUSES as $status): ?>
                                <option value="<?php echo esc_attr($status); ?>" <?php selected($status_filter, $status); ?>><?php echo esc_html($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="type-filter">Type:</label>
                        <select name="type" id="type-filter">
                            <option value="">All Types</option>
                            <?php foreach (ServiceRequestService::REQUEST_TYPES as $type_value => $type_label): ?>
                                <option value="<?php echo esc_attr($type_value); ?>" <?php selected($type_filter, $type_value); ?>><?php echo esc_html($type_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="month-filter">Month:</label>
                        <select name="month" id="month-filter">
                            <option value="">All Time</option>
                            <?php foreach ($month_options as $opt): ?>
                                <option value="<?php echo esc_attr($opt['value']); ?>" <?php selected($month_filter, $opt['value']); ?>>
                                    <?php echo esc_html($opt['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="hours-filter">Hours:</label>
                        <select name="has_hours" id="hours-filter">
                            <option value="">All Requests</option>
                            <option value="yes" <?php selected($has_hours, 'yes'); ?>>With Hours</option>
                            <option value="no" <?php selected($has_hours, 'no'); ?>>No Hours Yet</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="sort-select">Sort By:</label>
                        <select name="sort" id="sort-select">
                            <option value="date_desc" <?php selected($sort_by, 'date_desc'); ?>>Newest First</option>
                            <option value="date_asc" <?php selected($sort_by, 'date_asc'); ?>>Oldest First</option>
                            <option value="activity" <?php selected($sort_by, 'activity'); ?>>Recent Activity</option>
                            <option value="ref_asc" <?php selected($sort_by, 'ref_asc'); ?>>Ref # (Low to High)</option>
                            <option value="ref_desc" <?php selected($sort_by, 'ref_desc'); ?>>Ref # (High to Low)</option>
                            <option value="hours_desc" <?php selected($sort_by, 'hours_desc'); ?>>Most Hours</option>
                            <option value="hours_asc" <?php selected($sort_by, 'hours_asc'); ?>>Least Hours</option>
                        </select>
                    </div>

                    <button type="submit" class="filter-btn">Apply</button>
                    <?php if ($status_filter || $type_filter || $has_hours || $month_filter || $sort_by !== 'date_desc'): ?>
                        <a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'], '?')); ?>" class="clear-filters">Clear All</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Results count -->
            <div class="results-info">
                Showing <?php echo count($paged_requests); ?> of <?php echo $total_filtered; ?> requests
                <?php if ($total_pages > 1): ?>
                    (Page <?php echo $page; ?> of <?php echo $total_pages; ?>)
                <?php endif; ?>
            </div>

            <!-- Request table -->
            <?php if (!empty($paged_requests)): ?>
                <div class="requests-table">
                    <?php foreach ($paged_requests as $req): ?>
                        <?php
                        $status_class = strtolower(str_replace(' ', '-', $req['status']));
                        $type_class = strtolower(str_replace(' ', '-', $req['type']));
                        $sr_url = get_permalink($req['id']);
                        $date = date('M j, Y', strtotime($req['submitted_date']));
                        ?>
                        <a href="<?php echo esc_url($sr_url); ?>" class="request-row">
                            <div class="row-main">
                                <span class="row-ref"><?php echo esc_html($req['ref']); ?></span>
                                <span class="row-subject"><?php echo esc_html($req['subject']); ?></span>
                            </div>
                            <div class="row-meta">
                                <span class="row-date"><?php echo esc_html($date); ?></span>
                                <span class="row-submitter">by <?php echo esc_html($req['submitter']); ?></span>
                                <span class="row-type type-<?php echo esc_attr($type_class); ?>"><?php echo esc_html($req['type']); ?></span>
                                <span class="row-status status-<?php echo esc_attr($status_class); ?>"><?php echo esc_html($req['status']); ?></span>
                                <?php if ($req['hours'] > 0): ?>
                                    <span class="row-hours"><?php echo number_format($req['hours'], 2); ?> hrs</span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <?php echo $this->renderPagination($page, $total_pages); ?>
                <?php endif; ?>

            <?php else: ?>
                <div class="no-results">
                    <p>No service requests found matching your filters.</p>
                    <?php if ($status_filter || $type_filter || $has_hours || $month_filter): ?>
                        <a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'], '?')); ?>" class="clear-btn">Clear Filters</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php echo $this->getStyles(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Render pagination HTML.
     */
    private function renderPagination(int $page, int $total_pages): string {
        ob_start();
        ?>
        <div class="archive-pagination">
            <?php if ($page > 1): ?>
                <a href="<?php echo esc_url(add_query_arg('sr_page', $page - 1)); ?>" class="page-link">&larr; Previous</a>
            <?php endif; ?>

            <span class="page-numbers">
                <?php
                // Smart pagination - show max 7 page numbers
                $start = max(1, $page - 3);
                $end = min($total_pages, $page + 3);

                if ($start > 1): ?>
                    <a href="<?php echo esc_url(add_query_arg('sr_page', 1)); ?>" class="page-number">1</a>
                    <?php if ($start > 2): ?>
                        <span class="page-ellipsis">...</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current-page"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo esc_url(add_query_arg('sr_page', $i)); ?>" class="page-number"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($end < $total_pages): ?>
                    <?php if ($end < $total_pages - 1): ?>
                        <span class="page-ellipsis">...</span>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(add_query_arg('sr_page', $total_pages)); ?>" class="page-number"><?php echo $total_pages; ?></a>
                <?php endif; ?>
            </span>

            <?php if ($page < $total_pages): ?>
                <a href="<?php echo esc_url(add_query_arg('sr_page', $page + 1)); ?>" class="page-link">Next &rarr;</a>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get CSS styles for the archive page.
     */
    private function getStyles(): string {
        return '
        <style>
            .sr-archive-page {
                max-width: 1200px;
                margin: 0 auto;
            }
            .archive-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 32px;
            }
            .archive-header h2 {
                font-family: "Poppins", sans-serif;
                font-size: 36px;
                font-weight: 600;
                color: #1C244B;
                margin: 0;
            }
            .new-request-btn {
                background: #467FF7;
                color: white !important;
                padding: 12px 24px;
                border-radius: 6px;
                text-decoration: none;
                font-family: "Poppins", sans-serif;
                font-size: 16px;
                font-weight: 500;
                transition: background 0.2s;
            }
            .new-request-btn:hover {
                background: #3366cc;
            }

            /* Filters */
            .archive-filters {
                background: #F3F5F8;
                padding: 20px;
                border-radius: 8px;
                margin-bottom: 24px;
            }
            .filter-form {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 16px;
                align-items: end;
            }
            .filter-group {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            .filter-group label {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                font-weight: 500;
                color: #324A6D;
            }
            .filter-group select {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                padding: 8px 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                background: white;
            }
            .filter-btn {
                background: #467FF7;
                color: white;
                padding: 8px 20px;
                border: none;
                border-radius: 4px;
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                transition: background 0.2s;
                height: fit-content;
            }
            .filter-btn:hover {
                background: #3366cc;
            }
            .clear-filters {
                color: #467FF7;
                text-decoration: none;
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                padding: 8px 12px;
                text-align: center;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .clear-filters:hover {
                text-decoration: underline;
            }

            /* Results info */
            .results-info {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                color: #7f8c8d;
                margin-bottom: 16px;
            }

            /* Request table */
            .requests-table {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }
            .request-row {
                background: #F3F5F8;
                border-radius: 8px;
                padding: 20px;
                text-decoration: none;
                display: block;
                transition: all 0.2s;
                border: 2px solid transparent;
            }
            .request-row:hover {
                border-color: #467FF7;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(70, 127, 247, 0.15);
            }
            .row-main {
                margin-bottom: 12px;
            }
            .row-ref {
                font-family: "Poppins", sans-serif;
                font-weight: 600;
                color: #467FF7;
                margin-right: 12px;
                font-size: 14px;
            }
            .row-subject {
                font-family: "Poppins", sans-serif;
                color: #1C244B;
                font-weight: 500;
                font-size: 18px;
            }
            .row-meta {
                font-family: "Poppins", sans-serif;
                font-size: 13px;
                color: #324A6D;
                display: flex;
                align-items: center;
                gap: 12px;
                flex-wrap: wrap;
            }
            .row-submitter {
                color: #7f8c8d;
                font-style: italic;
            }
            .row-type, .row-status {
                padding: 3px 10px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 500;
            }
            .row-type {
                background: #e8eaf6;
                color: #5c6bc0;
            }
            .status-new { background: #e3f2fd; color: #1976d2; }
            .status-acknowledged { background: #fff3e0; color: #f57c00; }
            .status-in-progress { background: #e8f5e9; color: #388e3c; }
            .status-waiting-on-client { background: #fef9e7; color: #b7950b; }
            .status-on-hold { background: #e8eaf6; color: #5c6bc0; }
            .status-completed { background: #f5f5f5; color: #616161; }
            .status-cancelled { background: #ffebee; color: #c62828; }
            .row-hours {
                font-weight: 600;
                color: #467FF7;
            }

            /* Pagination */
            .archive-pagination {
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 16px;
                margin-top: 32px;
                font-family: "Poppins", sans-serif;
            }
            .page-link {
                color: #467FF7;
                text-decoration: none;
                font-size: 14px;
                font-weight: 500;
            }
            .page-link:hover {
                text-decoration: underline;
            }
            .page-numbers {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            .page-number {
                color: #324A6D;
                text-decoration: none;
                padding: 6px 12px;
                border-radius: 4px;
                font-size: 14px;
            }
            .page-number:hover {
                background: #F3F5F8;
            }
            .current-page {
                background: #467FF7;
                color: white;
                padding: 6px 12px;
                border-radius: 4px;
                font-size: 14px;
                font-weight: 500;
            }
            .page-ellipsis {
                color: #7f8c8d;
                padding: 0 4px;
            }

            /* No results */
            .no-results {
                text-align: center;
                padding: 60px 20px;
            }
            .no-results p {
                font-family: "Poppins", sans-serif;
                font-size: 18px;
                color: #324A6D;
                margin-bottom: 20px;
            }
            .clear-btn {
                background: #467FF7;
                color: white !important;
                padding: 12px 24px;
                border-radius: 6px;
                text-decoration: none;
                font-family: "Poppins", sans-serif;
                font-size: 16px;
                font-weight: 500;
                display: inline-block;
            }
            .clear-btn:hover {
                background: #3366cc;
            }

            /* Mobile */
            @media (max-width: 768px) {
                .archive-header {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 16px;
                }
                .new-request-btn {
                    width: 100%;
                    text-align: center;
                }
                .filter-form {
                    grid-template-columns: 1fr;
                }
                .row-subject {
                    font-size: 16px;
                }
                .row-meta {
                    font-size: 12px;
                }
            }
        </style>';
    }
}
