<?php

defined('ABSPATH') || exit;

class WAA_Tool_Registry {
    private array $tools = [];

    private const BLOCKED = ['delete_site', 'wp_delete_user_self', 'update_core'];

    public function __construct(
        private readonly array $disabled = []
    ) {}

    public function register(WAA_Tool_Base $tool): void {
        $name = $tool->get_name();
        if (!in_array($name, self::BLOCKED, true) && !in_array($name, $this->disabled, true)) {
            $this->tools[$name] = $tool;
        }
    }

    public function get_schemas(): array {
        return array_values(array_map(fn($t) => $t->get_schema(), $this->tools));
    }

    public function execute(string $name, array $input): array {
        if (!isset($this->tools[$name])) {
            $available = implode(', ', array_keys($this->tools));
            return ['error' => "Unknown tool: \"$name\". Available tools: $available. Use only exact names from this list."];
        }

        $tool = $this->tools[$name];

        if (!$tool->check_permission()) {
            return ['error' => 'Insufficient permissions for this operation.'];
        }

        $validated = $tool->validate_input($input);
        if (is_wp_error($validated)) {
            return ['error' => $validated->get_error_message()];
        }

        return $tool->execute($validated);
    }
}
