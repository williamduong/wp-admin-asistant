<?php

defined('ABSPATH') || exit;

class WAA_Tool_Update_WooCommerce_Product extends WAA_Tool_WooCommerce_Base {
    public function get_name(): string { return 'update_woocommerce_product'; }

    public function get_description(): string {
        return 'Update a WooCommerce product name, status, price, stock, description, categories, or featured image.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'private']],
                'regular_price' => ['type' => 'number'],
                'sale_price' => ['type' => 'number'],
                'description' => ['type' => 'string'],
                'short_description' => ['type' => 'string'],
                'sku' => ['type' => 'string'],
                'stock_quantity' => ['type' => 'integer'],
                'stock_status' => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder']],
                'catalog_visibility' => ['type' => 'string', 'enum' => ['visible', 'catalog', 'search', 'hidden']],
                'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
                'image_url' => ['type' => 'string'],
            ],
            'required' => ['product_id'],
        ];
    }

    public function execute(array $input): array {
        $inactive = $this->ensure_woocommerce_active();
        if ($inactive) {
            return $inactive;
        }

        $product_id = (int) ($input['product_id'] ?? 0);
        $product = wc_get_product($product_id);

        if (!$product instanceof WC_Product) {
            return ['success' => false, 'error' => "WooCommerce product {$product_id} not found."];
        }

        if (!empty($input['name'])) {
            $product->set_name(sanitize_text_field((string) $input['name']));
        }
        if (array_key_exists('status', $input)) {
            $product->set_status($this->sanitize_product_status($input['status'] ?? '', get_post_status($product_id) ?: 'draft'));
        }
        if (array_key_exists('regular_price', $input)) {
            $product->set_regular_price($this->sanitize_decimal($input['regular_price']));
        }
        if (array_key_exists('sale_price', $input)) {
            $product->set_sale_price($this->sanitize_decimal($input['sale_price']));
        }
        if (array_key_exists('description', $input)) {
            $product->set_description(wp_kses_post((string) $input['description']));
        }
        if (array_key_exists('short_description', $input)) {
            $product->set_short_description(wp_kses_post((string) $input['short_description']));
        }
        if (array_key_exists('sku', $input)) {
            $product->set_sku(sanitize_text_field((string) $input['sku']));
        }
        if (array_key_exists('stock_quantity', $input)) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((int) $input['stock_quantity']);
        }
        if (array_key_exists('stock_status', $input)) {
            $product->set_stock_status(sanitize_key((string) $input['stock_status']));
        }
        if (array_key_exists('catalog_visibility', $input)) {
            $product->set_catalog_visibility(sanitize_key((string) $input['catalog_visibility']));
        }

        $product->save();

        if (array_key_exists('categories', $input) && is_array($input['categories'])) {
            wp_set_object_terms($product_id, array_map('sanitize_text_field', $input['categories']), 'product_cat');
        }

        if (!empty($input['image_url'])) {
            $image_id = $this->maybe_import_image((string) $input['image_url'], $product->get_name());
            if (is_wp_error($image_id)) {
                return [
                    'success' => true,
                    'product' => $this->product_summary(wc_get_product($product_id)),
                    'image_warning' => 'Product updated but featured image failed: ' . $image_id->get_error_message(),
                    '_navigate_url' => get_edit_post_link($product_id, 'raw'),
                ];
            }

            set_post_thumbnail($product_id, $image_id);
        }

        return [
            'success' => true,
            'product' => $this->product_summary(wc_get_product($product_id)),
            '_navigate_url' => get_edit_post_link($product_id, 'raw'),
        ];
    }
}
