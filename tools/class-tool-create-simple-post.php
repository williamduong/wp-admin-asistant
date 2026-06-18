<?php

defined('ABSPATH') || exit;

class WAA_Tool_Create_Simple_Post extends WAA_Tool_Base {
    public function get_name(): string { return 'create_simple_post'; }

    public function get_description(): string {
        return 'Create a short WordPress post (1–3 paragraphs). Use for quick news snippets, announcements, or brief updates. No featured image. For longer structured posts with images use create_rich_post instead.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'title'      => ['type' => 'string', 'description' => 'Post title.'],
                'content'    => ['type' => 'string', 'description' => 'Post body — plain text or basic HTML (p, strong, em, ul, li, a). Keep to 1–3 short paragraphs.'],
                'status'     => ['type' => 'string', 'enum' => ['publish','draft','private'], 'description' => 'Default: draft.'],
                'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Category names (created if missing).'],
                'tags'       => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['title', 'content'],
        ];
    }

    public function execute(array $input): array {
        $postarr = [
            'post_title'   => sanitize_text_field($input['title']),
            'post_content' => wp_kses_post($input['content']),
            'post_status'  => in_array($input['status'] ?? '', ['publish','draft','private'], true) ? $input['status'] : 'draft',
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

        return [
            'success'  => true,
            'post_id'  => $post_id,
            'post_url' => get_permalink($post_id),
            'status'   => $postarr['post_status'],
        ];
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
