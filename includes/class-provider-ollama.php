<?php

defined('ABSPATH') || exit;

/**
 * Ollama provider using the /api/chat endpoint (native Ollama format).
 * Supports tool use for models that have it (gemma3, llama3, etc.)
 *
 * NOTE: When running inside wp-env Docker, use host.docker.internal:11434
 * instead of localhost:11434 to reach Ollama on the host machine.
 */
class WAA_Provider_Ollama extends WAA_Provider_Base {
    public function __construct(
        private readonly string $base_url = 'http://host.docker.internal:11434',
        private readonly string $model    = 'gemma3:4b'
    ) {}

    public function get_id(): string    { return 'ollama'; }
    public function get_label(): string { return 'Ollama (Local)'; }

    public function get_model_instructions(): string {
        return <<<INST
You are running on a local Ollama model. Keep responses short and direct.
- Tool support depends on the model. If a tool call fails, explain what you tried and suggest an alternative.
- Prefer plain-text answers over tool calls when the answer is obvious.
- Do not attempt parallel tool calls — call one tool at a time.
INST;
    }

    public function complete(string $system, array $messages, array $tools): array {
        $url     = rtrim($this->base_url, '/') . '/api/chat';
        $payload = [
            'model'    => $this->model,
            'stream'   => false,
            'messages' => $this->to_ollama_messages($system, $messages),
        ];

        if (!empty($tools)) {
            $payload['tools'] = $this->to_ollama_tools($tools);
        }

        $response = wp_remote_post($url, [
            'method'  => 'POST',
            'timeout' => 120,
            'headers' => ['content-type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException('Ollama connection failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $body['error'] ?? "HTTP $code";
            throw new RuntimeException("Ollama error: $msg");
        }

        return $this->normalize($body);
    }

    private function to_ollama_messages(string $system, array $messages): array {
        $out = [['role' => 'system', 'content' => $system]];

        foreach ($messages as $msg) {
            switch ($msg['role']) {
                case 'user':
                    $out[] = ['role' => 'user', 'content' => $msg['content']];
                    break;

                case 'assistant':
                    $entry = ['role' => 'assistant', 'content' => $msg['content'] ?? ''];
                    if (!empty($msg['tool_calls'])) {
                        $entry['tool_calls'] = array_map(fn($tc) => [
                            'function' => [
                                'name'      => $tc['name'],
                                'arguments' => $tc['input'],
                            ],
                        ], $msg['tool_calls']);
                    }
                    $out[] = $entry;
                    break;

                case 'tool':
                    $out[] = [
                        'role'    => 'tool',
                        'content' => wp_json_encode($msg['result']),
                    ];
                    break;
            }
        }

        return $out;
    }

    private function to_ollama_tools(array $tools): array {
        return array_map(fn($tool) => [
            'type'     => 'function',
            'function' => [
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'parameters'  => $tool['input_schema'],
            ],
        ], $tools);
    }

    private function normalize(array $body): array {
        $message    = $body['message'] ?? [];
        $text       = $message['content'] ?? '';
        $tool_calls = [];

        foreach ($message['tool_calls'] ?? [] as $i => $tc) {
            $fn = $tc['function'] ?? [];
            $tool_calls[] = [
                'id'    => 'ollama_tc_' . $i . '_' . uniqid(),
                'name'  => $fn['name'] ?? '',
                'input' => is_string($fn['arguments'])
                    ? (json_decode($fn['arguments'], true) ?? [])
                    : ($fn['arguments'] ?? []),
            ];
        }

        return [
            'stop_reason' => !empty($tool_calls) ? 'tool_use' : 'end_turn',
            'text'        => $text,
            'tool_calls'  => $tool_calls,
            'usage'       => [
                'input_tokens'  => $body['prompt_eval_count'] ?? 0,
                'output_tokens' => $body['eval_count']        ?? 0,
            ],
        ];
    }
}
