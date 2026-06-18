<?php

defined('ABSPATH') || exit;

class WAA_Tool_Deactivate_Plugin extends WAA_Tool_Base {
    public function get_name(): string { return 'deactivate_plugin'; }

    public function get_description(): string {
        return 'Deactivate an active WordPress plugin. The plugin remains installed — this is reversible.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'plugin_file' => ['type' => 'string'],
            ],
            'required' => ['plugin_file'],
        ];
    }

    public function execute(array $input): array {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins([sanitize_text_field($input['plugin_file'])]);

        return ['status' => 'deactivated', 'plugin' => $input['plugin_file']];
    }
}
