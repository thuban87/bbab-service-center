<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Frontend\Shortcodes\ServiceRequests;

use BBAB\ServiceCenter\Frontend\Shortcodes\BaseShortcode;

/**
 * Service Request Attachments shortcode.
 *
 * Displays attachments uploaded with the service request.
 *
 * Shortcode: [service_request_attachments]
 * Migrated from: WPCode Snippet #1839
 */
class Attachments extends BaseShortcode {

    protected string $tag = 'service_request_attachments';

    /**
     * For detail page, org check is handled by AccessControl.
     */
    protected bool $requires_org = false;

    /**
     * Render the attachments output.
     */
    protected function output(array $atts, int $org_id): string {
        // Only works on single service request pages
        if (!is_singular('service_request')) {
            return '';
        }

        global $post;

        // Get attachments from meta
        $attachments = get_post_meta($post->ID, 'attachments', true);

        if (empty($attachments)) {
            return '';
        }

        // Handle both single file and array of files
        if (!is_array($attachments)) {
            $attachments = [$attachments];
        }

        // Filter out empty values
        $attachments = array_filter($attachments);

        if (empty($attachments)) {
            return '';
        }

        ob_start();
        ?>
        <div class="sr-attachments-card">
            <h3>Attachments</h3>
            <div class="attachments-list">
                <?php foreach ($attachments as $file): ?>
                    <?php
                    $file_url = '';
                    $file_name = '';

                    if (is_array($file)) {
                        // Pods may return array with 'guid' or 'ID'
                        if (!empty($file['guid'])) {
                            $file_url = $file['guid'];
                            $file_name = basename($file_url);
                        } elseif (!empty($file['ID'])) {
                            $file_url = wp_get_attachment_url($file['ID']);
                            $file_name = basename(get_attached_file($file['ID']));
                        }
                    } elseif (is_numeric($file)) {
                        // Attachment ID stored as integer
                        $file_url = wp_get_attachment_url((int) $file);
                        $file_name = basename(get_attached_file((int) $file));
                    } elseif (is_string($file) && filter_var($file, FILTER_VALIDATE_URL)) {
                        // Direct URL string
                        $file_url = $file;
                        $file_name = basename($file_url);
                    }

                    if (empty($file_url)) {
                        continue;
                    }

                    // Get file extension for icon
                    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $icon = $this->getFileIcon($ext);
                    ?>
                    <a href="<?php echo esc_url($file_url); ?>" class="attachment-item" target="_blank" download>
                        <span class="attachment-icon"><?php echo $icon; ?></span>
                        <span class="attachment-name"><?php echo esc_html($file_name); ?></span>
                        <span class="attachment-action">Download</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php echo $this->getStyles(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Get file icon based on extension.
     */
    private function getFileIcon(string $ext): string {
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'])) {
            return '&#128444;'; // Image icon
        } elseif ($ext === 'pdf') {
            return '&#128196;'; // PDF icon
        } elseif (in_array($ext, ['doc', 'docx'])) {
            return '&#128195;'; // Document icon
        } elseif (in_array($ext, ['xls', 'xlsx'])) {
            return '&#128202;'; // Spreadsheet icon
        } elseif (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'])) {
            return '&#128230;'; // Archive icon
        }
        return '&#128196;'; // Default file icon
    }

    /**
     * Get CSS styles for attachments.
     */
    private function getStyles(): string {
        return '
        <style>
            .sr-attachments-card {
                background: #F3F5F8;
                border-radius: 12px;
                padding: 24px;
                margin-bottom: 32px;
            }
            .sr-attachments-card h3 {
                font-family: "Poppins", sans-serif;
                font-size: 18px;
                font-weight: 600;
                color: #1C244B;
                margin: 0 0 16px 0;
            }
            .attachments-list {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }
            .attachment-item {
                background: white;
                border-radius: 8px;
                padding: 16px;
                display: flex;
                align-items: center;
                gap: 12px;
                text-decoration: none;
                transition: all 0.2s;
                border: 2px solid transparent;
            }
            .attachment-item:hover {
                border-color: #467FF7;
                transform: translateX(4px);
            }
            .attachment-icon {
                font-size: 24px;
                flex-shrink: 0;
            }
            .attachment-name {
                font-family: "Poppins", sans-serif;
                font-size: 14px;
                color: #1C244B;
                flex: 1;
                word-break: break-word;
            }
            .attachment-action {
                font-family: "Poppins", sans-serif;
                font-size: 13px;
                color: #467FF7;
                font-weight: 500;
                flex-shrink: 0;
            }

            @media (max-width: 768px) {
                .attachment-name {
                    font-size: 13px;
                }
            }
        </style>';
    }
}
