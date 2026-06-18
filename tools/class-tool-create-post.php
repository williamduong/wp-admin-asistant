<?php

defined('ABSPATH') || exit;

class WAA_Tool_Create_Post extends WAA_Tool_Base {
    public function get_name(): string { return 'create_post'; }

    public function get_description(): string {
        return 'Create a new WordPress post or page. Can download a remote image URL and set it as the featured image. Use Markdown-compatible HTML for content.';
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
                    'description' => 'Post body (HTML or plain text).',
                ],
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['publish', 'draft', 'private'],
                    'description' => 'Post status. Default: draft.',
                ],
                'post_type' => [
                    'type'        => 'string',
                    'description' => 'Post type. Default: post. Use "page" for pages.',
                ],
                'categories' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Category names to assign (created if they do not exist).',
                ],
                'tags' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Tag names to assign.',
                ],
                'featured_image_url' => [
                    'type'        => 'string',
                    'description' => 'Remote image URL to download and set as featured image.',
                ],
            ],
            'required' => ['title'],
        ];
    }

    public function execute(array $input): array {
        $postarr = [
            'post_title'   => sanitize_text_field($input['title']),
            'post_content' => wp_kses_post($input['content'] ?? ''),
            'post_status'  => in_array($input['status'] ?? '', ['publish', 'draft', 'private'], true)
                                  ? $input['status']
                                  : 'draft',
            'post_type'    => sanitize_key($input['post_type'] ?? 'post'),
        ];

        // Resolve category names → IDs (create if missing)
        if (!empty($input['categories']) && is_array($input['categories'])) {
            $cat_ids = [];
            foreach ($input['categories'] as $name) {
                $term = term_exists(sanitize_text_field($name), 'category');
                if (!$term) {
                    $term = wp_insert_term(sanitize_text_field($name), 'category');
                }
                if (!is_wp_error($term)) {
                    $cat_ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
                }
            }
            if ($cat_ids) {
                $postarr['post_category'] = $cat_ids;
            }
        }

        $post_id = wp_insert_post($postarr, true);

        if (is_wp_error($post_id)) {
            return ['success' => false, 'error' => $post_id->get_error_message()];
        }

        // Tags
        if (!empty($input['tags']) && is_array($input['tags'])) {
            wp_set_post_tags($post_id, array_map('sanitize_text_field', $input['tags']));
        }

        // Featured image — download from URL
        $thumbnail_id = null;
        if (!empty($input['featured_image_url'])) {
            try {
                $importer     = new WAA_Media_Importer(new WAA_Resource_Fetcher());
                $thumbnail_id = $importer->import_from_url(
                    $input['featured_image_url'],
                    $postarr['post_title']
                );
                set_post_thumbnail($post_id, $thumbnail_id);
            } catch (Throwable $e) {
                // Non-fatal — post is created, image just failed
                return [
                    'success'       => true,
                    'post_id'       => $post_id,
                    'post_url'      => get_permalink($post_id),
                    'status'        => $postarr['post_status'],
                    'image_warning' => 'Post created but featured image failed: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'success'      => true,
            'post_id'      => $post_id,
            'post_url'     => get_permalink($post_id),
            'status'       => $postarr['post_status'],
            'thumbnail_id' => $thumbnail_id,
        ];
    }
}
