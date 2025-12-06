<?php
/**
 * Plugin Name: ACF File/Image to URL for REST
 * Description: Respects ACF Return Value setting (ID/URL/Array) in REST API responses.
 */

final class ACF_REST_Formatter {
    private static array $fields_cache = [];

    public static function init(): void {
        add_action('rest_api_init', function () {
            foreach (get_post_types(['show_in_rest' => true]) as $type) {
                add_filter("rest_prepare_{$type}", [self::class, 'format_response'], 99, 2);
            }
        });
    }

    public static function format_response(WP_REST_Response $response, WP_Post $post): WP_REST_Response {
        $data = $response->get_data();

        if (empty($data['acf']) || !is_array($data['acf'])) {
            return $response;
        }

        $fields = self::get_fields_config($post->ID);
        $data['acf'] = self::process_acf_data($data['acf'], $fields);
        $response->set_data($data);

        return $response;
    }

    private static function get_fields_config(int $post_id): array {
        if (isset(self::$fields_cache[$post_id])) {
            return self::$fields_cache[$post_id];
        }

        $fields = [];
        $field_objects = get_field_objects($post_id) ?: [];

        foreach ($field_objects as $name => $field) {
            $fields[$name] = self::extract_field_config($field);
        }

        self::$fields_cache[$post_id] = $fields;
        return $fields;
    }

    private static function extract_field_config(array $field): array {
        $config = [
            'type' => $field['type'] ?? '',
            'return_format' => $field['return_format'] ?? 'array',
            'sub_fields' => [],
        ];

        // Handle repeater/group/flexible content sub_fields
        if (!empty($field['sub_fields'])) {
            foreach ($field['sub_fields'] as $sub) {
                $config['sub_fields'][$sub['name']] = self::extract_field_config($sub);
            }
        }

        return $config;
    }

    private static function process_acf_data(array $data, array $fields): array {
        foreach ($data as $key => $value) {
            if (!isset($fields[$key])) {
                continue;
            }

            $field = $fields[$key];
            $type = $field['type'];
            $format = $field['return_format'];

            // Handle repeater/group fields recursively
            if (in_array($type, ['repeater', 'group', 'flexible_content'], true) && !empty($field['sub_fields'])) {
                if ($type === 'group' && is_array($value)) {
                    $data[$key] = self::process_acf_data($value, $field['sub_fields']);
                } elseif (is_array($value)) {
                    $data[$key] = array_map(
                        fn($row) => is_array($row) ? self::process_acf_data($row, $field['sub_fields']) : $row,
                        $value
                    );
                }
                continue;
            }

            if (!in_array($type, ['file', 'image', 'gallery'], true)) {
                continue;
            }

            $data[$key] = self::format_value($value, $format);
        }

        return $data;
    }

    private static function format_value(mixed $value, string $format): mixed {
        if (empty($value)) {
            return $value;
        }

        // ID format - keep as is
        if ($format === 'id') {
            return $value;
        }

        // URL format
        if ($format === 'url') {
            return self::to_url($value);
        }

        // Array format
        if ($format === 'array') {
            return self::to_array($value);
        }

        return $value;
    }

    private static function to_url(mixed $value): mixed {
        if (is_numeric($value)) {
            return wp_get_attachment_url((int) $value) ?: $value;
        }

        if (is_array($value)) {
            if (isset($value['url'])) {
                return $value['url'];
            }
            return array_map([self::class, 'to_url'], $value);
        }

        return $value;
    }

    private static function to_array(mixed $value): mixed {
        if (is_numeric($value)) {
            return acf_get_attachment($value);
        }

        if (is_array($value) && !empty($value)) {
            $first = reset($value);
            if (is_numeric($first)) {
                return array_map('acf_get_attachment', $value);
            }
        }

        return $value;
    }
}

ACF_REST_Formatter::init();
