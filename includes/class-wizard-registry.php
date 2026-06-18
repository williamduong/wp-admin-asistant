<?php

defined('ABSPATH') || exit;

class WAA_Wizard_Registry {
    public static function get(string $wizard_id): ?array {
        return match ($wizard_id) {
            'woocommerce_first_time_setup' => [
                'id' => 'woocommerce_first_time_setup',
                'title' => 'WooCommerce first-time setup',
                'description' => 'Collect store basics once, then apply WooCommerce settings in a single guided setup flow.',
                'allowed_tools' => [
                    'get_woocommerce_status',
                    'update_woocommerce_settings',
                    'create_woocommerce_product',
                ],
            ],
            default => null,
        };
    }

    public static function summarize_active_workflow(?array $workflow): string {
        if (!is_array($workflow) || ($workflow['kind'] ?? '') !== 'wizard') {
            return '';
        }

        $wizard_id = sanitize_key((string) ($workflow['workflowId'] ?? ''));
        $definition = self::get($wizard_id);
        if (!$definition) {
            return '';
        }

        $status = sanitize_key((string) ($workflow['status'] ?? 'collecting'));
        $current_step = sanitize_key((string) ($workflow['currentStep'] ?? ''));
        $answers = is_array($workflow['answers'] ?? null) ? $workflow['answers'] : [];

        $summary = [
            "Active wizard: {$definition['title']} ({$definition['id']})",
            "Wizard status: {$status}",
        ];

        if ($current_step !== '') {
            $summary[] = "Current step: {$current_step}";
        }

        if ($answers !== []) {
            $summary[] = 'Collected answers: ' . wp_json_encode($answers);
        }

        if (!empty($definition['allowed_tools'])) {
            $summary[] = 'Preferred tools during apply: ' . implode(', ', $definition['allowed_tools']);
        }

        return implode("\n", $summary);
    }
}
