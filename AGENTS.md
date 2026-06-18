# WP Admin AI Assistant — Codex Guide

## Project summary

WordPress plugin: AI chat agent embedded in `wp-admin`. Supports Anthropic Codex, Google Gemini, and Ollama. Admins manage plugins, themes, posts, users, settings through natural language.

## Key commands

```bash
npm run dev        # React dev build (watch)
npm run build      # Production build
npm run test:js    # Vitest
npm run lint       # ESLint
```

WordPress env: `wp-env start` / `wp-env stop` (Docker).

## Architecture — three layers

```
Browser (React + SSE)
    → REST API (class-rest-api.php)
    → Agent loop (class-agent.php) → Provider (class-provider-*.php)
                                   → Tool registry (tools/)
```

## Internal message contract

All providers speak a common internal format:

| Role | Fields |
|------|--------|
| `user` | `role`, `content` (string) |
| `assistant` | `role`, `content`, `tool_calls` → `[id, name, input]` |
| `tool` | `role`, `tool_call_id`, `tool_name`, `result` |

Tools schema is Anthropic-format: `[name, description, input_schema]`.  
Each provider's `to_X_messages()` method maps this to the API's native format.

## Provider key mapping (common bug source)

| Internal key | Anthropic | Gemini | Ollama |
|---|---|---|---|
| `input` (tool args) | `input` | **`args`** | `arguments` |
| `functionCall` | `tool_use` | `functionCall` | `tool_calls[].function` |

When adding or modifying a provider, double-check these mappings in both `to_X_messages()` and `normalize()`.

## Expanding capabilities — standard flow

```
Check (WP API exists?) → Write (if not) → Wrap (WAA Tool) → MCP (auto-exposed)
```

Full guide: [knowledge-base/expanding-capabilities.md](knowledge-base/expanding-capabilities.md)

Key infrastructure for non-text capabilities:
- `WAA_Resource_Fetcher` — safe URL download (mime + size validation)
- `WAA_Media_Importer` — URL → WP Media Library → `attachment_id`
- `WAA_MCP_Server` — JSON-RPC 2.0, auto-exposes all registered tools

## Adding a tool

1. Create `tools/class-tool-{name}.php` extending `WAA_Tool_Base`
2. Implement: `get_name()`, `get_description()`, `get_input_schema()`, `execute()`
3. Register in `class-rest-api.php → build_registry()`

Use `/add-tool` slash command for a scaffolded template.

## Adding a provider

1. Create `includes/class-provider-{name}.php` extending `WAA_Provider_Base`
2. Implement: `get_id()`, `get_label()`, `complete()`, `get_model_instructions()`
3. Add to `WAA_Provider_Factory::make()`

`get_model_instructions()` returns model-specific additions to the system prompt (tool calling style, limitations, etc.).

## Provider-specific instructions (system prompt branching)

`WAA_Agent::system_prompt()` appends `$provider->get_model_instructions()` to the base prompt.  
Each provider defines its own guidance — see `class-provider-*.php`.

## Security rules

- All endpoints require `manage_options` capability
- API keys encrypted with AES-256 via `WAA_Encryptor`
- All tool executions logged to `waa_logs` table
- Rate limit: 30 req/min per user (WordPress transients)

## File naming

- PHP classes: `WAA_Class_Name` → `class-class-name.php`
- Tools: `WAA_Tool_*` → `tools/class-tool-*.php`
- Providers: `WAA_Provider_*` → `includes/class-provider-*.php`
