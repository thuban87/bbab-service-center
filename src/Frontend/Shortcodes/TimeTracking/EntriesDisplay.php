<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\TimeTracking;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Modules\TimeTracking\TimeEntryService;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Time Entries display shortcodes for Projects and Milestones.
 *
 * Shortcodes:
 * - [project_time_entries] - Displays general project time (excludes milestone-specific)
 * - [milestone_time_entries] - Displays milestone-specific time entries
 *
 * Migrated from: WPCode Snippet #1887
 */
class EntriesDisplay extends BaseShortcode {

    /**
     * Primary tag - we register multiple shortcodes manually.
     */
    protected string $tag = 'project_time_entries';

    /**
     * Access control handled by project/milestone templates.
     */
    protected bool $requires_org = false;

    /**
     * Override register to add both shortcodes.
     */
    public function register(): void {
        add_shortcode('project_time_entries', [$this, 'renderProject']);
        add_shortcode('milestone_time_entries', [$this, 'renderMilestone']);

        Logger::debug('EntriesDisplay', 'Registered project and milestone time entry shortcodes');
    }

    /**
     * Render project time entries.
     *
     * Shows general project time (excludes milestone-specific entries).
     *
     * @param array|string $atts Shortcode attributes.
     * @return string Rendered HTML.
     */
    public function renderProject($atts = []): string {
        if (!is_singular('project')) {
            return '';
        }

        global $post;
        $project_id = $post->ID;

        // Get time entries using TimeEntryService (excludes milestone-specific)
        $entries = TimeEntryService::getForProject($project_id);

        if (empty($entries)) {
            return $this->renderEmptyState('project');
        }

        return $this->renderTable($entries, 'project');
    }

    /**
     * Render milestone time entries.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string Rendered HTML.
     */
    public function renderMilestone($atts = []): string {
        if (!is_singular('milestone')) {
            return '';
        }

        global $post;
        $milestone_id = $post->ID;

        // Get time entries using TimeEntryService
        $entries = TimeEntryService::getForMilestone($milestone_id);

        if (empty($entries)) {
            return $this->renderEmptyState('milestone');
        }

        return $this->renderTable($entries, 'milestone');
    }

