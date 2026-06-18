<?php

defined('ABSPATH') || exit;

class WAA_Tool_Create_WooCommerce_Product extends WAA_Tool_WooCommerce_Base {
    public function get_name(): string { return 'create_woocommerce_product'; }

    public function get_description(): string {
        return 'Create a simple WooCommerce product with price, stock, description, optional categories, and optional featured image URL.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Product name.'],
                'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'private'], 'default' => 'draft'],
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
            'required' => ['name'],
        ];
    }

    public function execute(array $input): array {
        $inactive = $this->ensure_woocommerce_active();
        if ($inactive) {
            return $inactive;
        }

        $product = new WC_Product_Simple();
        $product->set_name(sanitize_text_field((string) $input['name']));
        $product->set_status($this->sanitize_product_status($input['status'] ?? 'draft'));
        $product->set_description(wp_kses_post((string) ($input['description'] ?? '')));
        $product->set_short_description(wp_kses_post((string) ($input['short_description'] ?? '')));

        if (!empty($input['sku'])) {
            $product->set_sku(sanitize_text_field((string) $input['sku']));
        }

        if (isset($input['regular_price'])) {
            $product->set_regular_price($this->sanitize_decimal($input['regular_price']));
        }

        if (isset($input['sale_price'])) {
            $product->set_sale_price($this->sanitize_decimal($input['sale_price']));
        }

        if (isset($input['stock_quantity'])) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((int) $input['stock_quantity']);
        }

        if (!empty($input['stock_status'])) {
            $product->set_stock_status(sanitize_key((string) $input['stock_status']));
        }

        if (!empty($input['catalog_visibility'])) {
            $product->set_catalog_visibility(sanitize_key((string) $input['catalog_visibility']));
        }

        $product_id = $product->save();

        if (is_wp_error($product_id) || !$product_id) {
            return ['success' => false, 'error' => is_wp_error($product_id) ? $product_id->get_error_message() : 'Could not create product.'];
        }

        if (!empty($input['categories']) && is_array($input['categories'])) {
            wp_set_object_terms($product_id, array_map('sanitize_text_field', $input['categories']), 'product_cat');
        }

        if (!empty($input['image_url'])) {
            $image_id = $this->maybe_import_image((string) $input['image_url'], (string) $input['name']);
            if (is_wp_error($image_id)) {
                return [
                    'success' => true,
                    'product' => $this->product_summary(wc_get_product($product_id)),
                    'image_warning' => 'Product created but featured image failed: ' . $image_id->get_error_message(),
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
