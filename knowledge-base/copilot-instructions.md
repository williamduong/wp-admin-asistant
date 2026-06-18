---
description: "WordPress Admin AI Assistant: AI-powered chat interface for WordPress administrators to manage plugins, themes, posts, users, and settings using natural language commands. Supports Anthropic Claude, Google Gemini, and Ollama providers."
version: "0.2.0"
---

# WordPress Admin AI Assistant - Copilot Instructions

## Project Overview

This is a WordPress plugin that provides an AI-powered chat assistant in the WordPress admin panel (`wp-admin`). Administrators can control WordPress settings, plugins, themes, posts, and users using conversational commands like "Deactivate Jetpack" or "Switch to Twenty Twenty-Four theme".

**Key Technologies:**
- **Backend:** PHP 8.2+, WordPress 6.5+
- **Frontend:** React 18 with Vite build system
- **AI Providers:** Anthropic Claude, Google Gemini, Ollama (local models)
- **Communication:** Server-Sent Events (SSE) for real-time streaming
- **Security:** AES-256 encryption, rate limiting, audit logging

## Architecture

### Three-Layer Design
1. **Frontend** → React chat widget with SSE streaming
2. **Backend** → PHP agentic loop with REST API
3. **AI Providers** → Abstracted provider system

### Agentic Loop
The core operates as a generator-based agent that:
- Builds system prompt with WordPress context
- Calls AI provider with available tool schemas
- Executes WordPress tools via registry
- Logs to audit table
- Streams results back to frontend

## Key Files and Components

### Main Entry Points
- `wp-admin-agent.php` - Plugin bootstrap, constants, autoloader
- `src/App.jsx` - React app root
- `includes/class-plugin.php` - WordPress hooks and initialization
- `includes/class-rest-api.php` - REST API endpoints

### Backend Classes (includes/)
- `class-agent.php` - Core agentic loop implementation
- `class-provider-*.php` - AI provider implementations
- `class-tool-registry.php` - Tool discovery and execution
- `class-encryptor.php` - API key encryption
- `class-audit-log.php` - Logging and statistics
- `class-rate-limiter.php` - Per-user rate limiting

### Tools System (tools/)
All tools extend `WAA_Tool_Base` and implement:
- `get_name()`, `get_description()`, `get_input_schema()`, `execute()`

Available tools: list/activate/deactivate plugins, list/update posts, list/update users, list/switch themes, get/update settings.

### Frontend Components (src/components/)
- `ChatWidget.jsx` - Main chat interface
- `MessageList.jsx` - Conversation display
- `InputBar.jsx` - User input with send button
- `QuickPrompts.jsx` - Suggested command chips
- `SessionStats.jsx` - Token usage and cost display

### Configuration
- `vite.config.js` - Frontend build configuration
- `package.json` - Dependencies and scripts
- Admin pages in `admin/` folder

## Coding Patterns and Conventions

### PHP Backend
- **Class Naming:** `WAA_Class_Name` (WordPress Admin Agent prefix)
- **File Naming:** `class-name.php` (lowercase, hyphens)
- **Autoloading:** PSR-4 with `WAA_` namespace
- **Security:** Always check `manage_options` capability
- **Error Handling:** Return structured arrays with `success`, `error`, `data`
- **Database:** Use `$wpdb` with prepared statements

### JavaScript/React Frontend
- **State Management:** Custom `useChat` hook
- **Styling:** CSS Modules (`.module.css` files)
- **API Calls:** Centralized in `lib/api.js`
- **Streaming:** SSE parsing with async generators
- **Components:** Functional components with hooks

### Tool Development
```php
class WAA_Tool_Example extends WAA_Tool_Base {
    public function get_name() { return 'example'; }
    public function get_description() { return 'Description of what this tool does'; }
    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'param' => ['type' => 'string', 'description' => 'Parameter description']
            ],
            'required' => ['param']
        ];
    }
    public function execute($args) {
        // Implementation
        return ['success' => true, 'data' => $result];
    }
}
```

### Provider Development
```php
class WAA_Provider_Example extends WAA_Provider_Base {
    public function get_id() { return 'example'; }
    public function get_label() { return 'Example Provider'; }
    public function complete($messages, $tools) {
        // API call implementation
        return $response;
    }
}
```

## Development Workflow

### Setup
1. Clone repository
2. Run `npm install` for frontend dependencies
3. Activate plugin in WordPress admin
4. Configure AI provider in settings page

### Building
- `npm run dev` - Development build with watch
- `npm run build` - Production build
- `npm run test:js` - Run JavaScript tests (Vitest)
- `npm run lint` - ESLint checking

### Testing
- **JS Tests:** Vitest + Testing Library in `tests/js/`
- **PHP Tests:** PHPUnit structure in `tests/php/` (unit and integration)
- **E2E Tests:** Playwright in `tests/e2e/`

## Security Considerations

### WordPress-Specific Security
- Always validate user capabilities (`manage_options`)
- Use WordPress nonces for API requests
- Sanitize and validate all inputs
- Never expose sensitive data in responses

### API Key Management
- Encrypt API keys with AES-256-CBC using WordPress `AUTH_KEY`
- Store encrypted in `wp_options`
- Never log or expose keys in responses

### Rate Limiting
- 30 requests/minute per user (configurable)
- Implemented via WordPress transients

### Audit Logging
- All tool executions logged to `waa_logs` table
- Tracks user, tool, parameters, results, token usage
- Cost calculation per provider/model

## AI Integration Patterns

### System Prompt Construction
Include WordPress context: site URL, title, WP version, timezone, current user.

### Tool Calling
- Use JSON Schema for tool parameters
- Validate inputs before execution
- Return structured success/error responses
- Limit iterations (max 10) to prevent loops

### Streaming Response
- SSE events: `text_delta`, `tool_start`, `tool_end`, `usage`
- Handle errors gracefully in stream
- Support conversation cancellation

## Important Notes for Contributors

### Adding New Tools
1. Create `tools/class-tool-{name}.php`
2. Extend `WAA_Tool_Base`
3. Implement required methods
4. Auto-registered via autoloader

### Adding New Providers
1. Create `includes/class-provider-{name}.php`
2. Extend `WAA_Provider_Base`
3. Add to `WAA_Provider_Factory::make()` method

### Database Migrations
- Use activation hook for table creation
- Include version checks for upgrades
- Follow WordPress database best practices

### Frontend Development
- Use CSS Modules for component styling
- Follow React best practices (hooks, functional components)
- Test components with Testing Library
- Maintain 70% code coverage

### WordPress Integration
- Hook into appropriate WordPress actions/filters
- Use WordPress functions for data access
- Follow WordPress coding standards
- Test on multiple WordPress versions

## Common Patterns

### REST API Responses
```php
return [
    'success' => true,
    'data' => $result,
    'message' => 'Optional success message'
];
```

### Error Handling
```php
return [
    'success' => false,
    'error' => 'Error message',
    'code' => 'error_code'
];
```

### SSE Event Format
```javascript
yield { type: 'text_delta', content: 'text chunk' };
yield { type: 'tool_start', tool: 'tool_name' };
yield { type: 'tool_end', result: $tool_result };
yield { type: 'usage', tokens: 150, cost: 0.001 };
```

This project follows WordPress and modern PHP/JavaScript best practices while implementing advanced AI agent patterns. Focus on security, extensibility, and user experience when contributing.
