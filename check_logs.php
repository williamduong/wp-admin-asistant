<?php
require_once __DIR__ . '/wp-load.php';

global $wpdb;
$table = $wpdb->prefix . 'waa_logs';
$logs = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 10");

echo "Recent WAA Logs:\n";
foreach ($logs as $log) {
    echo sprintf(
        "[%s] %s: %s (%s) - %s\n",
        $log->created_at,
        $log->tool_name,
        $log->status,
        $log->provider,
        substr($log->input, 0, 100) . (strlen($log->input) > 100 ? '...' : '')
    );
}
?>