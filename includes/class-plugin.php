<?php

defined('ABSPATH') || exit;

class WAA_Plugin {
    private static ?self $instance = null;

    public static function get_instance(): static {
        return static::$instance ??= new static();
    }

    public function init(): void {
        new WAA_REST_API();
        $this->register_admin_hooks();
    }

    public static function activate(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE IF NOT EXISTS " . WAA_TABLE_LOGS . " (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id        BIGINT UNSIGNED NOT NULL,
            tool_name      VARCHAR(100)    NOT NULL,
            params         LONGTEXT,
            result         LONGTEXT,
            status         VARCHAR(20)     DEFAULT 'success',
            provider       VARCHAR(50)     DEFAULT '',
            model          VARCHAR(100)    DEFAULT '',
            input_tokens   INT UNSIGNED    DEFAULT 0,
            output_tokens  INT UNSIGNED    DEFAULT 0,
            created_at     DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id  (user_id),
            KEY idx_created  (created_at),
            KEY idx_model    (provider, model)
        ) $charset;");

        dbDelta("CREATE TABLE IF NOT EXISTS " . WAA_TABLE_CONVERSATIONS . " (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NOT NULL,
            title       VARCHAR(255),
            messages    LONGTEXT,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id)
        ) $charset;");

        // Migrate existing tables — add new columns if missing
        self::maybe_migrate_logs_table();

        update_option('waa_db_version', WAA_VERSION);
    }

    private static function maybe_migrate_logs_table(): void {
        global $wpdb;
        $table   = WAA_TABLE_LOGS;
        $columns = $wpdb->get_col("DESCRIBE $table", 0);

        $add = [];
        if (!in_array('provider',      $columns, true)) $add[] = "ADD COLUMN provider      VARCHAR(50)  DEFAULT ''";
        if (!in_array('model',         $columns, true)) $add[] = "ADD COLUMN model         VARCHAR(100) DEFAULT ''";
        if (!in_array('input_tokens',  $columns, true)) $add[] = "ADD COLUMN input_tokens  INT UNSIGNED DEFAULT 0";
        if (!in_array('output_tokens', $columns, true)) $add[] = "ADD COLUMN output_tokens INT UNSIGNED DEFAULT 0";

        if ($add) {
            $wpdb->query("ALTER TABLE $table " . implode(', ', $add));
        }
    }

    public static function deactivate(): void {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'waa_rate_%'");
    }

    private function register_admin_hooks(): void {
        add_action('admin_init',            [$this, 'maybe_handle_settings_save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_footer',          [$this, 'inject_mount_point']);
        add_action('admin_menu',            [$this, 'add_settings_page']);
        add_action('wp_dashboard_setup',    [$this, 'add_dashboard_widget']);
    }

    public function maybe_handle_settings_save(): void {
        if (!is_admin() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $page = $_POST['page'] ?? $_GET['page'] ?? '';
        if (!isset($_POST['_wpnonce']) || $page !== 'wp-admin-agent') {
            return;
        }

        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'waa_settings')) {
            return;
        }

        $settings = new WAA_Settings();

        if (!empty($_POST['waa_provider'])) {
            $settings->set_provider(sanitize_text_field($_POST['waa_provider']));
        }

        if (!empty($_POST['waa_model'])) {
            $settings->set_model(sanitize_text_field($_POST['waa_model']));
        }

        if (!empty($_POST['waa_api_key']) && $_POST['waa_api_key'] !== '••••••••') {
            $settings->set_api_key(sanitize_text_field($_POST['waa_api_key']));
        }

        if (!empty($_POST['waa_gemini_key']) && $_POST['waa_gemini_key'] !== '••••••••') {
            $settings->set_gemini_api_key(sanitize_text_field($_POST['waa_gemini_key']));
        }

        if (!empty($_POST['waa_ollama_url'])) {
            $settings->set_ollama_url(sanitize_text_field($_POST['waa_ollama_url']));
        }

        if (!empty($_POST['waa_pexels_key']) && $_POST['waa_pexels_key'] !== '••••••••') {
            $settings->set_pexels_api_key(sanitize_text_field($_POST['waa_pexels_key']));
        }

        if (isset($_POST['waa_debug_mode'])) {
            $settings->set_debug_mode(sanitize_key($_POST['waa_debug_mode']));
        }

        // Custom rules (textarea — may be empty, that's valid)
        if (isset($_POST['waa_custom_rules'])) {
            $settings->set_custom_rules(wp_unslash($_POST['waa_custom_rules']));
        }

        // Disabled tools are updated only when the Tools tab is submitted.
        // Otherwise, preserve the existing tool enable/disable state.
        if (!empty($_POST['tab']) && $_POST['tab'] === 'tools') {
            $submitted_enabled = array_keys(array_filter($_POST, fn($k) => str_starts_with($k, 'waa_tool_'), ARRAY_FILTER_USE_KEY));
            $enabled_names     = array_map(fn($k) => substr($k, strlen('waa_tool_')), $submitted_enabled);
            $all_tools         = array_column(WAA_REST_API::build_registry()->get_schemas(), 'name');
            $disabled          = array_values(array_diff($all_tools, $enabled_names));
            $settings->set_disabled_tools($disabled);
        }

        wp_redirect(add_query_arg('saved', '1', menu_page_url('wp-admin-agent', false)));
        exit;
    }

    public function enqueue_assets(): void {
        if (!current_user_can('manage_options')) return;

        $js_path = WAA_PLUGIN_DIR . 'assets/js/admin-agent.js';
        $version = file_exists($js_path) ? filemtime($js_path) : WAA_VERSION;

        wp_enqueue_script(
            'waa-admin-agent',
            WAA_PLUGIN_URL . 'assets/js/admin-agent.js',
            [],
            $version,
            true
        );

        $css_path = WAA_PLUGIN_DIR . 'assets/css/admin-agent.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'waa-admin-agent',
                WAA_PLUGIN_URL . 'assets/css/admin-agent.css',
                [],
                $version
            );
        }

        $settings = new WAA_Settings();
        wp_localize_script('waa-admin-agent', 'waaData', [
            'nonce'       => wp_create_nonce('wp_rest'),
            'restUrl'     => rest_url('wp-admin-agent/v1/'),
            'currentUser' => [
                'id'   => get_current_user_id(),
                'name' => wp_get_current_user()->display_name,
            ],
            'siteUrl'     => get_site_url(),
            'version'     => WAA_VERSION,
            'provider'    => $settings->get_provider(),
            'model'       => $settings->get_model(),
            'pricing'     => WAA_Pricing::all_for_js(),
            'debugMode'   => $settings->get_debug_mode(),
        ]);
    }

    public function inject_mount_point(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div id="waa-root"></div>';
    }

    public function add_settings_page(): void {
        add_options_page(
            'WP Admin Agent',
            'Admin Agent',
            'manage_options',
            'wp-admin-agent',
            function () {
                require_once WAA_PLUGIN_DIR . 'admin/settings-page.php';
            }
        );
    }

    public function add_dashboard_widget(): void {
        if (!current_user_can('manage_options')) return;
        wp_add_dashboard_widget(
            'waa_recent_actions',
            'Recent Agent Actions',
            function () {
                require_once WAA_PLUGIN_DIR . 'admin/dashboard-widget.php';
                waa_render_dashboard_widget();
            }
        );
    }
}
