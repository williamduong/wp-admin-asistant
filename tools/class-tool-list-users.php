<?php

defined('ABSPATH') || exit;

class WAA_Tool_List_Users extends WAA_Tool_Base {
    public function get_name(): string { return 'list_users'; }

    public function get_description(): string {
        return 'List WordPress users, optionally filtered by role.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'role'   => [
                    'type'        => 'string',
                    'description' => 'Filter by role: administrator, editor, author, contributor, subscriber',
                ],
                'number' => [
                    'type'        => 'integer',
                    'default'     => 20,
                    'description' => 'Max users to return (1–100)',
                ],
            ],
        ];
    }

    public function execute(array $input): array {
        $args = [
            'number' => min((int) ($input['number'] ?? 20), 100),
            'fields' => ['ID', 'user_login', 'user_email', 'display_name'],
        ];

        if (!empty($input['role'])) {
            $args['role'] = sanitize_text_field($input['role']);
        }

        $users = get_users($args);

        return [
            'users' => array_map(function ($u) {
                return [
                    'id'           => $u->ID,
                    'login'        => $u->user_login,
                    'email'        => $u->user_email,
                    'display_name' => $u->display_name,
                    'roles'        => get_userdata($u->ID)->roles,
                ];
            }, $users),
            'total' => count($users),
        ];
    }
}
