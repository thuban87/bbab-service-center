<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Utils;

/**
 * Safe wrapper for Pods operations.
 *
 * Provides null-safe access to Pods objects and handles
 * cases where the Pods plugin might not be active.
 */
class Pods {

    /**
     * Get a Pods object safely.
     * Returns null if Pods plugin is not active or pod doesn't exist.
     *
     * @param string   $pod_name Pod name
     * @param int|null $id       Optional post ID
     */
    public static function get(string $pod_name, ?int $id = null): ?\Pods {
        if (!function_exists('pods')) {
            Logger::error('Pods', 'Pods plugin not active');
            return null;
        }

        try {
            $pod = pods($pod_name, $id);
            if (!$pod || !$pod->valid()) {
                return null;
            }
            return $pod;
        } catch (\Exception $e) {
            Logger::error('Pods', 'Failed to get pod', [
                'pod' => $pod_name,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get a pod by its settings key (for type safety).
     */
    public static function getByType(string $type, ?int $id = null): ?\Pods {
        $pod_name = Settings::getPodName($type);
        return self::get($pod_name, $id);
    }

    /**
     * Check if Pods plugin is active.
     */
    public static function isActive(): bool {
        return function_exists('pods');
    }

    /**
     * Get a field value from a pod item safely.
     *
     * @param \Pods  $pod          Pods object
     * @param string $field        Field name
     * @param mixed  $default      Default value
     * @param bool   $single       Return single value (default true)
     */
    public static function field(\Pods $pod, string $field, $default = null, bool $single = true) {
        try {
            $value = $pod->field($field, $single);
            return $value !== null && $value !== '' ? $value : $default;
        } catch (\Exception $e) {
            Logger::error('Pods', 'Failed to get field', [
                'field' => $field,
                'error' => $e->getMessage()
            ]);
            return $default;
        }
    }

    /**
     * Get related items from a relationship field.
     *
     * @param \Pods  $pod   Pods object
     * @param string $field Relationship field name
     * @return array Array of related post IDs
     */
    public static function getRelated(\Pods $pod, string $field): array {
        try {
            $value = $pod->field($field);
            if (empty($value)) {
                return [];
            }

            // Handle both single and multiple relationships
            if (is_array($value)) {
                return array_map('intval', array_column($value, 'ID'));
            }

            if (is_object($value) && isset($value->ID)) {
                return [(int) $value->ID];
            }

            if (is_numeric($value)) {
                return [(int) $value];
            }

            return [];
        } catch (\Exception $e) {
            Logger::error('Pods', 'Failed to get related items', [
                'field' => $field,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get the available options for a Pods field (for dropdowns/lists).
     *
     * Fetches the configured options from the Pods field definition,
     * so you don't have to hardcode status values, etc.
     *
     * @param string $pod_name   Pod name (e.g., 'roadmap_item')
     * @param string $field_name Field name (e.g., 'roadmap_status')
     * @param array  $fallback   Fallback options if Pods unavailable
     * @return array Array of option values
     */
    public static function getFieldOptions(string $pod_name, string $field_name, array $fallback = []): array {
        // Check cache first
        $cache_key = "pods_field_options_{$pod_name}_{$field_name}";
        $cached = Cache::get($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        if (!self::isActive()) {
            return $fallback;
        }

        try {
            // Use pods_api to get field configuration
            if (!function_exists('pods_api')) {
                return $fallback;
            }

            $api = pods_api();
            $field = $api->load_field([
                'pod' => $pod_name,
                'name' => $field_name,
            ]);

            if (!$field || empty($field['options'])) {
                return $fallback;
            }

            $options = [];

            // Handle different field types
            $field_type = $field['type'] ?? '';

            if ($field_type === 'pick' && isset($field['options']['pick_custom'])) {
                // Simple Relationship with custom list - parse the newline-separated values
                $custom_list = $field['options']['pick_custom'] ?? '';
                if (!empty($custom_list)) {
                    $lines = explode("\n", $custom_list);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) {
                            continue;
                        }
                        // Handle "value|label" format or just "value"
                        if (strpos($line, '|') !== false) {
                            $parts = explode('|', $line, 2);
                            $options[] = trim($parts[0]);
                        } else {
                            $options[] = $line;
                        }
                    }
                }
            } elseif (isset($field['options']['text_options'])) {
                // Text-based dropdown
                $text_options = $field['options']['text_options'] ?? '';
                if (!empty($text_options)) {
                    $options = array_map('trim', explode("\n", $text_options));
                    $options = array_filter($options);
                }
            }

            // Cache for 1 hour (field definitions rarely change)
            if (!empty($options)) {
                Cache::set($cache_key, $options, 3600);
                return $options;
            }

            return $fallback;

        } catch (\Exception $e) {
            Logger::error('Pods', 'Failed to get field options', [
                'pod' => $pod_name,
                'field' => $field_name,
                'error' => $e->getMessage()
            ]);
            return $fallback;
        }
    }

    /**
     * Clear the cached field options (call after updating Pods field config).
     *
     * @param string|null $pod_name   Pod name (null to clear all)
     * @param string|null $field_name Field name (null to clear all for pod)
     */
    public static function clearFieldOptionsCache(?string $pod_name = null, ?string $field_name = null): void {
        if ($pod_name && $field_name) {
            Cache::delete("pods_field_options_{$pod_name}_{$field_name}");
        } elseif ($pod_name) {
            Cache::flushPattern("pods_field_options_{$pod_name}_");
        } else {
            Cache::flushPattern('pods_field_options_');
        }
    }
}
