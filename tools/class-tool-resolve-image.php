<?php

defined('ABSPATH') || exit;

class WAA_Tool_Resolve_Image extends WAA_Tool_Base {
    public function get_name(): string { return 'resolve_image'; }

    public function get_description(): string {
        return 'Resolve an image for content or products deterministically. First tries stock search via search_images. If stock confidence is low and generation is allowed, it automatically falls back to generate_image and returns a ready-to-use Media Library attachment.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Primary subject or stock-search query, for example "durian fruit" or "WordPress dashboard automation".',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Optional Media Library title for the final image.',
                ],
                'query_candidates' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional extra stock-search candidates ordered by preference.',
                ],
                'generation_prompt' => [
                    'type' => 'string',
                    'description' => 'Optional explicit AI image prompt for fallback generation. If omitted, a product/content-safe prompt will be derived from the query.',
                ],
                'orientation' => [
                    'type' => 'string',
                    'enum' => ['landscape', 'portrait', 'square'],
                    'description' => 'Preferred stock-image orientation. Default: square for products, otherwise landscape.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 3,
                    'description' => 'How many stock imports to request. Default: 1.',
                ],
                'allow_generation' => [
                    'type' => 'boolean',
                    'description' => 'Whether the tool may fall back to AI image generation when stock confidence is low. Default: true.',
                ],
                'prefer_generated' => [
                    'type' => 'boolean',
                    'description' => 'Skip stock search and generate an image directly. Default: false.',
                ],
                'usage_context' => [
                    'type' => 'string',
                    'enum' => ['product', 'post', 'general'],
                    'description' => 'Helps choose better defaults for orientation and fallback prompting.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input): array {
        $query = sanitize_text_field((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['success' => false, 'error' => 'query is required.'];
        }

        $usage_context = in_array($input['usage_context'] ?? '', ['product', 'post', 'general'], true)
            ? (string) $input['usage_context']
            : 'general';
        $orientation = in_array($input['orientation'] ?? '', ['landscape', 'portrait', 'square'], true)
            ? (string) $input['orientation']
            : ($usage_context === 'product' ? 'square' : 'landscape');
        $limit = max(1, min(3, (int) ($input['limit'] ?? 1)));
        $allow_generation = array_key_exists('allow_generation', $input) ? (bool) $input['allow_generation'] : true;
        $prefer_generated = !empty($input['prefer_generated']);
        $title = sanitize_text_field((string) ($input['title'] ?? ''));
        $query_candidates = is_array($input['query_candidates'] ?? null) ? $input['query_candidates'] : [];

        if (!$prefer_generated) {
            $search = $this->create_search_tool()->execute([
                'query' => $query,
                'query_candidates' => $query_candidates,
                'orientation' => $orientation,
                'limit' => $limit,
            ]);

            if (!empty($search['success']) && empty($search['low_confidence'])) {
                $first = $search['imported'][0] ?? null;
                return [
                    'success' => true,
                    'source_type' => 'stock',
                    'attachment_id' => (int) ($first['attachment_id'] ?? 0),
                    'media_url' => (string) ($first['media_url'] ?? ''),
                    'selected_query' => (string) ($search['selected_query'] ?? $query),
                    'confidence_score' => (float) ($search['confidence_score'] ?? 0),
                    'result' => $search,
                    'message' => 'Resolved image from stock search.',
                ];
            }

            if (!$allow_generation) {
                return [
                    'success' => false,
                    'error' => $search['error'] ?? 'Stock search did not find a reliable image.',
                    'source_type' => 'stock',
                    'low_confidence' => !empty($search['low_confidence']),
                    'result' => $search,
                ];
            }

            $fallback_prompt = $this->build_generation_prompt(
                $query,
                sanitize_text_field((string) ($input['generation_prompt'] ?? '')),
                $usage_context
            );
            $generated = $this->create_generate_tool()->execute([
                'prompt' => $fallback_prompt,
                'title' => $title !== '' ? $title : $this->derive_title_from_query($query),
                'size' => $this->orientation_to_size($orientation),
            ]);

            if (!empty($generated['success'])) {
                return [
                    'success' => true,
                    'source_type' => 'generated',
                    'attachment_id' => (int) ($generated['attachment_id'] ?? 0),
                    'media_url' => (string) ($generated['media_url'] ?? ''),
                    'selected_query' => (string) ($search['selected_query'] ?? $query),
                    'confidence_score' => (float) ($search['confidence_score'] ?? 0),
                    'stock_result' => $search,
                    'generation_result' => $generated,
                    'message' => 'Stock search was low-confidence, so an AI image was generated instead.',
                ];
            }

            return [
                'success' => false,
                'error' => $generated['error'] ?? 'Image generation fallback failed.',
                'source_type' => 'generated',
                'stock_result' => $search,
                'generation_result' => $generated,
            ];
        }

        $generated = $this->create_generate_tool()->execute([
            'prompt' => $this->build_generation_prompt(
                $query,
                sanitize_text_field((string) ($input['generation_prompt'] ?? '')),
                $usage_context
            ),
            'title' => $title !== '' ? $title : $this->derive_title_from_query($query),
            'size' => $this->orientation_to_size($orientation),
        ]);

        if (!empty($generated['success'])) {
            return [
                'success' => true,
                'source_type' => 'generated',
                'attachment_id' => (int) ($generated['attachment_id'] ?? 0),
                'media_url' => (string) ($generated['media_url'] ?? ''),
                'generation_result' => $generated,
                'message' => 'Resolved image by generating it directly.',
            ];
        }

        return array_merge(['source_type' => 'generated'], $generated);
    }

    protected function create_search_tool(): WAA_Tool_Search_Images {
        return new WAA_Tool_Search_Images();
    }

    protected function create_generate_tool(): WAA_Tool_Generate_Image {
        return new WAA_Tool_Generate_Image();
    }

    protected function build_generation_prompt(string $query, string $explicit_prompt, string $usage_context): string {
        if ($explicit_prompt !== '') {
            return $explicit_prompt;
        }

        return match ($usage_context) {
            'product' => sprintf(
                'Create a clean ecommerce-ready product image of %s on a plain studio background. Keep the subject centered, realistic, sharp, and suitable for a WooCommerce featured image.',
                $query
            ),
            'post' => sprintf(
                'Create a polished editorial illustration or hero image about %s for a WordPress blog post. Avoid text overlays and keep it relevant to the subject.',
                $query
            ),
            default => sprintf(
                'Create a relevant, professional image about %s. Keep the subject clear, realistic, and suitable for a WordPress site.',
                $query
            ),
        };
    }

    private function orientation_to_size(string $orientation): string {
        return match ($orientation) {
            'portrait' => '1024x1536',
            'landscape' => '1536x1024',
            default => '1024x1024',
        };
    }

    private function derive_title_from_query(string $query): string {
        return sanitize_text_field($query);
    }
}
