<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\ServiceRequests;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Modules\TimeTracking\TimeEntryService;

/**
 * Service Request Time Entries shortcode.
 *
 * Displays time entries logged against the service request.
 *
 * Shortcode: [service_request_time_entries]
 * Migrated from: WPCode Snippet #1840
 */
class TimeEntries extends BaseShortcode {

    protected string $tag = 'service_request_time_entries';

    /**
     * For detail page, org check is handled by AccessControl.
     */
    protected bool $requires_org = false;

    /**
     * Render the time entries output.
     */
    protected function output(array $atts, int $org_id): string {
        // Only works on single service request pages
        if (!is_singular('service_request')) {
            return '';
        }

        global $post;
        $sr_id = $post->ID;

        // Get time entries using TimeEntryService
        $entries = TimeEntryService::getForServiceRequest($sr_id);

        // No entries - show friendly message
        if (empty($entries)) {
            return $this->renderEmptyState();
        }

        // Build the table
        $total_hours = 0.0;

        ob_start();
        ?>
        <div class="sr-time-entries-card">
            <h3>Time Logged</h3>
            <div class="time-entries-table">
                <div class="table-header">
                    <span class="col-date">Date</span>
                    <span class="col-description">Description</span>
                    <span class="col-hours">Hours</span>
                </div>
                <?php foreach ($entries as $entry): ?>
                    <?php
                    $entry_date_raw = get_post_meta($entry->ID, 'entry_date', true);
                    if (is_array($entry_date_raw)) {
                        $entry_date_raw = reset($entry_date_raw);
                    }
                    $entry_date = !empty($entry_date_raw) ? date('M j, Y', strtotime($entry_date_raw)) : 'N/A';

                    $description = get_post_meta($entry->ID, 'description', true);
                    $hours = floatval(get_post_meta($entry->ID, 'hours', true));
                    $total_hours += $hours;
                    ?>
                    <div class="table-row">
                        <span class="col-date"><?php echo esc_html($entry_date); ?></span>
                        <span class="col-description"><?php echo esc_html($description ?: '(No description)'); ?></span>
                        <span class="col-hours"><?php echo number_format($hours, 2); ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="table-footer">
                    <span class="footer-label">Total Hours:</span>
                    <span class="footer-total"><?php echo number_format($total_hours, 2); ?> hrs</span>
                </div>
            </div>
        </div>

        <?php echo $this->getStyles(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Render empty state when no time entries exist.
     */
    private function renderEmptyState(): string {
        ob_start();
        ?>
        <div class="sr-time-entries-card">
            <h3>Work Log</h3>
            <div class="sr-te-empty-state">
                <span class="empty-icon">&#128221;</span>
                <p>We're working our way through our tickets. Bear with us and we'll be in touch soon!</p>
            </div>
        </div>
        <?php echo $this->getStyles(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Get CSS styles for time entries.
     */
    private function getStyles(): string {
        return '
        <style>
            .sr-time-entries-card {
                background: #F3F5F8;
                border-radius: 12px;
                padding: 24px;
                margin-bottom: 32px;
            }
            .sr-time-entries-card h3 {
                font-family: "Poppins", sans-serif;
                font-size: 18px;
                font-weight: 600;
                color: #1C244B;
                margin: 0 0 16px 0;
            }
            .sr-te-empty-state {
                background: white;
                border-radius: 8px;
                padding: 32px 24px;
                text-align: center;
            }
            .sr-te-empty-state .empty-icon {
                font-size: 32px;
                display: block;
                margin-bottom: 12px;
            }
            .sr-te-empty-state p {
                font-family: "Poppins", sans-serif;
                font-size: 15px;
                color: #324A6D;
                margin: 0;
                line-height: 1.5;
            }
            .time-entries-table {
                background: white;
                border-radius: 8px;
                overflow: hidden;
            }
            .table-header {
                display: grid;
                grid-template-columns: 120px 1fr 80px;
                gap: 16px;
                padding: 16px 20px;
                background: #1C244B;
                font-family: "Poppins", sans-serif;
                font-size: 13px;
                font-weight: 600;
                color: white;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .table-row {
                display: grid;
                grid-template-columns: 120px 1fr 80px;
                gap: 16px;
                padding: 16px 20px;
                border-bottom: 1px solid #f0f0f0;
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                color: #324A6D;
                align-items: center;
            }
            .table-row:last-of-type {
                border-bottom: none;
            }
            .col-date {
                font-weight: 500;
            }
            .col-hours {
                text-align: right;
                font-weight: 600;
                color: #467FF7;
            }
            .table-footer {
                display: flex;
                justify-content: flex-end;
                align-items: center;
                gap: 16px;
                padding: 20px;
                background: #F3F5F8;
                border-top: 2px solid #467FF7;
            }
            .footer-label {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                font-weight: 500;
                color: #324A6D;
            }
            .footer-total {
                font-family: "Poppins", sans-serif;
                font-size: 18px;
                font-weight: 600;
                color: #467FF7;
            }

            @media (max-width: 768px) {
                .table-header {
                    display: none;
                }
                .table-row {
                    grid-template-columns: 1fr;
                    gap: 8px;
                    padding: 16px;
                }
                .col-date::before {
                    content: "Date: ";
                    font-weight: 600;
                }
                .col-hours::before {
                    content: "Hours: ";
                    font-weight: 600;
                }
                .col-hours {
                    text-align: left;
                }
            }
        </style>';
    }
}
