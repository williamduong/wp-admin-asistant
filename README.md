# WP Admin AI Assistant

> Natural language AI assistant embedded in WordPress admin. Manage plugins, themes, posts, users, and settings through conversation.

**Internal demo — GoRight AI**

---

## What it does

An AI chat widget that lives in every `wp-admin` page. Administrators type commands in plain language; the agent calls WordPress tools to carry them out and shows the results in real time.

**Example commands:**
- *"Install and activate the Wordfence security plugin"*
- *"Write a rich post about the latest AI news with a featured image"*
- *"Switch to a minimal dark theme"*
- *"Apply security hardening: disable XML-RPC, close comments, hide WP version"*
- *"Show me a flowchart of how the plugin works"*

---

## Supported AI providers

| Provider | Notes |
|---|---|
| **Anthropic Claude** | Best tool-use reliability. Recommended: `claude-haiku-4-5` |
| **Google Gemini** | Free tier available. Recommended: `gemini-2.5-flash` |
| **Ollama** | Local / self-hosted, no API key needed |

---

## Quick setup

### Requirements

| | Minimum |
|---|---|
| PHP | 8.2+ |
| WordPress | 6.5+ |
| Node.js | 18+ *(dev only)* |
| Docker | *(dev only, for wp-env)* |

### Install (development)

```bash
git clone https://github.com/goright-ai/wp-admin-agent
cd wp-admin-agent
npm install
npm run build
wp-env start
```

WordPress runs at `http://localhost:8888`. Use the credentials from your local `wp-env` bootstrap or create your own admin user for development.

Activate the plugin via **Plugins → Installed Plugins → WP Admin Agent → Activate**, then go to **Settings → Admin Agent** to enter your API key.

### Install (production)

1. `npm run build` on your machine
2. Upload the entire folder to `wp-content/plugins/wp-admin-agent/`
3. Activate and configure in **Settings → Admin Agent**

---

## Full documentation

Complete documentation is built into the plugin itself.

After activating, go to **Settings → Admin Agent → 📖 Docs** for:

| Doc | Content |
|---|---|
| `01-overview` | Architecture, three-layer design, tool registry |
| `02-setup` | Full installation guide, dev environment, env vars |
| `03-data-flow` | Request lifecycle, SSE streaming, MCP path |
| `04-memory-prompt-history` | History format, context building, localStorage |
| `05-adding-tools` | How to create new tools (template + full walkthrough) |
| `06-adding-providers` | How to add a new AI provider |
| `07-embedding-bot` | Embedding the widget, custom integrations |

---

## Available tools

| Category | Tools |
|---|---|
| **Settings** | `get_site_settings`, `update_site_settings` |
| **Plugins** | `list_plugins`, `install_plugin`, `activate_plugin`, `deactivate_plugin` |
| **Themes** | `list_themes`, `search_themes`, `install_theme`, `switch_theme` |
| **Posts** | `list_posts`, `create_simple_post`, `create_rich_post`, `update_post` |
| **Images** | `search_images` (Pexels), `set_post_image` |
| **Users** | `list_users`, `update_user_role` |
| **Content** | `fetch_rss` (23 curated tech/science feeds) |
| **Security** | `security_harden` |
| **UI** | `navigate`, `search_icon`, `set_site_icon` |
| **WooCommerce** | `get_woocommerce_status`, `update_woocommerce_settings`, `list_woocommerce_products`, `create_woocommerce_product`, `update_woocommerce_product`, `list_woocommerce_orders`, `update_woocommerce_order_status`, `create_woocommerce_coupon` |

Mermaid diagrams supported in posts via `[mermaid]...[/mermaid]` shortcode.

---

## Development

```bash
npm run dev        # watch mode
npm run build      # production bundle
npm run test:js    # Vitest
npm run lint       # ESLint
```

### Runtime test baseline

```bash
npm run env:start             # start wp-env dev + tests sites
npm run test:php:setup        # install PHP test dependencies in tests-cli
npm run test:php:integration  # run Runtime/Wave 0 PHP integration checks
npm run test:e2e              # browser validation against the running site
```

`wp-env` serves the main site at `http://localhost:8888` and the test site at `http://localhost:8889`.

Stack: **React 18 + Vite** (IIFE bundle) · **PHP 8.2** · **WordPress REST API** · **SSE streaming**

---

## Security

- All endpoints require `manage_options` capability
- API keys encrypted at rest with AES-256
- Rate limited: 30 requests/minute per user
- All tool executions logged to `waa_logs` DB table

---

*By [William GoRight](https://williamresearch.com/) · GoRight AI*
