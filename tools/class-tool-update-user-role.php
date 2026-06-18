<?php

defined('ABSPATH') || exit;

class WAA_Tool_Update_User_Role extends WAA_Tool_Base {
    private const ALLOWED_ROLES = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];

    public function get_name(): string { return 'update_user_role'; }

    public function get_description(): string {
        return 'Change a WordPress user\'s role. Cannot demote another administrator.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer'],
                'role'    => ['type' => 'string', 'enum' => self::ALLOWED_ROLES],
            ],
            'required' => ['user_id', 'role'],
        ];
    }

    public function check_permission(): bool {
        return current_user_can('promote_users');
    }

    public function execute(array $input): array {
        $user_id = (int) $input['user_id'];
        $role    = sanitize_text_field($input['role']);

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            return ['error' => "Invalid role: $role"];
        }

        $target = get_userdata($user_id);
        if (!$target) {
            return ['error' => 'User not found.'];
        }

        // Protect other admins
        if (in_array('administrator', $target->roles, true) && get_current_user_id() !== $user_id) {
            return ['error' => 'Cannot change the role of another administrator.'];
        }

        wp_update_user(['ID' => $user_id, 'role' => $role]);

        return ['status' => 'updated', 'user_id' => $user_id, 'new_role' => $role];
    }
}
