<?php

defined('ABSPATH') || exit;

class WAA_Tool_Activate_Plugin extends WAA_Tool_Base {
    public function get_name(): string { return 'activate_plugin'; }

    public function get_description(): string {
        return 'Activate an installed WordPress plugin. Requires the plugin file path (e.g. "jetpack/jetpack.php").';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'plugin_file' => [
                    'type'        => 'string',
                    'description' => 'Plugin file path relative to plugins directory.',
                ],
            ],
            'required' => ['plugin_file'],
        ];
    }

    public function execute(array $input): array {
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin(sanitize_text_field($input['plugin_file']));

        if (is_wp_error($result)) {
            return ['status' => 'error', 'message' => $result->get_error_message()];
        }

        return ['status' => 'activated', 'plugin' => $input['plugin_file']];
    }
}
