<?php

defined('ABSPATH') || exit;

class WAA_Tool_Search_Themes extends WAA_Tool_Base {
    public function get_name(): string { return 'search_themes'; }

    public function get_description(): string {
        return 'Search the WordPress.org theme directory. Returns themes with slug, name, description, author, rating, downloads, and preview URL. Call this before install_theme to find the correct slug.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Search term, e.g. "minimalist blog", "ecommerce", "portfolio dark".',
                ],
                'per_page' => [
                    'type'        => 'integer',
                    'description' => 'Number of results to return (1–10). Default 5.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input): array {
        require_once ABSPATH . 'wp-admin/includes/theme.php';

        $query    = sanitize_text_field($input['query'] ?? '');
        $per_page = max(1, min(10, (int) ($input['per_page'] ?? 5)));

        $api = themes_api('query_themes', [
            'search'   => $query,
            'per_page' => $per_page,
            'fields'   => [
                'description'  => true,
                'rating'       => true,
                'downloaded'   => true,
                'screenshot_url' => true,
                'preview_url'  => true,
                'num_ratings'  => true,
                'active_installs' => true,
            ],
        ]);

        if (is_wp_error($api)) {
            return ['success' => false, 'error' => 'WordPress.org API error: ' . $api->get_error_message()];
        }

        $installed = array_keys(wp_get_themes());
        $themes    = [];

        foreach ($api->themes as $theme) {
            $themes[] = [
                'slug'             => $theme->slug,
                'name'             => $theme->name,
                'author'           => $theme->author,
                'version'          => $theme->version,
                'description'      => wp_trim_words(strip_tags($theme->description ?? ''), 30),
                'rating'           => round($theme->rating / 20, 1) . '/5',
                'active_installs'  => $theme->active_installs ?? 0,
                'preview_url'      => $theme->preview_url ?? '',
                'screenshot_url'   => $theme->screenshot_url ?? '',
                'already_installed' => in_array($theme->slug, $installed, true),
            ];
        }

        return [
            'success' => true,
            'query'   => $query,
            'total'   => $api->info['results'] ?? count($themes),
            'themes'  => $themes,
        ];
    }
}
