<?php

defined('ABSPATH') || exit;

class WAA_Tool_Switch_Theme extends WAA_Tool_Base {
    public function get_name(): string { return 'switch_theme'; }

    public function get_description(): string {
        return 'Switch the active WordPress theme to an installed theme by its slug.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'theme_slug' => ['type' => 'string', 'description' => 'Theme slug (directory name)'],
            ],
            'required' => ['theme_slug'],
        ];
    }

    public function check_permission(): bool {
        return current_user_can('switch_themes');
    }

    public function execute(array $input): array {
        $slug  = sanitize_text_field($input['theme_slug']);
        $theme = wp_get_theme($slug);

        if (!$theme->exists()) {
            return ['status' => 'error', 'message' => "Theme '$slug' is not installed."];
        }

        switch_theme($slug);

        return ['status' => 'switched', 'active_theme' => $slug];
    }
}
