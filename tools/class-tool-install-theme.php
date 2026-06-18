<?php

defined('ABSPATH') || exit;

class WAA_Tool_Install_Theme extends WAA_Tool_Base {
    public function get_name(): string { return 'install_theme'; }

    public function get_description(): string {
        return 'Download and install a theme from the WordPress.org directory using its slug. The theme is installed but NOT activated — call switch_theme afterward with the returned theme_slug. Always call search_themes or list_themes first to confirm the correct slug.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'slug' => [
                    'type'        => 'string',
                    'description' => 'WordPress.org theme slug, e.g. "twentytwentyfour", "astra", "blocksy".',
                ],
            ],
            'required' => ['slug'],
        ];
    }

    public function execute(array $input): array {
        $slug = sanitize_key($input['slug'] ?? '');
        if (!$slug) {
            return ['success' => false, 'error' => 'Theme slug is required.'];
        }

        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Check if already installed
        $installed = wp_get_themes();
        if (isset($installed[$slug])) {
            $theme = $installed[$slug];
            return [
                'success'           => true,
                'already_installed' => true,
                'theme_slug'        => $slug,
                'name'              => $theme->get('Name'),
                'version'           => $theme->get('Version'),
                'message'           => "Theme \"{$theme->get('Name')}\" is already installed. Call switch_theme with theme_slug: \"$slug\" to activate it.",
            ];
        }

        // Fetch theme info from WordPress.org
        $api = themes_api('theme_information', [
            'slug'   => $slug,
            'fields' => ['sections' => false, 'tags' => false],
        ]);

        if (is_wp_error($api)) {
            return ['success' => false, 'error' => 'Theme not found in WordPress.org directory: ' . $api->get_error_message()];
        }

        // Download and unzip — same pattern as install_plugin
        $tmp_zip = download_url($api->download_link, 120);

        if (is_wp_error($tmp_zip)) {
            return ['success' => false, 'error' => 'Download failed: ' . $tmp_zip->get_error_message()];
        }

        if (!defined('FS_CHMOD_DIR')) {
            define('FS_CHMOD_DIR', (fileperms(ABSPATH) & 0777 | 0755));
        }
        if (!defined('FS_CHMOD_FILE')) {
            define('FS_CHMOD_FILE', (fileperms(ABSPATH . 'index.php') & 0777 | 0644));
        }

        WP_Filesystem();
        global $wp_filesystem;

        if (!$wp_filesystem) {
            @unlink($tmp_zip);
            return ['success' => false, 'error' => 'WP Filesystem unavailable — check server permissions.'];
        }

        $result = unzip_file($tmp_zip, get_theme_root());
        @unlink($tmp_zip);

        if (is_wp_error($result)) {
            return ['success' => false, 'error' => 'Unzip failed: ' . $result->get_error_message()];
        }

        // Verify it's there now
        wp_clean_themes_cache();
        $installed_after = wp_get_themes();

        if (!isset($installed_after[$slug])) {
            return ['success' => false, 'error' => "Theme installed but could not be found. Try using slug: \"$slug\" manually."];
        }

        $theme_name = $installed_after[$slug]->get('Name');

        return [
            'success'    => true,
            'theme_slug' => $slug,
            'name'       => $theme_name,
            'version'    => $api->version,
            'message'    => "Theme \"$theme_name\" v{$api->version} installed successfully. Now call switch_theme with theme_slug: \"$slug\" to activate it.",
            '_navigate_url' => admin_url('themes.php'),
        ];
    }
}
