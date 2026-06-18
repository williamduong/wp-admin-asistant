<?php

defined('ABSPATH') || exit;

class WAA_Tool_Update_Settings extends WAA_Tool_Base {
    private const ALLOWED = [
        'blogname', 'blogdescription', 'admin_email',
        'timezone_string', 'date_format', 'time_format',
        'posts_per_page', 'default_comment_status',
        'default_ping_status', 'show_on_front',
    ];

    public function get_name(): string { return 'update_site_settings'; }

    public function get_description(): string {
        return 'Update WordPress site settings. Allowed keys: ' . implode(', ', self::ALLOWED);
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'updates' => [
                    'type'                 => 'object',
                    'description'          => 'Key-value pairs to update.',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
            'required' => ['updates'],
        ];
    }

    public function execute(array $input): array {
        $results = [];
        foreach ((array) $input['updates'] as $key => $value) {
            if (!in_array($key, self::ALLOWED, true)) {
                $results[$key] = ['status' => 'rejected', 'reason' => 'Key not in allowlist'];
                continue;
            }
            $old           = get_option($key);
            $sanitized     = $key === 'admin_email' ? sanitize_email($value) : sanitize_text_field($value);
            $updated       = update_option($key, $sanitized);
            $results[$key] = [
                'status'    => $updated ? 'updated' : 'unchanged',
                'old_value' => $old,
                'new_value' => $sanitized,
            ];
        }
        return ['results' => $results];
    }
}
