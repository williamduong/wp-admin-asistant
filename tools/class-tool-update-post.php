<?php

defined('ABSPATH') || exit;

class WAA_Tool_Update_Post extends WAA_Tool_Base {
    public function get_name(): string { return 'update_post'; }

    public function get_description(): string {
        return 'Update a WordPress post or page title, content, or status.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id' => ['type' => 'integer'],
                'title'   => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'status'  => ['type' => 'string', 'enum' => ['publish', 'draft', 'private', 'trash']],
            ],
            'required' => ['post_id'],
        ];
    }

    public function execute(array $input): array {
        $post_id = (int) $input['post_id'];

        if (!get_post($post_id)) {
            return ['error' => "Post $post_id not found."];
        }

        $update = ['ID' => $post_id];

        if (!empty($input['title'])) {
            $update['post_title'] = sanitize_text_field($input['title']);
        }
        if (!empty($input['content'])) {
            $update['post_content'] = wp_kses_post($input['content']);
        }
        if (!empty($input['status'])) {
            $update['post_status'] = sanitize_text_field($input['status']);
        }

        $result = wp_update_post($update, true);

        if (is_wp_error($result)) {
            return ['error' => $result->get_error_message()];
        }

        return ['status' => 'updated', 'post_id' => $post_id];
    }
}
