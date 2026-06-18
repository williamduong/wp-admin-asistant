<?php

defined('ABSPATH') || exit;

class WAA_Provider_Fake extends WAA_Provider_Base {
    public function __construct(
        private readonly string $fixture = 'runtime-v1'
    ) {}

    public function get_id(): string {
        return 'fake';
    }

    public function get_label(): string {
        return 'Fake Runtime Provider';
    }

    public function get_model_instructions(): string {
        return <<<INST
You are running against a deterministic fake provider fixture.
- Use the fixture responses exactly as defined.
- Do not improvise or call external services.
INST;
    }

    public function complete(string $system, array $messages, array $tools): array {
        $fixture = $this->load_fixture();
        $context = $this->build_context($messages);

        foreach ($fixture['responses'] ?? [] as $entry) {
            if ($this->matches($entry['when'] ?? [], $context)) {
                return $this->normalize_response($entry['response'] ?? []);
            }
        }

        if (!empty($fixture['default'])) {
            return $this->normalize_response($fixture['default']);
        }

        throw new RuntimeException("Fake provider fixture '{$this->fixture}' did not match the current prompt.");
    }

    private function load_fixture(): array {
        $slug = sanitize_file_name($this->fixture);
        $path = apply_filters(
            'waa_fake_provider_fixture_path',
            WAA_PLUGIN_DIR . 'tests/php/fixtures/fake-provider/' . $slug . '.json',
            $slug
        );

        if (!file_exists($path)) {
            throw new RuntimeException("Fake provider fixture '{$slug}' not found.");
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new RuntimeException("Fake provider fixture '{$slug}' is invalid JSON.");
        }

        return $data;
    }

    private function build_context(array $messages): array {
        $last_user = '';
        $last_tool_name = '';

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];

            if ($last_user === '' && ($message['role'] ?? '') === 'user') {
                $last_user = (string) ($message['content'] ?? '');
            }

            if ($last_tool_name === '' && ($message['role'] ?? '') === 'tool') {
                $last_tool_name = (string) ($message['tool_name'] ?? '');
            }

            if ($last_user !== '' && $last_tool_name !== '') {
                break;
            }
        }

        return [
            'last_user' => $last_user,
            'last_tool_name' => $last_tool_name,
        ];
    }

    private function matches(array $when, array $context): bool {
        if (isset($when['last_user']) && $context['last_user'] !== (string) $when['last_user']) {
            return false;
        }

        if (isset($when['last_user_contains']) && !str_contains($context['last_user'], (string) $when['last_user_contains'])) {
            return false;
        }

        if (isset($when['last_tool_name']) && $context['last_tool_name'] !== (string) $when['last_tool_name']) {
            return false;
        }

        return true;
    }

    private function normalize_response(array $response): array {
        return [
            'stop_reason' => $response['stop_reason'] ?? 'end_turn',
            'text' => (string) ($response['text'] ?? ''),
            'tool_calls' => array_values($response['tool_calls'] ?? []),
            'usage' => [
                'input_tokens' => (int) ($response['usage']['input_tokens'] ?? 0),
                'output_tokens' => (int) ($response['usage']['output_tokens'] ?? 0),
            ],
        ];
    }
}
