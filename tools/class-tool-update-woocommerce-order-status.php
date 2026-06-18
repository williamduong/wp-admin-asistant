<?php

defined('ABSPATH') || exit;

class WAA_Tool_Update_WooCommerce_Order_Status extends WAA_Tool_WooCommerce_Base {
    public function get_name(): string { return 'update_woocommerce_order_status'; }

    public function get_description(): string {
        return 'Update a WooCommerce order status and optionally add an internal order note.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['pending', 'processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed']],
                'note' => ['type' => 'string'],
            ],
            'required' => ['order_id', 'status'],
        ];
    }

    public function execute(array $input): array {
        $inactive = $this->ensure_woocommerce_active();
        if ($inactive) {
            return $inactive;
        }

        $order_id = (int) ($input['order_id'] ?? 0);
        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            return ['success' => false, 'error' => "WooCommerce order {$order_id} not found."];
        }

        $old_status = $order->get_status();
        $new_status = sanitize_key((string) $input['status']);
        $note = sanitize_textarea_field((string) ($input['note'] ?? ''));

        $order->update_status($new_status, $note, true);

        return [
            'success' => true,
            'order_id' => $order_id,
            'old_status' => $old_status,
            'new_status' => $order->get_status(),
            '_navigate_url' => admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id),
        ];
    }
}
