<?php

defined('ABSPATH') || exit;

class WAA_Tool_Get_Settings extends WAA_Tool_Base {
    private const KEYS = [
        'blogname', 'blogdescription', 'siteurl', 'admin_email',
        'timezone_string', 'date_format', 'time_format', 'posts_per_page',
        'default_comment_status', 'default_ping_status',
        'show_on_front', 'page_on_front', 'page_for_posts',
    ];

    public function get_name(): string { return 'get_site_settings'; }

    public function get_description(): string {
        return 'Read WordPress site settings: title, tagline, URL, admin email, timezone, date/time formats, posts per page, comment/ping status, homepage settings.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'keys' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Specific keys to retrieve. Omit for all. Valid: ' . implode(', ', self::KEYS),
                ],
            ],
        ];
    }

    public function execute(array $input): array {
        $settings = array_combine(self::KEYS, array_map('get_option', self::KEYS));

        if (!empty($input['keys'])) {
            $requested = array_intersect((array) $input['keys'], self::KEYS);
            $settings  = array_intersect_key($settings, array_flip($requested));
        }

        return ['settings' => $settings];
    }
}
