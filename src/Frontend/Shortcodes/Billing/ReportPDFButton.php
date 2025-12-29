<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\Billing;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;

/**
 * Monthly Report PDF Download Button shortcode.
 *
 * Displays a download button for the Site Health Report PDF.
 *
 * Shortcode: [report_pdf_download]
 *
 * Attributes:
 * - report_id: Monthly report post ID (default: current post)
 * - text: Button text (default: "Download Site Health Report (PDF)")
 *
 * Migrated from snippet: 1138
 */
class ReportPDFButton extends BaseShortcode {

    protected string $tag = 'report_pdf_download';
    protected bool $requires_org = false; // Uses report_id instead

    /**
     * Render the PDF download button.
     *
     * @param array $atts   Shortcode attributes.
     * @param int   $org_id Organization ID (not used).
     * @return string HTML output.
     */
    protected function output(array $atts, int $org_id): string {
        $atts = $this->parseAtts($atts, [
            'report_id' => get_the_ID(),
            'text' => 'Download Site Health Report (PDF)',
        ]);

        $report_id = (int) $atts['report_id'];
        $pdf_url = $this->getPdfUrl($report_id);

        if (empty($pdf_url)) {
            return '<p style="font-family: Poppins, sans-serif; font-size: 14px; color: #324A6D; font-style: italic;">Site health report PDF not yet available for this period.</p>';
        }

        $output = '<a href="' . esc_url($pdf_url) . '" target="_blank" class="bbab-pdf-button" style="
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: Poppins, sans-serif;
            font-size: 16px;
            font-weight: 600;
            color: #FFFFFF;
            background-color: #467FF7;
            padding: 12px 24px;
            border-radius: 4px;
            text-decoration: none;
            transition: background-color 0.3s ease;
        ">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            ' . esc_html($atts['text']) . '
        </a>';

        return $output;
    }

    /**
     * Get PDF URL from various storage formats.
     *
     * Handles Pods file fields and raw post meta with multiple storage patterns.
     *
     * @param int $report_id Report post ID.
     * @return string|null PDF URL or null if not found.
     */
    private function getPdfUrl(int $report_id): ?string {
        $pdf_url = null;

        // Try Pods first (handles file field relationships)
        if (function_exists('pods')) {
            $pod = pods('monthly_report', $report_id);
            if ($pod && $pod->exists()) {
                $pdf = $pod->field('site_health_pdf');

                if (is_array($pdf)) {
                    // Pods returns array with guid or ID
                    if (!empty($pdf['guid'])) {
                        $pdf_url = $pdf['guid'];
                    } elseif (!empty($pdf['ID'])) {
                        $pdf_url = wp_get_attachment_url($pdf['ID']);
                    }
                } elseif (is_numeric($pdf)) {
                    // Attachment ID
                    $pdf_url = wp_get_attachment_url((int) $pdf);
                } elseif (is_string($pdf) && !empty($pdf)) {
                    // URL string or ID as string
                    if (filter_var($pdf, FILTER_VALIDATE_URL)) {
                        $pdf_url = $pdf;
                    } elseif (is_numeric($pdf)) {
                        $pdf_url = wp_get_attachment_url((int) $pdf);
                    }
                }
            }
        }

        // Fallback to raw post meta with multiple possible field names
        if (!$pdf_url) {
            $meta_keys = ['site_health_pdf', 'report_pdf'];

            foreach ($meta_keys as $key) {
                $pdf_meta = get_post_meta($report_id, $key, true);

                if (empty($pdf_meta)) {
                    continue;
                }

                if (is_numeric($pdf_meta)) {
                    $pdf_url = wp_get_attachment_url((int) $pdf_meta);
                } elseif (is_string($pdf_meta) && filter_var($pdf_meta, FILTER_VALIDATE_URL)) {
                    $pdf_url = $pdf_meta;
                } elseif (is_array($pdf_meta)) {
                    if (!empty($pdf_meta['guid'])) {
                        $pdf_url = $pdf_meta['guid'];
                    } elseif (!empty($pdf_meta['ID'])) {
                        $pdf_url = wp_get_attachment_url($pdf_meta['ID']);
                    } elseif (!empty($pdf_meta['url'])) {
                        $pdf_url = $pdf_meta['url'];
                    }
                }

                if ($pdf_url) {
                    break;
                }
            }
        }

        return $pdf_url;
    }
}
