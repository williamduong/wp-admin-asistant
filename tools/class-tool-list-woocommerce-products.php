<?php

defined('ABSPATH') || exit;

class WAA_Tool_List_WooCommerce_Products extends WAA_Tool_WooCommerce_Base {
    public function get_name(): string { return 'list_woocommerce_products'; }

    public function get_description(): string {
        return 'List WooCommerce products with pricing, stock, visibility, and edit links.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'publish, draft, private, trash, or any', 'default' => 'any'],
                'number' => ['type' => 'integer', 'default' => 10],
                'search' => ['type' => 'string', 'description' => 'Search by product title or content keyword.'],
                'sku' => ['type' => 'string', 'description' => 'Exact SKU filter.'],
            ],
        ];
    }

    public function execute(array $input): array {
        $inactive = $this->ensure_woocommerce_active();
        if ($inactive) {
            return $inactive;
        }

        $status = sanitize_key((string) ($input['status'] ?? 'any'));
        $args = [
            'post_type' => 'product',
            'post_status' => in_array($status, ['publish', 'draft', 'private', 'trash'], true) ? $status : 'any',
            'posts_per_page' => min(max((int) ($input['number'] ?? 10), 1), 50),
        ];

        if (!empty($input['search'])) {
            $args['s'] = sanitize_text_field((string) $input['search']);
        }

        if (!empty($input['sku'])) {
            $args['meta_query'] = [[
                'key' => '_sku',
                'value' => sanitize_text_field((string) $input['sku']),
            ]];
        }

        $query = new WP_Query($args);
        $products = [];

        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product instanceof WC_Product) {
                continue;
            }

            $products[] = $this->product_summary($product);
        }

        return [
            'success' => true,
            'products' => $products,
            'total' => (int) $query->found_posts,
        ];
    }
}
