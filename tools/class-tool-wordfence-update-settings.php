<?php

defined('ABSPATH') || exit;

class WAA_Tool_Wordfence_Update_Settings extends WAA_Tool_Base {
    private const SETTING_MAP = [
        'firewall_enabled'         => ['key' => 'firewallEnabled',                'type' => 'bool'],
        'require_2fa_admins'       => ['key' => 'loginSec_requireAdminTwoFactor', 'type' => 'bool'],
        'lockout_after_failures'   => ['key' => 'loginSec_maxFailures',           'type' => 'int'],
        'lockout_duration_mins'    => ['key' => 'loginSec_lockoutMins',           'type' => 'int'],
        'count_failures_over_mins' => ['key' => 'loginSec_countFailMins',         'type' => 'int'],
        'block_fake_bots'          => ['key' => 'blockFakeBots',                  'type' => 'bool'],
        'scheduled_scans'          => ['key' => 'scheduledScansEnabled',          'type' => 'bool'],
        'alert_email'              => ['key' => 'alertEmailAddress',              'type' => 'string'],
        'alert_on_admin_login'     => ['key' => 'emailAlertOnLogin',              'type' => 'bool'],
        'live_traffic'             => ['key' => 'liveTraf_enabled',               'type' => 'bool'],
        'auto_update'              => ['key' => 'autoUpdate',                     'type' => 'bool'],
    ];

    public function get_name(): string { return 'wordfence_update_settings'; }

    public function get_description(): string {
        return 'Update Wordfence security settings. Accepts friendly keys: firewall_enabled, require_2fa_admins, lockout_after_failures, lockout_duration_mins, block_fake_bots, scheduled_scans, alert_email, alert_on_admin_login, live_traffic, auto_update.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'settings' => [
                    'type'        => 'object',
                    'description' => 'Map of setting names to values. e.g. {"firewall_enabled": true, "require_2fa_admins": true, "lockout_after_failures": 5}',
                ],
            ],
            'required' => ['settings'],
        ];
    }

    public function execute(array $input): array {
        if (!class_exists('wfConfig')) {
            return ['success' => false, 'error' => 'Wordfence is not active or not fully loaded.'];
        }

        $settings = $input['settings'] ?? [];
        if (empty($settings)) {
            return ['success' => false, 'error' => 'No settings provided.'];
        }

        $updated = [];
        $unknown = [];

        foreach ($settings as $friendly => $value) {
            $meta = self::SETTING_MAP[$friendly] ?? null;
            if (!$meta) {
                $unknown[] = $friendly;
                continue;
            }
            $cast = match ($meta['type']) {
                'bool'  => $value ? 1 : 0,
                'int'   => (int) $value,
                default => sanitize_text_field((string) $value),
            };
            wfConfig::set($meta['key'], $cast);
            $updated[$friendly] = $value;
        }

        $result = ['success' => true, 'updated' => $updated];
        if (!empty($unknown)) {
            $result['unknown_keys'] = $unknown;
            $result['valid_keys']   = array_keys(self::SETTING_MAP);
        }
        return $result;
    }
}
