<?php

defined('ABSPATH') || exit;

class WAA_Tool_Update_WooCommerce_Settings extends WAA_Tool_WooCommerce_Base {
    private const ALLOWED = [
        'woocommerce_store_address',
        'woocommerce_store_address_2',
        'woocommerce_store_city',
        'woocommerce_store_postcode',
        'woocommerce_default_country',
        'woocommerce_currency',
        'woocommerce_currency_pos',
        'woocommerce_calc_taxes',
        'woocommerce_prices_include_tax',
        'woocommerce_enable_coupons',
        'woocommerce_allowed_countries',
        'woocommerce_ship_to_countries',
        'woocommerce_default_customer_address',
        'woocommerce_weight_unit',
        'woocommerce_dimension_unit',
    ];

    public function get_name(): string { return 'update_woocommerce_settings'; }

    public function get_description(): string {
        return 'Update core WooCommerce store settings. Allowed keys: ' . implode(', ', self::ALLOWED);
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'updates' => [
                    'type' => 'object',
                    'description' => 'Key-value pairs of WooCommerce option names to update.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['updates'],
        ];
    }

    public function execute(array $input): array {
        $inactive = $this->ensure_woocommerce_active();
        if ($inactive) {
            return $inactive;
        }

        $results = [];

        foreach ((array) ($input['updates'] ?? []) as $key => $value) {
            if (!in_array($key, self::ALLOWED, true)) {
                $results[$key] = ['status' => 'rejected', 'reason' => 'Key not in allowlist'];
                continue;
            }

            $old_value = get_option($key);
            $new_value = $this->sanitize_setting_value($key, $value);
            $updated = update_option($key, $new_value);

            $results[$key] = [
                'status' => $updated ? 'updated' : 'unchanged',
                'old_value' => $old_value,
                'new_value' => $new_value,
            ];
        }

        return [
            'success' => true,
            'results' => $results,
            '_navigate_url' => admin_url('admin.php?page=wc-settings'),
        ];
    }

    private function sanitize_setting_value(string $key, mixed $value): string {
        return match ($key) {
            'woocommerce_calc_taxes',
            'woocommerce_prices_include_tax',
            'woocommerce_enable_coupons' => $this->sanitize_yes_no($value),
            default => sanitize_text_field((string) $value),
        };
    }
}
