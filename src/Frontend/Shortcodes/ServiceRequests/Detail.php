<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\ServiceRequests;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Modules\ServiceRequests\ServiceRequestService;

/**
 * Service Request Detail shortcode.
 *
 * Displays the main service request details on single SR pages.
 * Shows: reference number, status badge, subject, meta grid, description.
 *
 * Shortcode: [service_request_details]
 * Migrated from: WPCode Snippet #1837
 */
class Detail extends BaseShortcode {

    protected string $tag = 'service_request_details';

    /**
     * For detail page, org check is handled by AccessControl.
     * This shortcode requires being on a single SR page.
     */
    protected bool $requires_org = false;

    /**
     * Render the detail output.
     */
    protected function output(array $atts, int $org_id): string {
        // Only works on single service request pages
        if (!is_singular('service_request')) {
            return '';
        }

        global $post;
        $sr_id = $post->ID;

        // Get all SR data
        $sr_data = ServiceRequestService::getData($sr_id);

        if (empty($sr_data)) {
            return '<p>Service request not found.</p>';
        }

        // Extract data
        $ref = $sr_data['reference_number'];
        $subject = $sr_data['subject'];
        $description = $sr_data['description'];
        $status = $sr_data['request_status'];
        $type = $sr_data['request_type'];
        $priority = $sr_data['priority'] ?: 'Normal';
        $hours = $sr_data['hours'];

        // Format dates
        $submitted_date = !empty($sr_data['submitted_date'])
            ? date('F j, Y', strtotime($sr_data['submitted_date']))
            : date('F j, Y', strtotime($sr_data['post_date']));

        $completed_date = null;
        if (!empty($sr_data['completed_date']) && strtotime($sr_data['completed_date']) > 0) {
            $completed_date = date('F j, Y', strtotime($sr_data['completed_date']));
        }

        // Get submitter name
        $submitter_name = 'Unknown';
        if (!empty($sr_data['submitted_by'])) {
            $submitter = get_userdata((int) $sr_data['submitted_by']);
            $submitter_name = $submitter ? $submitter->display_name : 'Unknown';
        }

        // Status and priority classes
        $status_class = strtolower(str_replace(' ', '-', $status));
        $priority_class = strtolower($priority);

        ob_start();
        ?>
        <div class="sr-details-card">
            <div class="sr-header">
                <div class="sr-ref-status">
                    <span class="sr-ref"><?php echo esc_html($ref); ?></span>
                    <span class="sr-status status-<?php echo esc_attr($status_class); ?>"><?php echo esc_html($status); ?></span>
                </div>
                <h1 class="sr-subject"><?php echo esc_html($subject); ?></h1>
            </div>

            <div class="sr-meta-grid">
                <div class="meta-item">
                    <span class="meta-label">Type</span>
                    <span class="meta-value"><?php echo esc_html($type); ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Priority</span>
                    <span class="meta-value priority-<?php echo esc_attr($priority_class); ?>"><?php echo esc_html($priority); ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Submitted</span>
                    <span class="meta-value"><?php echo esc_html($submitted_date); ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Submitted By</span>
                    <span class="meta-value"><?php echo esc_html($submitter_name); ?></span>
                </div>
                <?php if ($completed_date): ?>
                    <div class="meta-item">
                        <span class="meta-label">Completed</span>
                        <span class="meta-value"><?php echo esc_html($completed_date); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($hours > 0): ?>
                    <div class="meta-item">
                        <span class="meta-label">Hours Logged</span>
                        <span class="meta-value sr-hours"><?php echo number_format($hours, 2); ?> hrs</span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($description): ?>
                <div class="sr-description">
                    <h3>Request Details</h3>
                    <div class="description-content">
                        <?php echo wp_kses_post($description); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php echo $this->getStyles(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Get CSS styles for the detail card.
     */
    private function getStyles(): string {
        return '
        <style>
            .sr-details-card {
                background: #F3F5F8;
                border-radius: 12px;
                padding: 32px;
                margin-bottom: 32px;
            }
            .sr-header {
                margin-bottom: 24px;
            }
            .sr-ref-status {
                display: flex;
                align-items: center;
                gap: 12px;
                margin-bottom: 12px;
            }
            .sr-ref {
                font-family: "Poppins", sans-serif;
                font-size: 16px;
                font-weight: 600;
                color: #467FF7;
            }
            .sr-status {
                padding: 4px 12px;
                border-radius: 4px;
                font-family: "Poppins", sans-serif;
                font-size: 13px;
                font-weight: 500;
            }
            .status-new { background: #e3f2fd; color: #1976d2; }
            .status-acknowledged { background: #fff3e0; color: #f57c00; }
            .status-in-progress { background: #e8f5e9; color: #388e3c; }
            .status-waiting-on-client { background: #fef9e7; color: #b7950b; }
            .status-on-hold { background: #e8eaf6; color: #5c6bc0; }
            .status-completed { background: #f5f5f5; color: #616161; }
            .status-cancelled { background: #ffebee; color: #c62828; }
            .sr-subject {
                font-family: "Poppins", sans-serif;
                font-size: 32px;
                font-weight: 600;
                color: #1C244B;
                margin: 0;
                line-height: 1.3;
            }
            .sr-meta-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 32px;
                padding: 24px;
                background: white;
                border-radius: 8px;
            }
            .meta-item {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            .meta-label {
                font-family: "Poppins", sans-serif;
                font-size: 12px;
                font-weight: 500;
                color: #7f8c8d;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .meta-value {
                font-family: "Poppins", sans-serif;
                font-size: 16px;
                font-weight: 500;
                color: #1C244B;
            }
            .priority-urgent {
                color: #c62828;
                font-weight: 600;
            }
            .priority-high {
                color: #f57c00;
                font-weight: 600;
            }
            .sr-hours {
                color: #467FF7;
                font-weight: 600;
            }
            .sr-description {
                background: white;
                border-radius: 8px;
                padding: 24px;
            }
            .sr-description h3 {
                font-family: "Poppins", sans-serif;
                font-size: 18px;
                font-weight: 600;
                color: #1C244B;
                margin: 0 0 16px 0;
            }
            .description-content {
                font-family: "Poppins", sans-serif;
                font-size: 16px;
                line-height: 1.6;
                color: #324A6D;
            }
            .description-content p {
                margin-bottom: 16px;
            }
            .description-content ul,
            .description-content ol {
                margin-left: 24px;
                margin-bottom: 16px;
            }
            .description-content li {
                margin-bottom: 8px;
            }

            @media (max-width: 768px) {
                .sr-details-card {
                    padding: 20px;
                }
                .sr-subject {
                    font-size: 24px;
                }
                .sr-meta-grid {
                    grid-template-columns: 1fr;
                    gap: 16px;
                }
            }
        </style>';
    }
}
