# WP Admin AI Assistant Source Bundle

This bundle contains the source code and documentation for local development and local WordPress installation.

## What is included

- Plugin source code
- React source code
- WordPress plugin PHP source
- Tests
- Release notes
- Knowledge-base documentation
- Local `wp-env` configuration

## What is intentionally excluded

- `.git` metadata
- GitHub workflows and local agent config
- `node_modules`
- `vendor`
- coverage output
- test result artifacts
- prebuilt frontend asset output

## Local install options

### Option A: Local development with `wp-env`

Requirements:

- Node.js 18+
- Docker

Steps:

```bash
npm install
npm run build
npm run env:start
```

Then open:

- Main site: `http://localhost:8888`
- Test site: `http://localhost:8889`

Activate the plugin in WordPress admin and configure the provider in **Settings -> Admin Agent**.

### Option B: Install into an existing local WordPress site

1. Extract this folder into:

```text
wp-content/plugins/wp-admin-ai-assistant
```

2. From the plugin folder, run:

```bash
npm install
npm run build
```

3. Activate **WP Admin Agent** from the WordPress Plugins screen.

4. Open **Settings -> Admin Agent** and configure one of:

- Anthropic
- Gemini
- Ollama

## Important note

This bundle excludes generated frontend build artifacts on purpose. The recipient must run `npm install` and `npm run build` before using the plugin locally.

## Documentation map

- `README.md`: project overview and quick setup
- `RELEASE_NOTES_v0.2.0.md`: release summary
- `knowledge-base/`: architecture, setup, runtime, and extension guides
