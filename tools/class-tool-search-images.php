<?php

defined('ABSPATH') || exit;

class WAA_Tool_Search_Images extends WAA_Tool_Base {
    public function get_name(): string { return 'search_images'; }

    public function get_description(): string {
        return 'Search Pexels for royalty-free photos and import the best matches into the WordPress Media Library. Returns attachment IDs ready to use with set_post_image. Requires a Pexels API key in Settings.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Search query (English works best, e.g. "artificial intelligence", "coffee shop").',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Number of images to import (1–5). Default: 1.',
                    'minimum'     => 1,
                    'maximum'     => 5,
                ],
                'orientation' => [
                    'type'        => 'string',
                    'enum'        => ['landscape', 'portrait', 'square'],
                    'description' => 'Image orientation. Default: landscape.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input): array {
        $api_key = (new WAA_Settings())->get_pexels_api_key();
        if (!$api_key) {
            return ['success' => false, 'error' => 'Pexels API key not configured. Add it in Settings → Media & Images.'];
        }

        $query       = sanitize_text_field($input['query'] ?? '');
        $limit       = max(1, min(5, (int) ($input['limit'] ?? 1)));
        $orientation = in_array($input['orientation'] ?? '', ['landscape', 'portrait', 'square'], true)
                           ? $input['orientation']
                           : 'landscape';

        $response = wp_remote_get(add_query_arg([
            'query'       => $query,
            'per_page'    => $limit,
            'orientation' => $orientation,
        ], 'https://api.pexels.com/v1/search'), [
            'timeout' => 20,
            'headers' => ['Authorization' => $api_key],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            return ['success' => false, 'error' => "Pexels API error (HTTP $code): " . ($body['error'] ?? 'unknown')];
        }

        $photos = $body['photos'] ?? [];
        if (empty($photos)) {
            return ['success' => false, 'error' => "No photos found for query: \"$query\""];
        }

        $importer = new WAA_Media_Importer(new WAA_Resource_Fetcher());
        $imported = [];

        foreach ($photos as $photo) {
            $url   = $photo['src']['large2x'] ?? $photo['src']['large'] ?? $photo['src']['original'];
            $title = sanitize_text_field($photo['alt'] ?: $query);

            try {
                $attachment_id = $importer->import_from_url($url, $title);

                // Store attribution as attachment meta
                update_post_meta($attachment_id, '_pexels_photographer', sanitize_text_field($photo['photographer']));
                update_post_meta($attachment_id, '_pexels_photo_url',   esc_url_raw($photo['url']));

                // Set alt text
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $title);

                $imported[] = [
                    'attachment_id' => $attachment_id,
                    'title'         => $title,
                    'photographer'  => $photo['photographer'],
                    'source_url'    => $photo['url'],
                    'media_url'     => wp_get_attachment_url($attachment_id),
                ];
            } catch (Throwable $e) {
                $imported[] = ['error' => $e->getMessage(), 'photo_id' => $photo['id']];
            }
        }

        $successful = array_filter($imported, fn($i) => isset($i['attachment_id']));

        return [
            'success'  => !empty($successful),
            'imported' => $imported,
            'message'  => sprintf('%d image(s) imported to Media Library. Use set_post_image with the attachment_id to attach to a post.', count($successful)),
        ];
    }
}
