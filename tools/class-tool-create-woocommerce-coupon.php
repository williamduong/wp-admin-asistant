<?php

defined('ABSPATH') || exit;

class WAA_Tool_Create_WooCommerce_Coupon extends WAA_Tool_WooCommerce_Base {
    public function get_name(): string { return 'create_woocommerce_coupon'; }

    public function get_description(): string {
        return 'Create a WooCommerce coupon code for percentage, fixed cart, or fixed product discounts.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string'],
                'amount' => ['type' => 'number'],
                'discount_type' => ['type' => 'string', 'enum' => ['percent', 'fixed_cart', 'fixed_product']],
                'description' => ['type' => 'string'],
                'individual_use' => ['type' => 'boolean'],
                'usage_limit' => ['type' => 'integer'],
                'date_expires' => ['type' => 'string', 'description' => 'Date string parseable by strtotime, e.g. 2026-12-31.'],
            ],
            'required' => ['code', 'amount', 'discount_type'],
        ];
    }

    public function execute(array $input): array {
        $inactive = $this->ensure_woocommerce_active();
        if ($inactive) {
            return $inactive;
        }

        $coupon = new WC_Coupon();
        $coupon->set_code(sanitize_text_field((string) $input['code']));
        $coupon->set_amount($this->sanitize_decimal($input['amount']));
        $coupon->set_discount_type(sanitize_key((string) $input['discount_type']));
        $coupon->set_description(sanitize_textarea_field((string) ($input['description'] ?? '')));

        if (array_key_exists('individual_use', $input)) {
            $coupon->set_individual_use((bool) $input['individual_use']);
        }

        if (array_key_exists('usage_limit', $input)) {
            $coupon->set_usage_limit(max(0, (int) $input['usage_limit']));
        }

        if (!empty($input['date_expires'])) {
            $timestamp = strtotime((string) $input['date_expires']);
            if ($timestamp !== false) {
                $coupon->set_date_expires($timestamp);
            }
        }

        $coupon_id = $coupon->save();

        if (is_wp_error($coupon_id) || !$coupon_id) {
            return ['success' => false, 'error' => is_wp_error($coupon_id) ? $coupon_id->get_error_message() : 'Could not create coupon.'];
        }

        return [
            'success' => true,
            'coupon_id' => $coupon_id,
            'code' => $coupon->get_code(),
            'amount' => $coupon->get_amount(),
            'discount_type' => $coupon->get_discount_type(),
            '_navigate_url' => get_edit_post_link($coupon_id, 'raw'),
        ];
    }
}
