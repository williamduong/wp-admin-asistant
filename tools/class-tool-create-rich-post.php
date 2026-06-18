<?php

defined('ABSPATH') || exit;

class WAA_Tool_Create_Rich_Post extends WAA_Tool_Base {
    public function get_name(): string { return 'create_rich_post'; }

    public function get_description(): string {
        return 'Create a long, structured WordPress post with a featured image auto-fetched from Pexels based on the topic. Content should use HTML headings (h2, h3), paragraphs, lists, and optionally [mermaid]...[/mermaid] shortcode blocks for diagrams. For short posts without images use create_simple_post instead.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'title' => [
                    'type'        => 'string',
                    'description' => 'Post title.',
                ],
                'content' => [
                    'type'        => 'string',
                    'description' => 'Full post body in HTML. Use <h2> for sections, <h3> for sub-sections, <p>, <ul>/<ol>, <strong>, <em>. For diagrams use [mermaid]flowchart TD\n  A-->B[/mermaid]. Aim for 500–1500 words.',
                ],
                'image_query' => [
                    'type'        => 'string',
                    'description' => 'Search term for the featured image on Pexels. Defaults to the post title if omitted.',
                ],
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['publish', 'draft', 'private'],
                    'description' => 'Default: draft.',
                ],
                'categories' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Category names (created if missing).',
                ],
                'tags' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['title', 'content'],
        ];
    }

    public function execute(array $input): array {
        $title  = sanitize_text_field($input['title']);
        $status = in_array($input['status'] ?? '', ['publish','draft','private'], true)
            ? $input['status'] : 'draft';

        $postarr = [
            'post_title'   => $title,
            'post_content' => wp_kses_post($input['content']),
            'post_status'  => $status,
            'post_type'    => 'post',
        ];

        if (!empty($input['categories'])) {
            $postarr['post_category'] = $this->resolve_categories($input['categories']);
        }

        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) {
            return ['success' => false, 'error' => $post_id->get_error_message()];
        }

        if (!empty($input['tags'])) {
            wp_set_post_tags($post_id, array_map('sanitize_text_field', $input['tags']));
        }

        // Auto-fetch featured image from Pexels
        $image_query    = sanitize_text_field($input['image_query'] ?? $title);
        $attachment_id  = $this->fetch_pexels_image($image_query);
        $image_warning  = null;

        if ($attachment_id) {
            set_post_thumbnail($post_id, $attachment_id);
        } else {
            $image_warning = 'Featured image could not be fetched (Pexels key missing or no results). Add it manually.';
        }

        $result = [
            'success'       => true,
            'post_id'       => $post_id,
            'post_url'      => get_permalink($post_id),
            'status'        => $status,
            'thumbnail_id'  => $attachment_id,
        ];

        if ($image_warning) {
            $result['image_warning'] = $image_warning;
        }

        return $result;
    }

    // ---------------------------------------------------------------

    private function fetch_pexels_image(string $query): ?int {
        $api_key = (new WAA_Settings())->get_pexels_api_key();
        if (empty($api_key)) return null;

        $response = wp_remote_get(
            'https://api.pexels.com/v1/search?' . http_build_query([
                'query'       => $query,
                'per_page'    => 3,
                'orientation' => 'landscape',
            ]),
            [
                'headers' => ['Authorization' => $api_key],
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data  = json_decode(wp_remote_retrieve_body($response), true);
        $photo = $data['photos'][0] ?? null;
        if (!$photo) return null;

        $image_url = $photo['src']['large2x'] ?? $photo['src']['large'] ?? $photo['src']['original'];
        $alt       = $photo['alt'] ?: $query;

        try {
            $importer = new WAA_Media_Importer(new WAA_Resource_Fetcher());
            $id       = $importer->import_from_url($image_url, $alt);
            // Store Pexels attribution
            update_post_meta($id, '_pexels_photographer', $photo['photographer'] ?? '');
            update_post_meta($id, '_pexels_photo_id',     $photo['id'] ?? '');
            return $id;
        } catch (Throwable) {
            return null;
        }
    }

    private function resolve_categories(array $names): array {
        $ids = [];
        foreach ($names as $name) {
            $term = term_exists(sanitize_text_field($name), 'category')
                ?: wp_insert_term(sanitize_text_field($name), 'category');
            if (!is_wp_error($term)) {
                $ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
            }
        }
        return $ids;
    }
}
