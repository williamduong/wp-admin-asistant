<?php

defined('ABSPATH') || exit;

class WAA_Tool_List_Themes extends WAA_Tool_Base {
    public function get_name(): string { return 'list_themes'; }

    public function get_description(): string {
        return 'List all installed WordPress themes with name, version, and active status.';
    }

    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): array {
        $active  = get_stylesheet();
        $themes  = wp_get_themes();
        $list    = [];

        foreach ($themes as $slug => $theme) {
            $list[] = [
                'slug'        => $slug,
                'name'        => $theme->get('Name'),
                'version'     => $theme->get('Version'),
                'description' => wp_trim_words($theme->get('Description'), 20),
                'author'      => $theme->get('Author'),
                'active'      => $slug === $active,
            ];
        }

        return ['themes' => $list, 'active_theme' => $active];
    }
}
