<?php
/**
 * Plugin Name:       WP Admin Agent
 * Plugin URI:        https://github.com/goright-ai/wp-admin-agent
 * Description:       Natural language AI assistant for WordPress admin settings.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            GoRight AI
 * Author URI:        https://goright.ai
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-admin-agent
 */

defined('ABSPATH') || exit;

define('WAA_VERSION',             '0.2.0');
define('WAA_PLUGIN_DIR',          plugin_dir_path(__FILE__));
define('WAA_PLUGIN_URL',          plugin_dir_url(__FILE__));
define('WAA_TABLE_LOGS',          $GLOBALS['wpdb']->prefix . 'waa_logs');
define('WAA_TABLE_CONVERSATIONS', $GLOBALS['wpdb']->prefix . 'waa_conversations');
define('WAA_MAX_TOOL_ITERATIONS', 10);
define('WAA_RATE_LIMIT',          30);

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'WAA_Tool_' => WAA_PLUGIN_DIR . 'tools/class-tool-',
        'WAA_'      => WAA_PLUGIN_DIR . 'includes/class-',
    ];
    foreach ($prefixes as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) continue;
        $suffix = strtolower(str_replace('_', '-', substr($class, strlen($prefix))));
        $file   = $base . $suffix . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

require_once WAA_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook(__FILE__,   ['WAA_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WAA_Plugin', 'deactivate']);

add_action('plugins_loaded', function () {
    WAA_Plugin::get_instance()->init();
    WAA_Mermaid::init();
});

// Runtime security hooks (activated via security_harden tool)
if (get_option('waa_xmlrpc_disabled')) {
    add_filter('xmlrpc_enabled', '__return_false');
}
if (get_option('waa_hide_wp_version')) {
    add_filter('the_generator', '__return_empty_string');
    remove_action('wp_head', 'wp_generator');
}
