<?php

defined('ABSPATH') || exit;

abstract class WAA_Tool_Base {
    abstract public function get_name(): string;
    abstract public function get_description(): string;
    abstract public function get_input_schema(): array;
    abstract public function execute(array $input): array;

    public function check_permission(): bool {
        return current_user_can('manage_options');
    }

    public function validate_input(array $input): array|WP_Error {
        return $input;
    }

    final public function get_schema(): array {
        return [
            'name'         => $this->get_name(),
            'description'  => $this->get_description(),
            'input_schema' => $this->get_input_schema(),
        ];
    }
}
