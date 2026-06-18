<?php

defined('ABSPATH') || exit;

class WAA_Tool_Navigate extends WAA_Tool_Base {
    public function get_name(): string { return 'navigate'; }

    public function get_description(): string {
        return 'Navigate the user\'s browser to any WordPress admin page. Use relative paths like "plugins.php", "edit.php", "options-general.php". Use focus_selector (CSS ID like "#timezone_string") to scroll directly to a specific field.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'page' => [
                    'type'        => 'string',
                    'description' => 'wp-admin relative path (e.g. "plugins.php", "edit.php?post_type=page", "options-general.php") or a full URL.',
                ],
                'focus_selector' => [
                    'type'        => 'string',
                    'description' => 'Optional CSS ID selector to scroll to after navigation (e.g. "#timezone_string", "#blogname"). Must start with #.',
                ],
            ],
            'required' => ['page'],
        ];
    }

    public function execute(array $input): array {
        $page = trim($input['page'] ?? '');
        if (!$page) {
            return ['success' => false, 'error' => 'page is required.'];
        }

        $url = filter_var($page, FILTER_VALIDATE_URL) ? $page : admin_url($page);

        // Append CSS ID hash for native browser scroll
        $focus = trim($input['focus_selector'] ?? '');
        if ($focus && str_starts_with($focus, '#') && !str_contains($url, '#')) {
            $url .= $focus;
        }

        return [
            'success'        => true,
            '_navigate_url'  => esc_url_raw($url),
            'message'        => "Navigating to: $url",
        ];
    }
}
