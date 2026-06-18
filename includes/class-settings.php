<?php

defined('ABSPATH') || exit;

class WAA_Settings {
    private WAA_Encryptor $enc;

    public function __construct() {
        $this->enc = new WAA_Encryptor();
    }

    // --- Provider ---

    public function get_provider(): string {
        return get_option('waa_provider', 'anthropic');
    }

    public function set_provider(string $provider): void {
        $allowed = ['anthropic', 'gemini', 'ollama', 'fake'];
        if (in_array($provider, $allowed, true)) {
            update_option('waa_provider', $provider);
        }
    }

    // --- Model ---

    public function get_model(): string {
        $defaults = [
            'anthropic' => 'claude-haiku-4-5',
            'gemini'    => 'gemini-2.5-flash',
            'ollama'    => 'qwen2.5:3b',
            'fake'      => 'runtime-v1',
        ];
        return get_option('waa_model', $defaults[$this->get_provider()] ?? 'claude-haiku-4-5');
    }

    public function set_model(string $model): void {
        update_option('waa_model', sanitize_text_field($model));
    }

    // --- Anthropic ---

    public function get_api_key(): string {
        $encrypted = get_option('waa_api_key_enc', '');
        return $encrypted ? $this->enc->decrypt($encrypted) : '';
    }

    public function set_api_key(string $key): void {
        update_option('waa_api_key_enc', $this->enc->encrypt($key), false);
    }

    // --- Gemini ---

    public function get_gemini_api_key(): string {
        $encrypted = get_option('waa_gemini_key_enc', '');
        return $encrypted ? $this->enc->decrypt($encrypted) : '';
    }

    public function set_gemini_api_key(string $key): void {
        update_option('waa_gemini_key_enc', $this->enc->encrypt($key), false);
    }

    // --- Ollama ---

    public function get_ollama_url(): string {
        return get_option('waa_ollama_url', 'http://localhost:11434');
    }

    public function set_ollama_url(string $url): void {
        update_option('waa_ollama_url', esc_url_raw($url));
    }

    // --- Custom rules (appended to system prompt) ---

    public function get_custom_rules(): string {
        return get_option('waa_custom_rules', '');
    }

    public function set_custom_rules(string $rules): void {
        update_option('waa_custom_rules', sanitize_textarea_field($rules));
    }

    // --- Disabled tools ---

    public function get_disabled_tools(): array {
        return (array) get_option('waa_disabled_tools', []);
    }

    public function set_disabled_tools(array $names): void {
        update_option('waa_disabled_tools', array_map('sanitize_key', $names));
    }

    // --- Pexels (image search) ---

    public function get_pexels_api_key(): string {
        $encrypted = get_option('waa_pexels_key_enc', '');
        return $encrypted ? $this->enc->decrypt($encrypted) : '';
    }

    public function set_pexels_api_key(string $key): void {
        update_option('waa_pexels_key_enc', $this->enc->encrypt($key), false);
    }

    // --- Debug mode ---

    public function get_debug_mode(): string {
        return get_option('waa_debug_mode', 'off');
    }

    public function set_debug_mode(string $mode): void {
        $allowed = ['off', 'compact', 'full'];
        if (in_array($mode, $allowed, true)) {
            update_option('waa_debug_mode', $mode);
        }
    }

    // --- Misc ---

    public function get_max_tokens(): int {
        return (int) get_option('waa_max_tokens', 4096);
    }

    public function has_active_credential(): bool {
        return match ($this->get_provider()) {
            'gemini'    => !empty($this->get_gemini_api_key()),
            'ollama'    => true,   // no key needed
            'fake'      => true,
            default     => !empty($this->get_api_key()),
        };
    }
}
