<?php

defined('ABSPATH') || exit;

abstract class WAA_Tool_WooCommerce_Base extends WAA_Tool_Base {
    protected function get_woocommerce_state(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugin_file = 'woocommerce/woocommerce.php';
        $plugins = get_plugins();
        $installed = isset($plugins[$plugin_file]);
        $active = class_exists('WooCommerce');

        return [
            'plugin_file' => $plugin_file,
            'installed' => $installed,
            'active' => $active,
            'version' => $installed ? (string) ($plugins[$plugin_file]['Version'] ?? '') : '',
        ];
    }

    protected function ensure_woocommerce_active(): ?array {
        $state = $this->get_woocommerce_state();

        if ($state['active']) {
            return null;
        }

        return [
            'success' => false,
            'error' => $state['installed']
                ? 'WooCommerce is installed but not active. Activate WooCommerce first.'
                : 'WooCommerce is not installed yet. Install and activate WooCommerce first.',
            'installed' => $state['installed'],
            'active' => $state['active'],
            'plugin_file' => $state['plugin_file'],
            'version' => $state['version'],
        ];
    }

    protected function sanitize_yes_no(mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'yes', 'true', 'on'], true) ? 'yes' : 'no';
    }

    protected function sanitize_decimal(mixed $value): string {
        if (class_exists('WooCommerce') && function_exists('wc_format_decimal')) {
            return (string) wc_format_decimal($value);
        }

        return number_format((float) $value, 2, '.', '');
    }

    protected function sanitize_product_status(mixed $status, string $default = 'draft'): string {
        $status = sanitize_key((string) $status);

        return in_array($status, ['publish', 'draft', 'private'], true) ? $status : $default;
    }

    protected function product_summary(WC_Product $product): array {
        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'status' => get_post_status($product->get_id()),
            'type' => $product->get_type(),
            'sku' => $product->get_sku(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->managing_stock() ? $product->get_stock_quantity() : null,
            'edit_url' => get_edit_post_link($product->get_id(), 'raw'),
            'permalink' => get_permalink($product->get_id()),
        ];
    }

    protected function order_summary(WC_Order $order): array {
        return [
            'id' => $order->get_id(),
            'status' => $order->get_status(),
            'currency' => $order->get_currency(),
            'total' => $order->get_total(),
            'customer_name' => trim($order->get_formatted_billing_full_name()),
            'created_at' => $order->get_date_created() ? $order->get_date_created()->date('c') : '',
            'item_count' => count($order->get_items()),
            'edit_url' => admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id()),
        ];
    }

    protected function maybe_import_image(string $image_url, string $title): int|WP_Error {
        try {
            $importer = new WAA_Media_Importer(new WAA_Resource_Fetcher());

            return (int) $importer->import_from_url($image_url, $title);
        } catch (Throwable $e) {
            return new WP_Error('woo_image_import_failed', $e->getMessage());
        }
    }

    protected function resolve_existing_attachment(mixed $attachment_id): int|WP_Error {
        $attachment_id = (int) $attachment_id;

        if ($attachment_id <= 0) {
            return new WP_Error('woo_image_attachment_invalid', 'image_attachment_id must be a valid Media Library attachment ID.');
        }

        if (!wp_attachment_is_image($attachment_id)) {
            return new WP_Error('woo_image_attachment_invalid', "Attachment {$attachment_id} is not an image or does not exist.");
        }

        return $attachment_id;
    }
}
