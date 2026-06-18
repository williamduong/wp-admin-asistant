<?php

defined('ABSPATH') || exit;

class WAA_Tool_Wordfence_Get_Scan_Results extends WAA_Tool_Base {
    public function get_name(): string { return 'wordfence_get_scan_results'; }

    public function get_description(): string {
        return 'Retrieve Wordfence scan issues from the database: malware detections, file integrity problems, vulnerable plugins, etc.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Maximum issues to return (1–100, default 20)',
                    'minimum'     => 1,
                    'maximum'     => 100,
                ],
                'status' => [
                    'type'        => 'string',
                    'description' => 'Filter by status: "new" (default), "accepted", "ignored", or "all"',
                    'enum'        => ['new', 'accepted', 'ignored', 'all'],
                ],
            ],
        ];
    }

    public function execute(array $input): array {
        if (!class_exists('wfConfig')) {
            return ['success' => false, 'error' => 'Wordfence is not active or not fully loaded.'];
        }

        global $wpdb;
        $limit  = min(100, max(1, (int) ($input['limit'] ?? 20)));
        $status = $input['status'] ?? 'new';

        $table = $wpdb->prefix . 'wfIssues';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return ['success' => false, 'error' => 'Wordfence issues table not found — run a scan first.'];
        }

        if ($status === 'all') {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM `$table` ORDER BY severity DESC, time DESC LIMIT %d", $limit),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM `$table` WHERE status = %s ORDER BY severity DESC, time DESC LIMIT %d", $status, $limit),
                ARRAY_A
            );
        }

        $issues  = [];
        $summary = ['total' => 0, 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($rows as $row) {
            $data = json_decode($row['data'] ?? '{}', true) ?? [];
            $sev  = (int) ($row['severity'] ?? 0);

            $severity_label = match (true) {
                $sev >= 10 => 'critical',
                $sev >= 7  => 'high',
                $sev >= 4  => 'medium',
                default    => 'low',
            };

            $summary[$severity_label]++;
            $summary['total']++;

            $issues[] = [
                'id'          => (int) $row['id'],
                'severity'    => $severity_label,
                'status'      => $row['status'] ?? '',
                'type'        => $row['type'] ?? '',
                'description' => $data['shortMsg'] ?? $data['longMsg'] ?? '',
                'file'        => $data['file'] ?? '',
                'url'         => $data['url'] ?? '',
                'time'        => date('Y-m-d H:i:s', (int) ($row['time'] ?? 0)),
            ];
        }

        return [
            'success' => true,
            'summary' => $summary,
            'issues'  => $issues,
        ];
    }
}
