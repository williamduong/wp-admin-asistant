<?php

defined('ABSPATH') || exit;

class WAA_Tool_Install_Plugin extends WAA_Tool_Base {
    public function get_name(): string { return 'install_plugin'; }

    public function get_description(): string {
        return 'Download and install a plugin from the WordPress.org plugin directory using its slug. The plugin is installed but NOT activated — call activate_plugin afterward with the returned plugin_file. Always call list_plugins first to check if already installed.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'slug' => [
                    'type'        => 'string',
                    'description' => 'WordPress.org plugin slug (e.g. "wordfence", "woocommerce", "contact-form-7").',
                ],
            ],
            'required' => ['slug'],
        ];
    }

    public function execute(array $input): array {
        $slug = sanitize_key($input['slug'] ?? '');
        if (!$slug) {
            return ['success' => false, 'error' => 'Plugin slug is required.'];
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Check if already installed
        $existing = get_plugins();
        foreach ($existing as $file => $data) {
            if (str_starts_with($file, $slug . '/') || $file === $slug . '.php') {
                return [
                    'success'           => true,
                    'already_installed' => true,
                    'plugin_file'       => $file,
                    'name'              => $data['Name'],
                    'version'           => $data['Version'],
                    'message'           => "Plugin \"{$data['Name']}\" is already installed (v{$data['Version']}). Call activate_plugin with plugin_file: \"{$file}\".",
                ];
            }
        }

        // Fetch plugin info from WordPress.org
        $api = plugins_api('plugin_information', [
            'slug'   => $slug,
            'fields' => ['short_description' => false, 'sections' => false, 'tags' => false],
        ]);

        if (is_wp_error($api)) {
            return ['success' => false, 'error' => 'Plugin not found in WordPress.org directory: ' . $api->get_error_message()];
        }

        // Use download_url + unzip_file — more reliable than Plugin_Upgrader in REST context
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

        $result = unzip_file($tmp_zip, WP_PLUGIN_DIR);
        @unlink($tmp_zip);

        if (is_wp_error($result)) {
            return ['success' => false, 'error' => 'Unzip failed: ' . $result->get_error_message()];
        }

        // Find the installed plugin file (refresh cache)
        wp_cache_delete('plugins', 'plugins');
        $plugin_file = $slug . '/' . $slug . '.php'; // best-guess default
        foreach (array_keys(get_plugins()) as $file) {
            if (str_starts_with($file, $slug . '/')) {
                $plugin_file = $file;
                break;
            }
        }

        return [
            'success'     => true,
            'name'        => $api->name,
            'version'     => $api->version,
            'plugin_file' => $plugin_file,
            'message'     => "Plugin \"{$api->name}\" v{$api->version} installed successfully. Now call activate_plugin with plugin_file: \"{$plugin_file}\".",
        ];
    }
}
