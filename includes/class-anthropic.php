<?php

defined('ABSPATH') || exit;

class WAA_Anthropic {
    private const BASE_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VER  = '2023-06-01';

    public function __construct(
        private readonly string $api_key,
        private readonly string $model = 'claude-haiku-4-5'
    ) {}

    public function messages(array $payload): array {
        $payload['model']      = $this->model;
        $payload['stream']     = false;
        $payload['max_tokens'] = $payload['max_tokens'] ?? 4096;

        $response = wp_remote_post(self::BASE_URL, [
            'method'  => 'POST',
            'timeout' => 90,
            'headers' => $this->headers(),
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $body['error']['message'] ?? "HTTP $code";
            throw new RuntimeException("Anthropic API error: $msg");
        }

        return $body;
    }

    public function stream_to_callback(array $payload, callable $on_event): void {
        $payload['model']      = $this->model;
        $payload['stream']     = true;
        $payload['max_tokens'] = $payload['max_tokens'] ?? 4096;

        $buffer = '';
        $ch     = curl_init(self::BASE_URL);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => wp_json_encode($payload),
            CURLOPT_HTTPHEADER     => $this->headers_array(),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$buffer, $on_event) {
                $buffer .= $data;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    if (!str_starts_with($line, 'data: ')) continue;
                    $json = json_decode(substr($line, 6), true);
                    if ($json) $on_event($json);
                }
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException('curl error: ' . curl_strerror($errno));
        }
    }

    private function headers(): array {
        return [
            'x-api-key'         => $this->api_key,
            'anthropic-version' => self::API_VER,
            'content-type'      => 'application/json',
        ];
    }

    private function headers_array(): array {
        return array_map(
            fn($k, $v) => "$k: $v",
            array_keys($this->headers()),
            array_values($this->headers())
        );
    }
}
