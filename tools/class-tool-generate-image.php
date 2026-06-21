<?php

defined('ABSPATH') || exit;

class WAA_Tool_Generate_Image extends WAA_Tool_Base {
    private const GEMINI_IMAGE_BASE_URL = 'https://generativelanguage.googleapis.com/v1/models/';
    private const DEFAULT_MODEL = 'gemini-2.5-flash-image';

    public function get_name(): string { return 'generate_image'; }

    public function get_description(): string {
        return 'Generate a brand-new image with AI, import it into the WordPress Media Library, and return an attachment ready for posts or WooCommerce products. Use this when stock search is low-confidence or the user explicitly wants an AI-generated illustration/product image.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Direct image prompt describing the desired result.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Optional Media Library title. Defaults to a short prompt-based label.',
                ],
                'size' => [
                    'type' => 'string',
                    'enum' => ['1024x1024', '1024x1536', '1536x1024'],
                    'description' => 'Output size. Default: 1024x1024.',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Optional Gemini image model override. Default: gemini-2.5-flash-image.',
                ],
            ],
            'required' => ['prompt'],
        ];
    }

    public function execute(array $input): array {
        $settings = new WAA_Settings();
        $api_key = $settings->get_gemini_api_key();
        if ($api_key === '') {
            return ['success' => false, 'error' => 'Gemini API key not configured. Add it in Settings → AI Agent Settings before using generate_image.'];
        }

        $prompt = trim((string) ($input['prompt'] ?? ''));
        if ($prompt === '') {
            return ['success' => false, 'error' => 'Image prompt cannot be empty.'];
        }

        $size = in_array($input['size'] ?? '', ['1024x1024', '1024x1536', '1536x1024'], true)
            ? (string) $input['size']
            : '1024x1024';
        $model = sanitize_text_field((string) ($input['model'] ?? self::DEFAULT_MODEL));
        $title = sanitize_text_field((string) ($input['title'] ?? ''));

        $final_prompt = $this->apply_size_hint_to_prompt($prompt, $size);
        $request_body = [
            'contents' => [[
                'parts' => [[
                    'text' => $final_prompt,
                ]],
            ]],
        ];

        $response = wp_remote_post(
            self::GEMINI_IMAGE_BASE_URL . rawurlencode($model) . ':generateContent',
            [
                'timeout' => 90,
                'headers' => [
                    'x-goog-api-key' => $api_key,
                    'content-type' => 'application/json',
                ],
                'body' => wp_json_encode($request_body),
            ]
        );

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            return ['success' => false, 'error' => 'Gemini image generation error: ' . ($body['error']['message'] ?? "HTTP {$code}")];
        }

        $image_part = $this->find_inline_image_part($body);
        if (!$image_part) {
            return ['success' => false, 'error' => 'Gemini did not return image bytes for this prompt.'];
        }

        $image_bytes = base64_decode((string) ($image_part['inlineData']['data'] ?? ''), true);
        if ($image_bytes === false || $image_bytes === '') {
            return ['success' => false, 'error' => 'Gemini returned invalid image data.'];
        }

        $mime = (string) ($image_part['inlineData']['mimeType'] ?? 'image/png');
        $extension = $this->mime_to_extension($mime);
        $filename = $this->build_filename($title !== '' ? $title : $prompt, $extension);

        try {
            $importer = new WAA_Media_Importer(new WAA_Resource_Fetcher());
            $attachment_id = $importer->import_from_binary(
                $image_bytes,
                $filename,
                $mime,
                $title !== '' ? $title : $this->derive_default_title($prompt)
            );
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Generated image could not be imported: ' . $e->getMessage()];
        }

        update_post_meta($attachment_id, '_waa_generated_image', '1');
        update_post_meta($attachment_id, '_waa_generated_provider', 'gemini');
        update_post_meta($attachment_id, '_waa_generated_model', $model);
        update_post_meta($attachment_id, '_waa_generated_prompt', $prompt);

        return [
            'success' => true,
            'provider' => 'gemini',
            'model' => $model,
            'prompt' => $prompt,
            'effective_prompt' => $final_prompt,
            'attachment_id' => $attachment_id,
            'media_url' => wp_get_attachment_url($attachment_id),
            'title' => get_the_title($attachment_id),
            'mime_type' => $mime,
            'message' => 'AI-generated image created and imported into Media Library.',
        ];
    }

    private function find_inline_image_part(array $body): ?array {
        $parts = $body['candidates'][0]['content']['parts'] ?? [];
        if (!is_array($parts)) {
            return null;
        }

        foreach ($parts as $part) {
            if (!empty($part['inlineData']['data'])) {
                return $part;
            }
        }

        return null;
    }

    private function mime_to_extension(string $mime): string {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }

    private function build_filename(string $label, string $extension): string {
        $base = sanitize_title($label);
        if ($base === '') {
            $base = 'generated-image';
        }

        return $base . '-' . gmdate('Ymd-His') . '.' . $extension;
    }

    private function derive_default_title(string $prompt): string {
        $title = function_exists('mb_substr') ? mb_substr($prompt, 0, 80) : substr($prompt, 0, 80);
        return sanitize_text_field($title);
    }

    private function apply_size_hint_to_prompt(string $prompt, string $size): string {
        $hint = match ($size) {
            '1024x1536' => 'Use a portrait 2:3 composition.',
            '1536x1024' => 'Use a landscape 3:2 composition.',
            default => 'Use a square 1:1 composition.',
        };

        return trim($prompt . "\n\n" . $hint);
    }
}
