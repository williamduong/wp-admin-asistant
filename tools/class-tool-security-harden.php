<?php

defined('ABSPATH') || exit;

class WAA_Tool_Security_Harden extends WAA_Tool_Base {
    public function get_name(): string { return 'security_harden'; }

    public function get_description(): string {
        return 'Apply WordPress security hardening settings. Can disable user registration, disable XML-RPC, close comments globally, discourage search engines, disable pingbacks, remove WordPress version from page headers, and enforce strong password policy hint. Call get_site_settings first to check current state.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'disable_registration' => [
                    'type'        => 'boolean',
                    'description' => 'Prevent new user self-registration.',
                ],
                'disable_xmlrpc' => [
                    'type'        => 'boolean',
                    'description' => 'Completely disable XML-RPC endpoint (blocks pingbacks + remote publishing).',
                ],
                'close_comments' => [
                    'type'        => 'boolean',
                    'description' => 'Disable comments on all new posts globally.',
                ],
                'discourage_search_engines' => [
                    'type'        => 'boolean',
                    'description' => 'Ask search engines not to index this site (sets blog_public = 0).',
                ],
                'disable_pingbacks' => [
                    'type'        => 'boolean',
                    'description' => 'Disable incoming pingbacks/trackbacks.',
                ],
                'hide_wp_version' => [
                    'type'        => 'boolean',
                    'description' => 'Remove WordPress version number from page source and feeds.',
                ],
                'disable_file_edit' => [
                    'type'        => 'boolean',
                    'description' => 'Disable the theme/plugin file editor in wp-admin. Adds a must-use plugin to enforce this (cannot modify wp-config.php directly).',
                ],
            ],
        ];
    }

    public function execute(array $input): array {
        $applied = [];
        $skipped = [];

        if ($input['disable_registration'] ?? false) {
            update_option('users_can_register', 0);
            $applied[] = 'User registration disabled';
        }

        if ($input['disable_xmlrpc'] ?? false) {
            update_option('waa_xmlrpc_disabled', 1);
            $applied[] = 'XML-RPC disabled (via filter hook — active immediately)';
        }

        if ($input['close_comments'] ?? false) {
            update_option('default_comment_status', 'closed');
            update_option('default_ping_status', 'closed');
            $applied[] = 'Comments and pingbacks closed on new posts';
        }

        if ($input['discourage_search_engines'] ?? false) {
            update_option('blog_public', 0);
            $applied[] = 'Search engine indexing discouraged (blog_public = 0)';
        }

        if ($input['disable_pingbacks'] ?? false) {
            update_option('default_ping_status', 'closed');
            $applied[] = 'Pingbacks disabled';
        }

        if ($input['hide_wp_version'] ?? false) {
            update_option('waa_hide_wp_version', 1);
            $applied[] = 'WordPress version hidden from page source';
        }

        if ($input['disable_file_edit'] ?? false) {
            $this->install_disable_file_edit_mu();
            $applied[] = 'File editor disabled via must-use plugin';
        }

        return [
            'success'  => true,
            'applied'  => $applied,
            'skipped'  => $skipped,
            'message'  => count($applied) > 0
                ? 'Applied ' . count($applied) . ' hardening setting(s): ' . implode('; ', $applied)
                : 'No changes requested.',
            'note'     => 'To fully disable XML-RPC and hide WP version, the plugin adds runtime hooks on every request. DISALLOW_FILE_EDIT in wp-config.php provides deeper protection — add manually to your server.',
        ];
    }

    private function install_disable_file_edit_mu(): void {
        $mu_dir = WPMU_PLUGIN_DIR;
        if (!is_dir($mu_dir)) {
            wp_mkdir_p($mu_dir);
        }
        $file = $mu_dir . '/waa-security.php';
        if (!file_exists($file)) {
            file_put_contents($file, "<?php\n// WP Admin Agent — security hardening\ndefine('DISALLOW_FILE_EDIT', true);\n");
        }
    }
}
