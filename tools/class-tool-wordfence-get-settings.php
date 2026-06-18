<?php

defined('ABSPATH') || exit;

class WAA_Tool_Wordfence_Get_Settings extends WAA_Tool_Base {
    private const SETTING_MAP = [
        'firewall_enabled'         => ['key' => 'firewallEnabled',                'type' => 'bool',   'default' => false],
        'require_2fa_admins'       => ['key' => 'loginSec_requireAdminTwoFactor', 'type' => 'bool',   'default' => false],
        'lockout_after_failures'   => ['key' => 'loginSec_maxFailures',           'type' => 'int',    'default' => 20],
        'lockout_duration_mins'    => ['key' => 'loginSec_lockoutMins',           'type' => 'int',    'default' => 5],
        'count_failures_over_mins' => ['key' => 'loginSec_countFailMins',         'type' => 'int',    'default' => 5],
        'block_fake_bots'          => ['key' => 'blockFakeBots',                  'type' => 'bool',   'default' => false],
        'scheduled_scans'          => ['key' => 'scheduledScansEnabled',          'type' => 'bool',   'default' => false],
        'alert_email'              => ['key' => 'alertEmailAddress',              'type' => 'string', 'default' => ''],
        'alert_on_admin_login'     => ['key' => 'emailAlertOnLogin',              'type' => 'bool',   'default' => false],
        'live_traffic'             => ['key' => 'liveTraf_enabled',               'type' => 'bool',   'default' => true],
        'auto_update'              => ['key' => 'autoUpdate',                     'type' => 'bool',   'default' => false],
    ];

    public function get_name(): string { return 'wordfence_get_settings'; }

    public function get_description(): string {
        return 'Read Wordfence security settings: firewall, brute-force lockout, 2FA requirement, scheduled scans, alert email, etc.';
    }

    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): array {
        if (!class_exists('wfConfig')) {
            return ['success' => false, 'error' => 'Wordfence is not active or not fully loaded.'];
        }

        $out = [];
        foreach (self::SETTING_MAP as $friendly => $meta) {
            $raw = wfConfig::get($meta['key'], $meta['default']);
            $out[$friendly] = match ($meta['type']) {
                'bool'  => (bool) $raw,
                'int'   => (int)  $raw,
                default => (string) $raw,
            };
        }

        return ['success' => true, 'settings' => $out];
    }
}
