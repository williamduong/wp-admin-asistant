<?php

defined('ABSPATH') || exit;

class WAA_Audit_Log {
    public function write(string $tool, array $params, array $result, array $meta = []): void {
        global $wpdb;
        $wpdb->insert(WAA_TABLE_LOGS, [
            'user_id'       => get_current_user_id(),
            'tool_name'     => $tool,
            'params'        => wp_json_encode($params),
            'result'        => wp_json_encode($result),
            'status'        => isset($result['error']) ? 'error' : 'success',
            'provider'      => $meta['provider']      ?? '',
            'model'         => $meta['model']         ?? '',
            'input_tokens'  => $meta['input_tokens']  ?? 0,
            'output_tokens' => $meta['output_tokens'] ?? 0,
            'created_at'    => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s']);
    }

    public static function get_recent(int $limit = 10): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . WAA_TABLE_LOGS . " ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }

    public static function get_stats(string $period = '30'): array {
        global $wpdb;
        $table = WAA_TABLE_LOGS;

        $days = (int) $period;

        // Aggregate totals
        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*)             AS total_calls,
                SUM(input_tokens)    AS total_input,
                SUM(output_tokens)   AS total_output,
                SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) AS total_errors
             FROM $table
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ), ARRAY_A);

        // By model
        $by_model = $wpdb->get_results($wpdb->prepare(
            "SELECT
                provider, model,
                COUNT(*)          AS calls,
                SUM(input_tokens) AS input_tokens,
                SUM(output_tokens) AS output_tokens
             FROM $table
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
               AND model != ''
             GROUP BY provider, model
             ORDER BY calls DESC",
            $days
        ), ARRAY_A);

        // Per-day (last 7 days)
        $daily = $wpdb->get_results(
            "SELECT
                DATE(created_at) AS day,
                COUNT(*)          AS calls,
                SUM(input_tokens) AS input_tokens,
                SUM(output_tokens) AS output_tokens
             FROM $table
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at)
             ORDER BY day ASC",
            ARRAY_A
        );

        // Top tools
        $top_tools = $wpdb->get_results($wpdb->prepare(
            "SELECT tool_name, COUNT(*) AS calls
             FROM $table
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY tool_name
             ORDER BY calls DESC
             LIMIT 10",
            $days
        ), ARRAY_A);

        // Enrich by_model with cost
        $settings = new WAA_Settings();
        foreach ($by_model as &$row) {
            $row['cost_usd'] = WAA_Pricing::calculate(
                $row['provider'],
                $row['model'],
                (int) $row['input_tokens'],
                (int) $row['output_tokens']
            );
        }
        unset($row);

        $total_cost = array_sum(array_column($by_model, 'cost_usd'));

        return [
            'period_days'  => $days,
            'totals'       => array_map('intval', $totals ?? []),
            'total_cost'   => round($total_cost, 6),
            'by_model'     => $by_model,
            'daily'        => $daily,
            'top_tools'    => $top_tools,
        ];
    }
}
