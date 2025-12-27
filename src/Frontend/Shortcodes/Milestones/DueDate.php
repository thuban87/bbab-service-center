<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Milestones;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;
use BBAB\ServiceCenter\Modules\Projects\MilestoneService;

/**
 * Milestone Due Date shortcode.
 *
 * Renders the milestone due date with overdue styling if applicable.
 * Paid milestones don't show as overdue.
 *
 * Usage: [milestone_due_date]
 *
 * Migrated from: WPCode Snippet #1655
 */
class DueDate extends BaseShortcode {

    protected string $tag = 'milestone_due_date';
    protected bool $requires_org = false;
    protected bool $requires_login = false;

    /**
     * Render the shortcode output.
     *
     * @param array $atts   Shortcode attributes.
     * @param int   $org_id Organization ID (unused - uses current post context).
     * @return string HTML output.
     */
    protected function output(array $atts, int $org_id): string {
        $post_id = get_the_ID();

        // Fallback for Elementor loop context
        if (!$post_id) {
            global $post;
            $post_id = $post ? $post->ID : 0;
        }

        if (!$post_id) {
            return '';
        }

        $due_date = get_post_meta($post_id, 'due_date', true);

        // Triple validation to prevent epoch dates
        if (empty($due_date) || strtotime($due_date) === false || strtotime($due_date) <= 0) {
            return '<span class="no-due-date">No due date set</span>' . $this->getStyles();
        }

        $timestamp = strtotime($due_date);
        $formatted_date = date('M j, Y', $timestamp);
        $today = strtotime('today');

        // Check if overdue
        $is_overdue = $timestamp < $today;

        // Check if milestone is paid - paid milestones don't show as overdue
        $is_paid = MilestoneService::isPaid($post_id);

        // Determine styling
        $overdue_text = '';
        if ($is_paid) {
            $class = 'due-date-complete';
        } elseif ($is_overdue) {
            $class = 'due-date-overdue';
            $days_overdue = (int) floor(($today - $timestamp) / DAY_IN_SECONDS);
            $overdue_text = " ({$days_overdue} " . ($days_overdue === 1 ? 'day' : 'days') . " overdue)";
        } else {
            $class = 'due-date-upcoming';
        }

        ob_start();
        ?>
        <div class="milestone-due-date <?php echo esc_attr($class); ?>">
            <strong>Due:</strong> <?php echo esc_html($formatted_date); ?><?php echo esc_html($overdue_text); ?>
        </div>
        <?php echo $this->getStyles(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Get component styles.
     *
     * @return string CSS styles.
     */
    private function getStyles(): string {
        return '<style>
            .milestone-due-date {
                font-family: \'Poppins\', sans-serif;
                font-size: 14px;
                margin: 8px 0;
            }
            .milestone-due-date strong {
                font-weight: 500;
                color: #324A6D;
            }
            .due-date-complete {
                color: #1e8449;
            }
            .due-date-overdue {
                color: #c0392b;
                font-weight: 500;
            }
            .due-date-upcoming {
                color: #324A6D;
            }
            .no-due-date {
                font-family: \'Poppins\', sans-serif;
                font-size: 14px;
                color: #7f8c8d;
                font-style: italic;
            }
        </style>';
    }
}
