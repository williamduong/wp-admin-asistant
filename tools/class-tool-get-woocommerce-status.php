<?php

defined('ABSPATH') || exit;

class WAA_Tool_Get_WooCommerce_Status extends WAA_Tool_WooCommerce_Base {
    public function get_name(): string { return 'get_woocommerce_status'; }

    public function get_description(): string {
        return 'Inspect WooCommerce installation, activation state, store setup basics, key pages, payment gateways, and shipping readiness.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function execute(array $input): array {
        $state = $this->get_woocommerce_state();

        if (!$state['active']) {
            return array_merge(['success' => true], $state);
        }

        $gateway_rows = [];
        if (class_exists('WC_Payment_Gateways')) {
            foreach (WC_Payment_Gateways::instance()->payment_gateways() as $gateway) {
                $gateway_rows[] = [
                    'id' => $gateway->id,
                    'title' => $gateway->get_title(),
                    'enabled' => $gateway->enabled === 'yes',
                ];
            }
        }

        $pages = [];
        foreach (['shop', 'cart', 'checkout', 'myaccount'] as $page_key) {
            $page_id = function_exists('wc_get_page_id') ? (int) wc_get_page_id($page_key) : 0;
            $pages[$page_key] = [
                'id' => $page_id,
                'configured' => $page_id > 0,
                'title' => $page_id > 0 ? get_the_title($page_id) : '',
                'url' => $page_id > 0 ? get_permalink($page_id) : '',
            ];
        }

        $zones = class_exists('WC_Shipping_Zones') ? WC_Shipping_Zones::get_zones() : [];

        return [
            'success' => true,
            'installed' => $state['installed'],
            'active' => $state['active'],
            'version' => $state['version'],
            'currency' => get_option('woocommerce_currency', ''),
            'store' => [
                'address_1' => get_option('woocommerce_store_address', ''),
                'address_2' => get_option('woocommerce_store_address_2', ''),
                'city' => get_option('woocommerce_store_city', ''),
                'postcode' => get_option('woocommerce_store_postcode', ''),
                'country' => get_option('woocommerce_default_country', ''),
            ],
            'features' => [
                'taxes_enabled' => get_option('woocommerce_calc_taxes', 'no') === 'yes',
                'coupons_enabled' => get_option('woocommerce_enable_coupons', 'yes') === 'yes',
            ],
            'shipping' => [
                'zone_count' => is_array($zones) ? count($zones) : 0,
                'allowed_countries' => get_option('woocommerce_allowed_countries', 'all'),
                'ship_to_countries' => get_option('woocommerce_ship_to_countries', ''),
            ],
            'payment_gateways' => $gateway_rows,
            'pages' => $pages,
        ];
    }
}
