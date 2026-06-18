<?php

defined('ABSPATH') || exit;

class WAA_Tool_List_WooCommerce_Orders extends WAA_Tool_WooCommerce_Base {
    public function get_name(): string { return 'list_woocommerce_orders'; }

    public function get_description(): string {
        return 'List WooCommerce orders with status, totals, customer name, and edit links.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'pending, processing, completed, on-hold, cancelled, refunded, failed, or any', 'default' => 'any'],
                'number' => ['type' => 'integer', 'default' => 10],
            ],
        ];
    }

    public function execute(array $input): array {
        $inactive = $this->ensure_woocommerce_active();
        if ($inactive) {
            return $inactive;
        }

        $status = sanitize_key((string) ($input['status'] ?? 'any'));
        $query = [
            'limit' => min(max((int) ($input['number'] ?? 10), 1), 50),
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
        ];

        if ($status !== 'any') {
            $query['status'] = $status;
        }

        $orders = wc_get_orders($query);

        return [
            'success' => true,
            'orders' => array_map(fn(WC_Order $order): array => $this->order_summary($order), $orders),
            'total' => count($orders),
        ];
    }
}