    /**
     * Render the time entries table.
     *
     * @param array  $entries Array of time entry post objects.
     * @param string $context 'project' or 'milestone'.
     * @return string Rendered HTML.
     */
    private function renderTable(array $entries, string $context): string {
        $total_hours = 0.0;
        $card_class = $context === 'project' ? 'project-time-entries-card' : 'milestone-time-entries-card';

        ob_start();
        ?>
        <div class="<?php echo esc_attr($card_class); ?>">
            <?php if ($context === 'project'): ?>
                <h3>General Project Time</h3>
                <p class="section-description">Planning, meetings, and administrative work not tied to specific milestones.</p>
            <?php else: ?>
                <h3>Time Logged for This Milestone</h3>
            <?php endif; ?>

            <div class="bbab-te-table">
                <div class="bbab-te-header">
                    <span class="bbab-te-col-date">Date</span>
                    <span class="bbab-te-col-desc">Description</span>
                    <span class="bbab-te-col-notes">Notes</span>
                    <span class="bbab-te-col-hours">Hours</span>
                </div>

                <?php foreach ($entries as $entry): ?>
                    <?php
                    // Get entry data
                    $entry_date_raw = get_post_meta($entry->ID, 'entry_date', true);
                    if (is_array($entry_date_raw)) {
                        $entry_date_raw = reset($entry_date_raw);
                    }
                    $entry_date = !empty($entry_date_raw) ? date('M j, Y', strtotime($entry_date_raw)) : 'N/A';

                    $description = get_post_meta($entry->ID, 'description', true);
                    $work_notes = get_post_meta($entry->ID, 'work_notes', true);
                    $hours = floatval(get_post_meta($entry->ID, 'hours', true));
                    $total_hours += $hours;
                    ?>
                    <div class="bbab-te-row">
                        <span class="bbab-te-col-date"><?php echo esc_html($entry_date); ?></span>
                        <span class="bbab-te-col-desc"><?php echo esc_html($description ?: '(No description)'); ?></span>
                        <span class="bbab-te-col-notes">
                            <?php if (!empty($work_notes)): ?>
                                <div class="bbab-te-work-notes"><?php echo wp_kses_post($work_notes); ?></div>
                            <?php else: ?>
                                <span class="bbab-te-no-notes">&mdash;</span>
                            <?php endif; ?>
                        </span>
                        <span class="bbab-te-col-hours"><?php echo number_format($hours, 2); ?></span>
                    </div>
                <?php endforeach; ?>

                <div class="bbab-te-footer">
                    <span class="bbab-te-footer-label">
                        <?php echo $context === 'project' ? 'Total General Project Hours:' : 'Total Milestone Hours:'; ?>
                    </span>
                    <span class="bbab-te-footer-total"><?php echo number_format($total_hours, 2); ?> hrs</span>
                </div>
            </div>
        </div>

        <?php echo $this->getStyles(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Render empty state when no time entries exist.
     *
     * @param string $context 'project' or 'milestone'.
     * @return string Rendered HTML.
     */
    private function renderEmptyState(string $context): string {
        $card_class = $context === 'project' ? 'project-time-entries-card' : 'milestone-time-entries-card';
        $title = $context === 'project' ? 'General Project Time' : 'Time Logged for This Milestone';

        ob_start();
        ?>
        <div class="<?php echo esc_attr($card_class); ?>">
            <h3><?php echo esc_html($title); ?></h3>
            <div class="te-empty-state">
                <span class="empty-icon">&#128221;</span>
                <p>No time has been logged yet. Check back soon for updates!</p>
            </div>
        </div>
        <?php echo $this->getStyles(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Get CSS styles for time entries display.
     *
     * @return string CSS styles.
     */
    private function getStyles(): string {
        return '
        <style>
            .project-time-entries-card,
            .milestone-time-entries-card {
                background: #F3F5F8;
                border-radius: 12px;
                padding: 24px;
                margin-bottom: 32px;
            }
            .project-time-entries-card h3,
            .milestone-time-entries-card h3 {
                font-family: "Poppins", sans-serif;
                font-size: 18px;
                font-weight: 600;
                color: #1C244B;
                margin: 0 0 8px 0;
            }
            .section-description {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                color: #7f8c8d;
                margin: 0 0 16px 0;
            }
            .te-empty-state {
                background: white;
                border-radius: 8px;
                padding: 32px 24px;
                text-align: center;
            }
            .te-empty-state .empty-icon {
                font-size: 32px;
                display: block;
                margin-bottom: 12px;
            }
            .te-empty-state p {
                font-family: "Poppins", sans-serif;
                font-size: 15px;
                color: #324A6D;
                margin: 0;
                line-height: 1.5;
            }
            .bbab-te-table {
                background: white;
                border-radius: 8px;
                overflow: hidden;
            }
            .bbab-te-header {
                display: grid;
                grid-template-columns: 120px 200px 1fr 80px;
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
            .bbab-te-row {
                display: grid;
                grid-template-columns: 120px 200px 1fr 80px;
                gap: 16px;
                padding: 16px 20px;
                border-bottom: 1px solid #f0f0f0;
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                color: #324A6D;
                align-items: start;
            }
            .bbab-te-row:last-of-type {
                border-bottom: none;
            }
            .bbab-te-row .bbab-te-col-date {
                font-weight: 500;
            }
            .bbab-te-row .bbab-te-col-desc {
                font-weight: 500;
                color: #1C244B;
            }
            .bbab-te-col-notes {
                font-size: 13px;
                line-height: 1.5;
            }
            .bbab-te-work-notes {
                color: #324A6D;
            }
            .bbab-te-work-notes p {
                margin: 0 0 8px 0;
            }
            .bbab-te-work-notes p:last-child {
                margin: 0;
            }
            .bbab-te-no-notes {
                color: #999;
            }
            .bbab-te-col-hours {
                text-align: right;
                font-weight: 600;
                color: #467FF7;
            }
            .bbab-te-footer {
                display: flex;
                justify-content: flex-end;
                align-items: center;
                gap: 16px;
                padding: 20px;
                background: #F3F5F8;
                border-top: 2px solid #467FF7;
            }
            .bbab-te-footer-label {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                font-weight: 500;
                color: #324A6D;
            }
            .bbab-te-footer-total {
                font-family: "Poppins", sans-serif;
                font-size: 18px;
                font-weight: 600;
                color: #467FF7;
            }

            @media (max-width: 768px) {
                .bbab-te-header {
                    display: none;
                }
                .bbab-te-row {
                    grid-template-columns: 1fr;
                    gap: 8px;
                    padding: 16px;
                }
                .bbab-te-col-date::before {
                    content: "Date: ";
                    font-weight: 600;
                }
                .bbab-te-col-desc::before {
                    content: "Task: ";
                    font-weight: 600;
                }
                .bbab-te-col-notes::before {
                    content: "Notes: ";
                    font-weight: 600;
                }
                .bbab-te-col-hours::before {
                    content: "Hours: ";
                    font-weight: 600;
                }
                .bbab-te-col-hours {
                    text-align: left;
                }
            }
        </style>';
    }

    /**
     * Not used - we override register() instead.
     *
     * @param array $atts   Shortcode attributes.
     * @param int   $org_id Organization ID.
     * @return string
     */
    protected function output(array $atts, int $org_id): string {
        return '';
    }
}
