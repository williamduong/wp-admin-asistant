<?php

defined('ABSPATH') || exit;

class WAA_Tool_Wordfence_Run_Scan extends WAA_Tool_Base {
    public function get_name(): string { return 'wordfence_run_scan'; }

    public function get_description(): string {
        return 'Schedule a Wordfence security scan to run immediately via WP-Cron. The scan runs asynchronously; use wordfence_get_scan_results after it completes.';
    }

    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): array {
        if (!class_exists('wfConfig')) {
            return ['success' => false, 'error' => 'Wordfence is not active or not fully loaded.'];
        }

        // Enable scheduled scans so the cron hook is registered
        wfConfig::set('scheduledScansEnabled', 1);

        // Schedule an immediate single-fire scan event if Wordfence's hook exists
        $hook = 'wordfence_start_scheduled_scan';
        if (!wp_next_scheduled($hook)) {
            wp_schedule_single_event(time(), $hook);
        }

        // Spawn a loopback request to trigger WP-Cron immediately (best-effort)
        wp_remote_post(site_url('/?wc-ajax=wordfence_scan'), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
        ]);

        return [
            'success' => true,
            'async' => true,
            'job_type' => 'wordfence_scan',
            'job_status' => 'queued',
            'recommended_poll_tool' => 'wordfence_get_scan_results',
            'recommended_delay_sec' => 60,
            'message' => 'Wordfence scan scheduled. It will run on the next WP-Cron tick (usually within 1 minute). Use wordfence_get_scan_results to check for issues after it completes.',
        ];
    }
}
