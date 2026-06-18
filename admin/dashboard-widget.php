<?php

defined('ABSPATH') || exit;

function waa_render_dashboard_widget(): void {
    $logs = WAA_Audit_Log::get_recent(5);

    if (empty($logs)) {
        echo '<p>' . esc_html__('No agent actions yet. Click the chat icon to get started.', 'wp-admin-agent') . '</p>';
        return;
    }

    echo '<ul style="margin:0;padding:0;list-style:none">';
    foreach ($logs as $log) {
        $status_color = $log->status === 'error' ? '#d63638' : '#00a32a';
        printf(
            '<li style="padding:4px 0;border-bottom:1px solid #eee">' .
            '<code style="font-size:12px">%s</code> ' .
            '<span style="color:%s;font-size:11px">%s</span> ' .
            '<span style="color:#999;font-size:11px">— %s ago</span>' .
            '</li>',
            esc_html($log->tool_name),
            esc_attr($status_color),
            esc_html($log->status),
            esc_html(human_time_diff(strtotime($log->created_at)))
        );
    }
    echo '</ul>';
}
