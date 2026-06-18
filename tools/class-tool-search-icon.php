<?php

defined('ABSPATH') || exit;

/**
 * Search free icons via Iconify API (https://iconify.design).
 * 200,000+ icons, no API key required, returns direct SVG URLs.
 *
 * Typical flow:
 *   1. User: "change site icon to something related to robots"
 *   2. Bot calls search_icon({ query: "robot" })
 *   3. Bot picks a result URL, calls set_site_icon({ image_url: "..." })
 */
class WAA_Tool_Search_Icon extends WAA_Tool_Base {
    private const API = 'https://api.iconify.design';

    public function get_name(): string { return 'search_icon'; }

    public function get_description(): string {
        return 'Search for free icons by keyword using the Iconify library (200k+ icons, no attribution required). '
             . 'Returns direct SVG URLs ready to pass to set_site_icon. '
             . 'Always call this first when the user asks to change the site icon without providing a specific URL.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Search keyword in English, e.g. "robot", "gear", "wordpress", "star".',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Number of results to return (1–8, default 5).',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input): array {
        $query = sanitize_text_field($input['query'] ?? '');
        $limit = min(8, max(1, (int) ($input['limit'] ?? 5)));

        if (empty($query)) {
            throw new RuntimeException('query is required.');
        }

        $response = wp_remote_get(
            self::API . '/search?' . http_build_query(['query' => $query, 'limit' => $limit]),
            ['timeout' => 10, 'user-agent' => 'WordPress WAA-Bot/1.0']
        );

        if (is_wp_error($response)) {
            throw new RuntimeException('Icon search failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || !isset($body['icons'])) {
            throw new RuntimeException("Iconify returned HTTP $code");
        }

        $results = [];
        foreach ($body['icons'] as $icon) {
            [$collection, $name] = explode(':', $icon, 2);
            $results[] = [
                'id'  => $icon,
                'url' => self::API . "/{$collection}/{$name}.svg",
            ];
        }

        return [
            'icons' => $results,
            'total' => $body['total'] ?? count($results),
            'hint'  => 'Pass the url of your chosen icon to set_site_icon.',
        ];
    }
}
