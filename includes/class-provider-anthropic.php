<?php

defined('ABSPATH') || exit;

class WAA_Provider_Anthropic extends WAA_Provider_Base {
    private const BASE_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VER  = '2023-06-01';

    public function __construct(
        private readonly string $api_key,
        private readonly string $model = 'claude-haiku-4-5'
    ) {}

    public function get_id(): string    { return 'anthropic'; }
    public function get_label(): string { return 'Anthropic (Claude)'; }

    public function get_model_instructions(): string {
        return <<<INST
You are running on Anthropic Claude. You excel at structured tool use and step-by-step reasoning.
- Always read current state before modifying (call a get_ tool first).
- Chain tool calls within one turn when steps are independent.
- Prefer concise confirmations after each successful write.
INST;
    }

    public function complete(string $system, array $messages, array $tools): array {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => 4096,
            'system'     => $system,
            'tools'      => $tools,  // already in Anthropic format
            'messages'   => $this->to_anthropic_messages($messages),
        ];

        $response = wp_remote_post(self::BASE_URL, [
            'method'  => 'POST',
            'timeout' => 90,
            'headers' => [
                'x-api-key'         => $this->api_key,
                'anthropic-version' => self::API_VER,
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $body['error']['message'] ?? "HTTP $code";
            throw new RuntimeException("Anthropic error: $msg");
        }

        return $this->normalize($body);
    }

    private function to_anthropic_messages(array $messages): array {
        $out = [];
        foreach ($messages as $msg) {
            switch ($msg['role']) {
                case 'user':
                    $out[] = ['role' => 'user', 'content' => $msg['content']];
                    break;

                case 'assistant':
                    $parts = [];
                    if (!empty($msg['content'])) {
                        $parts[] = ['type' => 'text', 'text' => $msg['content']];
                    }
                    foreach ($msg['tool_calls'] ?? [] as $tc) {
                        $parts[] = [
                            'type'  => 'tool_use',
                            'id'    => $tc['id'],
                            'name'  => $tc['name'],
                            'input' => $tc['input'] ?? (object)[],
                        ];
                    }
                    // Skip empty assistant messages — Anthropic rejects content:[]
                    if (!empty($parts)) {
                        $out[] = ['role' => 'assistant', 'content' => $parts];
                    }
                    break;

                case 'tool':
                    // Group consecutive tool results into one user message
                    $last = end($out);
                    $tool_result = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $msg['tool_call_id'],
                        'content'     => wp_json_encode($msg['result']),
                    ];
                    if ($last && $last['role'] === 'user' && is_array($last['content'])) {
                        $out[array_key_last($out)]['content'][] = $tool_result;
                    } else {
                        $out[] = ['role' => 'user', 'content' => [$tool_result]];
                    }
                    break;
            }
        }
        return $out;
    }

    private function normalize(array $body): array {
        $text       = '';
        $tool_calls = [];

        foreach ($body['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $text .= $block['text'];
            }
            if ($block['type'] === 'tool_use') {
                $tool_calls[] = [
                    'id'    => $block['id'],
                    'name'  => $block['name'],
                    'input' => $block['input'],
                ];
            }
        }

        return [
            'stop_reason'  => $body['stop_reason'] === 'tool_use' ? 'tool_use' : 'end_turn',
            'text'         => $text,
            'tool_calls'   => $tool_calls,
            'usage'        => [
                'input_tokens'  => $body['usage']['input_tokens']  ?? 0,
                'output_tokens' => $body['usage']['output_tokens'] ?? 0,
            ],
        ];
    }
}
