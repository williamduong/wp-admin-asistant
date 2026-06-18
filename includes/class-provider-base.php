<?php

defined('ABSPATH') || exit;

/**
 * Normalized response from any provider:
 * [
 *   'stop_reason' => 'end_turn' | 'tool_use',
 *   'text'        => string,
 *   'tool_calls'  => [['id' => string, 'name' => string, 'input' => array], ...],
 * ]
 *
 * Normalized message history (passed to complete()):
 *   ['role' => 'user',      'content' => string]
 *   ['role' => 'assistant', 'content' => string, 'tool_calls' => [...]]
 *   ['role' => 'tool',      'tool_call_id' => string, 'tool_name' => string, 'result' => array]
 */
abstract class WAA_Provider_Base {
    /**
     * Send one turn to the AI and return a normalized response.
     *
     * Internal message contract (Anthropic-style, used across all providers):
     *   user:      ['role'=>'user', 'content'=>string]
     *   assistant: ['role'=>'assistant', 'content'=>string, 'tool_calls'=>[['id','name','input'],...]]
     *   tool:      ['role'=>'tool', 'tool_call_id'=>string, 'tool_name'=>string, 'result'=>array]
     *
     * Tools schema contract (Anthropic-style):
     *   ['name'=>string, 'description'=>string, 'input_schema'=>['type'=>'object','properties'=>[...]]]
     *
     * @param string $system   System prompt (base, without model-specific additions)
     * @param array  $messages Normalized history
     * @param array  $tools    Tool schemas in internal format
     * @return array ['stop_reason'=>string, 'text'=>string, 'tool_calls'=>array, 'usage'=>array]
     */
    abstract public function complete(string $system, array $messages, array $tools): array;

    abstract public function get_id(): string;
    abstract public function get_label(): string;

    /**
     * Model-specific additions to the system prompt.
     * Override to guide behavior for this particular provider/model.
     */
    public function get_model_instructions(): string {
        return '';
    }
}
