<?php

defined('ABSPATH') || exit;

class WAA_Tool_List_Posts extends WAA_Tool_Base {
    public function get_name(): string { return 'list_posts'; }

    public function get_description(): string {
        return 'List WordPress posts or pages with title, status, date, and URL.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'post_type' => ['type' => 'string', 'default' => 'post', 'description' => 'post or page'],
                'status'    => ['type' => 'string', 'default' => 'any', 'description' => 'publish, draft, private, any'],
                'number'    => ['type' => 'integer', 'default' => 10],
                'search'    => ['type' => 'string', 'description' => 'Keyword search'],
            ],
        ];
    }

    public function execute(array $input): array {
        $args = [
            'post_type'      => in_array($input['post_type'] ?? 'post', ['post', 'page'], true) ? $input['post_type'] : 'post',
            'post_status'    => sanitize_text_field($input['status'] ?? 'any'),
            'posts_per_page' => min((int) ($input['number'] ?? 10), 50),
        ];

        if (!empty($input['search'])) {
            $args['s'] = sanitize_text_field($input['search']);
        }

        $query = new WP_Query($args);
        $posts = [];

        foreach ($query->posts as $post) {
            $posts[] = [
                'id'         => $post->ID,
                'title'      => $post->post_title,
                'status'     => $post->post_status,
                'date'       => $post->post_date,
                'url'        => get_permalink($post->ID),
                'edit_url'   => get_edit_post_link($post->ID, 'raw'),
                'post_type'  => $post->post_type,
            ];
        }

        return ['posts' => $posts, 'total' => $query->found_posts];
    }
}
