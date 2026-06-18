<?php

defined('ABSPATH') || exit;

/**
 * Centralized pricing + model metadata.
 * Prices in USD per 1,000,000 tokens.
 */
class WAA_Pricing {
    private const DATA = [
        'anthropic' => [
            // Prices: USD per 1M tokens. Context: 200K for Haiku, 1M for Sonnet/Opus.
            'claude-haiku-4-5'  => ['label' => 'Claude Haiku 4.5',  'ctx' =>  200000, 'in' =>  1.00, 'out' =>  5.00],
            'claude-sonnet-4-6' => ['label' => 'Claude Sonnet 4.6', 'ctx' => 1000000, 'in' =>  3.00, 'out' => 15.00],
            'claude-opus-4-7'   => ['label' => 'Claude Opus 4.7',   'ctx' => 1000000, 'in' =>  5.00, 'out' => 25.00],
        ],
        'gemini' => [
            // Model IDs verified via API (May 2026). gemini-2.0-flash removed (deprecated).
            'gemini-2.5-flash-lite' => ['label' => 'Gemini 2.5 Flash-Lite',   'ctx' => 1048576, 'in' => 0.10, 'out' => 0.40],
            'gemini-2.5-flash'      => ['label' => 'Gemini 2.5 Flash',         'ctx' => 1048576, 'in' => 0.30, 'out' => 2.50],
            'gemini-2.5-pro'        => ['label' => 'Gemini 2.5 Pro',           'ctx' => 1048576, 'in' => 1.25, 'out' => 10.00],
            'gemini-3.1-flash-lite' => ['label' => 'Gemini 3.1 Flash-Lite',    'ctx' => 1048576, 'in' => 0.25, 'out' => 1.50],
            'gemini-3-flash-preview'   => ['label' => 'Gemini 3 Flash (Preview)',   'ctx' => 1048576, 'in' => 0.50, 'out' => 3.00],
            'gemini-3.1-pro-preview'   => ['label' => 'Gemini 3.1 Pro (Preview)',   'ctx' => 1048576, 'in' => 2.00, 'out' => 12.00],
        ],
        'ollama' => [
            // Common local/self-hosted Ollama models
            'qwen2.5:3b'   => ['label' => 'Qwen 2.5 3B ★ (Recommended — fast, Vietnamese)',    'ctx' =>  32768, 'in' => 0, 'out' => 0],
            'gemma:2b'     => ['label' => 'Gemma 2B (Google — lightweight, stable)',            'ctx' =>   8192, 'in' => 0, 'out' => 0],
            'gemma:7b'     => ['label' => 'Gemma 7B (Google — more capable, may be slower)',   'ctx' =>   8192, 'in' => 0, 'out' => 0],
            // ── Other common models (pull manually if needed) ──────
            'gemma4:4b'       => ['label' => 'Gemma 4 4B',         'ctx' => 128000, 'in' => 0, 'out' => 0],
            'gemma3:4b'       => ['label' => 'Gemma 3 4B',         'ctx' => 128000, 'in' => 0, 'out' => 0],
            'qwen3:8b'        => ['label' => 'Qwen3 8B',           'ctx' => 128000, 'in' => 0, 'out' => 0],
            'llama4:scout'    => ['label' => 'Llama 4 Scout',      'ctx' => 128000, 'in' => 0, 'out' => 0],
            'mistral'         => ['label' => 'Mistral 7B',         'ctx' =>  32000, 'in' => 0, 'out' => 0],
            'phi4'            => ['label' => 'Phi-4 14B',          'ctx' =>  16000, 'in' => 0, 'out' => 0],
        ],
    ];

    public static function get_models(string $provider): array {
        return self::DATA[$provider] ?? [];
    }

    public static function get_model_info(string $provider, string $model): array {
        return self::DATA[$provider][$model] ?? ['label' => $model, 'ctx' => 0, 'in' => 0, 'out' => 0];
    }

    /** Returns cost in USD (not micro, not milli — just dollars) */
    public static function calculate(string $provider, string $model, int $input_tokens, int $output_tokens): float {
        $info = self::get_model_info($provider, $model);
        return ($input_tokens * $info['in'] + $output_tokens * $info['out']) / 1_000_000;
    }

    /** Returns all providers + models for frontend consumption */
    public static function all_for_js(): array {
        $out = [];
        foreach (self::DATA as $provider => $models) {
            foreach ($models as $id => $info) {
                $out[$provider][$id] = [
                    'label' => $info['label'],
                    'ctx'   => $info['ctx'],
                    'in'    => $info['in'],
                    'out'   => $info['out'],
                    'free'  => $info['in'] === 0 && $info['out'] === 0,
                ];
            }
        }
        return $out;
    }
}
