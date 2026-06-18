<?php

defined('ABSPATH') || exit;

class WAA_Provider_Gemini extends WAA_Provider_Base {
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        private readonly string $api_key,
        private readonly string $model = 'gemini-2.5-flash'
    ) {}

    public function get_id(): string    { return 'gemini'; }
    public function get_label(): string { return 'Google Gemini'; }

    public function get_model_instructions(): string {
        return <<<INST
You are running on Google Gemini with function calling enabled. Critical rules:
- To take ANY action (install plugin, create post, change settings, navigate, search images, etc.) you MUST invoke the corresponding function/tool. Never describe actions in JSON or code blocks — call the actual function.
- If you feel like writing {"install_plugin": ...} or similar JSON, STOP — call install_plugin() instead.
- Pass only parameters defined in the tool schema. Do not invent extra fields.
- If a tool has no required parameters, call it with an empty argument object {}.
- After calling tools and receiving results, always provide a clear text summary of what was done.
INST;
    }

    public function complete(string $system, array $messages, array $tools): array {
        $url     = self::BASE_URL . urlencode($this->model) . ':generateContent?key=' . $this->api_key;
        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $system]],
            ],
            'contents' => $this->to_gemini_contents($messages),
            'generationConfig' => [
                'maxOutputTokens' => 4096,
                'temperature'     => 0.7,
            ],
        ];

        $gemini_tools = $this->to_gemini_tools($tools);
        if (!empty($gemini_tools)) {
            $payload['tools']       = $gemini_tools;
            $payload['tool_config'] = [
                'function_calling_config' => ['mode' => 'AUTO'],
            ];
        }

        $response = wp_remote_post($url, [
            'method'  => 'POST',
            'timeout' => 90,
            'headers' => ['content-type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $body['error']['message'] ?? "HTTP $code";
            throw new RuntimeException("Gemini error: $msg");
        }

        return $this->normalize($body);
    }

    private function to_gemini_contents(array $messages): array {
        $contents = [];
        foreach ($messages as $msg) {
            switch ($msg['role']) {
                case 'user':
                    $contents[] = [
                        'role'  => 'user',
                        'parts' => [['text' => $msg['content']]],
                    ];
                    break;

                case 'assistant':
                    $parts = [];
                    if (!empty($msg['content'])) {
                        $parts[] = ['text' => $msg['content']];
                    }
                    foreach ($msg['tool_calls'] ?? [] as $tc) {
                        $part = [
                            'functionCall' => [
                                'name' => $tc['name'],
                                // Cast to object: empty PHP array [] must serialize as {} not []
                                'args' => (object) ($tc['input'] ?? []),
                            ],
                        ];
                        if (!empty($tc['thought_signature'])) {
                            $part['thoughtSignature'] = $tc['thought_signature'];
                        }
                        $parts[] = $part;
                    }
                    // Skip empty model messages — Gemini rejects parts:[]
                    if (!empty($parts)) {
                        $contents[] = ['role' => 'model', 'parts' => $parts];
                    }
                    break;

                case 'tool':
                    // Group consecutive tool responses into one user turn
                    $last = end($contents);
                    $part = [
                        'functionResponse' => [
                            'name'     => $msg['tool_name'],
                            'response' => ['result' => $msg['result']],
                        ],
                    ];
                    if ($last && $last['role'] === 'user') {
                        $contents[array_key_last($contents)]['parts'][] = $part;
                    } else {
                        $contents[] = ['role' => 'user', 'parts' => [$part]];
                    }
                    break;
            }
        }
        return $contents;
    }

    private function to_gemini_tools(array $tools): array {
        if (empty($tools)) return [];

        $declarations = [];
        foreach ($tools as $tool) {
            $declarations[] = [
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'parameters'  => $this->fix_schema($tool['input_schema']),
            ];
        }

        return [['functionDeclarations' => $declarations]];
    }

    /**
     * Recursively sanitise a JSON Schema for Gemini:
     * - Strip 'additionalProperties' everywhere (not supported)
     * - Cast any empty PHP array [] to stdClass so it serialises as {} not []
     *   (Gemini requires 'properties' to be a map, never an array)
     */
    private function fix_schema(array $schema): array {
        unset($schema['additionalProperties']);

        if (array_key_exists('properties', $schema)) {
            // Normalise to array first: (object)[] is truthy so empty() misses it
            $props = is_array($schema['properties']) ? $schema['properties'] : (array) $schema['properties'];
            if (empty($props)) {
                $schema['properties'] = (object) [];
            } else {
                $fixed = [];
                foreach ($props as $key => $prop) {
                    $fixed[$key] = is_array($prop) ? $this->fix_schema($prop) : $prop;
                }
                $schema['properties'] = $fixed;
            }
        }

        // Recurse into 'items' for array-type properties
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->fix_schema($schema['items']);
        }

        return $schema;
    }

    private function normalize(array $body): array {
        $candidate  = $body['candidates'][0] ?? [];
        $parts      = $candidate['content']['parts'] ?? [];
        $text       = '';
        $tool_calls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
            if (isset($part['functionCall'])) {
                $tool_call = [
                    'id'    => uniqid('gemini_tc_'),
                    'name'  => $part['functionCall']['name'],
                    'input' => $part['functionCall']['args'] ?? [],
                ];
                if (!empty($part['thoughtSignature'])) {
                    $tool_call['thought_signature'] = $part['thoughtSignature'];
                }
                $tool_calls[] = $tool_call;
            }
        }

        $stop_reason = !empty($tool_calls) ? 'tool_use' : 'end_turn';
        $meta        = $body['usageMetadata'] ?? [];

        return [
            'stop_reason' => $stop_reason,
            'text'        => $text,
            'tool_calls'  => $tool_calls,
            'usage'       => [
                'input_tokens'  => $meta['promptTokenCount']     ?? 0,
                'output_tokens' => $meta['candidatesTokenCount'] ?? 0,
            ],
        ];
    }
}
