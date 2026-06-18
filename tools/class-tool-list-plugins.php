<?php

defined('ABSPATH') || exit;

class WAA_Tool_List_Plugins extends WAA_Tool_Base {
    public function get_name(): string { return 'list_plugins'; }

    public function get_description(): string {
        return 'List all installed WordPress plugins with name, version, active status, and description.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'status' => [
                    'type'    => 'string',
                    'enum'    => ['all', 'active', 'inactive'],
                    'default' => 'all',
                ],
            ],
        ];
    }

    public function execute(array $input): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $filter         = $input['status'] ?? 'all';
        $list           = [];

        foreach ($all_plugins as $file => $data) {
            $is_active = in_array($file, $active_plugins, true);
            if ($filter === 'active'   && !$is_active) continue;
            if ($filter === 'inactive' &&  $is_active) continue;

            $list[] = [
                'file'        => $file,
                'name'        => $data['Name'],
                'version'     => $data['Version'],
                'description' => wp_trim_words($data['Description'], 20),
                'status'      => $is_active ? 'active' : 'inactive',
                'author'      => $data['Author'],
            ];
        }

        return ['plugins' => $list, 'total' => count($list)];
    }
}
