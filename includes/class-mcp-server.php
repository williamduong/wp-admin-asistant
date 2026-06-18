<?php

defined('ABSPATH') || exit;

/**
 * MCP (Model Context Protocol) server — JSON-RPC 2.0 over HTTP.
 *
 * Exposes all registered WAA tools to any MCP-compatible AI client
 * (Claude Desktop, Claude Code, IDE extensions, custom bridges).
 *
 * Endpoint: POST /wp-json/wp-admin-agent/v1/mcp
 *
 * Supported methods:
 *   initialize    — handshake, returns server info + capabilities
 *   tools/list    — list all registered tools in MCP schema format
 *   tools/call    — execute a tool and return the result
 *
 * MCP spec: https://modelcontextprotocol.io/specification
 */
class WAA_MCP_Server {
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME      = 'wp-admin-agent';
    private const SERVER_VERSION   = '1.0.0';

    public function __construct(
        private readonly WAA_Tool_Registry $registry
    ) {}

    public function handle(WP_REST_Request $request): WP_REST_Response {
        $body   = $request->get_json_params();
        $id     = $body['id']     ?? null;
        $method = $body['method'] ?? '';

        if (($body['jsonrpc'] ?? '') !== '2.0') {
            return $this->error($id, -32600, 'Invalid Request: missing jsonrpc 2.0');
        }

        return match ($method) {
            'initialize'  => $this->handle_initialize($id),
            'tools/list'  => $this->handle_tools_list($id),
            'tools/call'  => $this->handle_tools_call($id, $body['params'] ?? []),
            default       => $this->error($id, -32601, "Method not found: $method"),
        };
    }

    // -------------------------------------------------------------------------
    // MCP method handlers
    // -------------------------------------------------------------------------

    private function handle_initialize(mixed $id): WP_REST_Response {
        return $this->result($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'serverInfo'      => [
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'instructions' => 'WordPress Admin Agent — manage plugins, themes, posts, users, and settings via natural language.',
        ]);
    }

    private function handle_tools_list(mixed $id): WP_REST_Response {
        $tools = [];
        foreach ($this->registry->get_schemas() as $schema) {
            $tools[] = [
                'name'        => $schema['name'],
                'description' => $schema['description'],
                'inputSchema' => $schema['input_schema'],
            ];
        }
        return $this->result($id, ['tools' => $tools]);
    }

    private function handle_tools_call(mixed $id, array $params): WP_REST_Response {
        $name      = $params['name']      ?? '';
        $arguments = $params['arguments'] ?? [];

        if (empty($name)) {
            return $this->error($id, -32602, 'Invalid params: missing tool name');
        }

        try {
            $result = $this->registry->execute($name, $arguments);
            return $this->result($id, [
                'content' => [
                    ['type' => 'text', 'text' => wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
                ],
                'isError' => false,
            ]);
        } catch (Throwable $e) {
            // MCP spec: tool errors are returned as content with isError=true, not JSON-RPC errors
            return $this->result($id, [
                'content' => [
                    ['type' => 'text', 'text' => $e->getMessage()],
                ],
                'isError' => true,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // JSON-RPC helpers
    // -------------------------------------------------------------------------

    private function result(mixed $id, array $result): WP_REST_Response {
        return new WP_REST_Response([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ], 200);
    }

    private function error(mixed $id, int $code, string $message): WP_REST_Response {
        return new WP_REST_Response([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ], 200); // JSON-RPC errors still return HTTP 200
    }
}
