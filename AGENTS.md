# WP Admin Agent - Codex Operating Guide

This file is the primary handoff for Codex when working on `wp-content/plugins/wp-admin-assistant`.

## Scope

- Product: WordPress admin AI assistant plugin
- Plugin slug/folder: `wp-admin-assistant`
- Main plugin file: `wp-admin-agent.php`
- Current version constant: `WAA_VERSION`
- Nested Git repo root: `/home/duongtannghia/projects/wpdemo/wp-content/plugins/wp-admin-assistant`
- Host WordPress project root: `/home/duongtannghia/projects/wpdemo`

Treat the plugin directory as the canonical repo. The outer `wpdemo` folder is the runtime container project.

## What Codex Should Optimize For

Use this repo to:

- develop new features
- fix bugs and regressions
- add or adjust tools/providers
- maintain docs and release notes
- test runtime, UI, and integration behavior
- package a distributable plugin build
- prepare a release safely without breaking the demo site
- manage GitHub issues as the default follow-up mechanism for unfinished, risky, or deferred work

## Issue Management Policy

For this repo, GitHub Issues are the default follow-up system.

Codex should treat issues as part of the working process, not as optional admin overhead.

### When Codex should create or update an issue

Create a GitHub issue when any of these are true:

- a bug is found but not fixed in the current task
- a limitation is confirmed and needs follow-up
- a risky refactor should be split into a later task
- docs reveal stale behavior that needs code work later
- testing exposes an intermittent or environment-specific failure
- a feature request becomes concrete enough to schedule
- release readiness has a known blocker or gap

Update an existing issue instead of creating a new one when the work is clearly the same follow-up thread.

### Default issue expectations

A useful issue in this repo should usually contain:

- clear title
- current behavior
- expected behavior or target outcome
- impact
- likely affected area
- proposed next step
- acceptance checks if known

### Agent behavior

When working in this repo, Codex should:

- check whether a follow-up belongs in GitHub Issues
- prefer creating an issue instead of leaving a loose TODO in chat
- reference issue IDs in later code, docs, or release notes when relevant
- close the loop by updating the issue after the work is done

### Terminal flow

GitHub CLI is installed for this repo's workflow.

Available helper scripts:

- `scripts/create-issue.sh`
- `scripts/follow-up-issue.sh`

If `gh` is not authenticated, run:

```bash
gh auth login --hostname github.com --git-protocol ssh --web
```

## Runtime Topology

There are two environment stories in the repo. Only one is fully present here.

### 1. Active local environment in this checkout

The outer project uses Docker Compose:

- compose file: `/home/duongtannghia/projects/wpdemo/docker-compose.yml`
- container names:
  - `wpdemo_web`
  - `wpdemo_db`
- WordPress content mount:
  - host `./wp-content`
  - container `/var/www/html/wp-content`

This means plugin file edits on disk are reflected immediately inside the running WordPress container.

Use WP-CLI through the container:

```bash
docker exec wpdemo_web sh -lc 'wp --allow-root --path=/var/www/html plugin list'
docker exec wpdemo_web sh -lc 'wp --allow-root --path=/var/www/html theme list'
docker exec wpdemo_web sh -lc 'wp --allow-root --path=/var/www/html option get siteurl'
```

### 2. `wp-env` scripts exist, but config is not checked in here

`package.json` contains:

- `npm run env:start`
- `npm run env:stop`
- `npm run test:php:setup`
- `npm run test:php`
- `npm run test:php:integration`

Those assume a `wp-env` setup, but this checkout does not include `.wp-env.json`. Do not assume `wp-env` works until that config is restored or supplied elsewhere.

Practical rule:

- for live development in this workspace, prefer the Docker Compose site
- for PHP integration scripts, verify whether `wp-env` is available before relying on those npm commands

## Architecture

High-level flow:

```text
React widget in wp-admin
  -> REST API routes
  -> WAA_Agent runtime loop
  -> provider adapter
  -> tool registry
  -> WordPress / WooCommerce mutations
```

Core files:

- `wp-admin-agent.php`
  - plugin header
  - constants
  - class autoloader
  - activation/deactivation hooks
- `includes/class-plugin.php`
  - plugin bootstrap
  - admin hooks
  - DB table creation
  - asset enqueue
  - settings page and dashboard widget
- `includes/class-rest-api.php`
  - REST routes
  - SSE chat endpoint
  - conversation CRUD
  - settings/stats/pricing endpoints
  - MCP endpoint
- `includes/class-agent.php`
  - system prompt construction
  - tool loop
  - confirmation policy
  - usage/trace events
  - navigation events after writes
- `includes/class-provider-factory.php`
  - provider selection
- `includes/class-provider-*.php`
  - Anthropic, Gemini, Ollama, Fake adapters
- `includes/class-tool-registry.php`
  - tool registration, blocking, validation, execution
- `tools/class-tool-*.php`
  - actual WordPress and WooCommerce capabilities
- `src/`
  - React source
- `assets/`
  - built frontend bundle loaded by WordPress

## Important Domain Rules

The plugin is intentionally conservative around writes.

### Confirmation policy

Sensitive actions are classified in `WAA_Agent` and can require explicit confirmation before tool execution. Existing guarded categories include:

- plugin install/activate/deactivate
- theme install/switch
- site settings changes
- security changes
- user role changes
- site icon changes
- some content status changes such as `publish`, `private`, `trash`

If you add a new destructive or site-wide tool, update the confirmation classification, not just the tool itself.

### Permission model

- REST routes require `manage_options`
- tools also perform permission checks via `WAA_Tool_Base`
- rate limiting applies to chat, MCP, and connection-test routes

### Persistence model

Two custom tables are created on activation:

- `waa_logs`
- `waa_conversations`

Do not change conversation payload shape casually. Runtime history, session restore, and UI state depend on it.

## Source Map

Use this when choosing where to edit.

### PHP runtime

- `includes/class-agent.php`
  - orchestration loop and event emission
- `includes/class-settings.php`
  - provider keys, models, plugin settings
- `includes/class-audit-log.php`
  - tool execution logging
- `includes/class-rate-limiter.php`
  - per-user request limits
- `includes/class-mcp-server.php`
  - JSON-RPC exposure of tools
- `includes/class-media-importer.php`
  - URL/media ingestion
- `includes/class-resource-fetcher.php`
  - remote fetch safety checks

### Admin UI and embedding

- `admin/settings-page.php`
  - settings screen and embedded docs
- `admin/dashboard-widget.php`
  - recent actions widget
- `src/App.jsx`
  - UI root
- `src/hooks/useChat.js`
  - runtime client state, SSE handling, persistence, workflow state
- `src/lib/api.js`
  - REST client
- `src/lib/sse.js`
  - SSE parser
- `src/lib/workflows.js`
  - wizard/workflow launch and step logic

### Tool surface

Notable families:

- plugin/theme management
- post creation and updates
- media/image handling
- site settings
- WooCommerce products, orders, coupons, settings
- Wordfence integration
- navigation/UI helpers

When adding a new capability:

1. prefer native WordPress or WooCommerce APIs first
2. encapsulate it in a `WAA_Tool_*`
3. register it in the REST registry builder
4. cover validation and confirmation behavior

## Naming and Loading Conventions

Autoloading depends on file naming.

- `WAA_Foo_Bar` -> `includes/class-foo-bar.php`
- `WAA_Tool_Foo_Bar` -> `tools/class-tool-foo-bar.php`

If a new class name and file name drift apart, WordPress will fail at runtime rather than at build time.

## Frontend Build Rules

Frontend source lives in `src/`. WordPress serves built files from `assets/`.

Build config:

- `vite.config.js`
- output dir: `assets`
- main JS output: `assets/js/admin-agent.js`
- CSS output: `assets/css/admin-agent.css`

Important consequence:

- `npm run build` clears and rebuilds `assets/`
- never hand-edit compiled files in `assets/` as a source of truth
- edit `src/` and rebuild

## Test Surface

### JavaScript

- test runner: Vitest
- config lives in `vite.config.js`
- tests:
  - `tests/js/components/*.test.jsx`
  - `tests/js/hooks/useChat.test.js`
  - `tests/js/lib/sse.test.js`

Commands:

```bash
npm run test:js
npm run test:js:runtime
npm run lint
```

Coverage floor in config:

- lines: `60`

### PHP

- bootstrap: `tests/php/bootstrap.php`
- suite: `tests/php/integration`
- config: `phpunit.xml.dist`

Tests currently cover integration-style behavior, especially runtime safety and fake-provider flows.

### Browser E2E

- Playwright config: `playwright.config.js`
- suite: `tests/e2e`

Current coverage includes:

- confirmation flows
- hallucination guards

### Suggested validation strategy

For a pure UI change:

1. `npm run lint`
2. `npm run test:js`
3. `npm run build`

For a PHP/runtime change:

1. `npm run build` if frontend payload changed
2. `npm run test:js` if the client stream/UI changed
3. `npm run test:php` or `npm run test:php:integration` if `wp-env` is actually available
4. manual smoke test on the Docker Compose WordPress site

For tooling or destructive-action changes:

1. verify confirmation behavior
2. verify navigation event behavior after success
3. verify audit logs still capture tool runs

## Manual Smoke Test Checklist

Use the running demo site in the Docker container.

Check:

1. plugin activates without fatal errors
2. settings page loads
3. admin widget mounts on a `wp-admin` page
4. message streaming works
5. a safe read-only tool works
6. a guarded write asks for confirmation
7. approving the action executes the tool and updates the UI
8. conversation history persists and reloads

## Build and Packaging

This repo does not include an active checked-in GitHub release workflow. Packaging is currently manual.

### Development build

```bash
cd /home/duongtannghia/projects/wpdemo/wp-content/plugins/wp-admin-assistant
npm install
npm run build
```

### Packaging intent

A distributable plugin zip should include:

- PHP source
- built `assets/`
- `vendor/` if runtime depends on Composer-installed packages
- docs that belong with the release

It should exclude:

- `.git`
- `node_modules`
- `coverage`
- local artifacts
- test result artifacts

### Practical manual package flow

Run from the plugin repo root after a clean build:

```bash
VERSION="$(sed -n "s/^ \\* Version:\\s*//p" wp-admin-agent.php | head -n 1 | xargs)"
rm -rf dist
mkdir -p dist/wp-admin-assistant
rsync -a ./ dist/wp-admin-assistant/ \
  --exclude '.git' \
  --exclude 'node_modules' \
  --exclude 'coverage' \
  --exclude 'dist' \
  --exclude '.DS_Store'
cd dist && zip -r "wp-admin-assistant-${VERSION}.zip" wp-admin-assistant
```

Before shipping a zip, verify that:

- `assets/js/admin-agent.js` exists
- `vendor/autoload.php` exists if Composer dependencies are needed at runtime
- the plugin root folder inside the zip is exactly `wp-admin-assistant`

## Release Discipline

When preparing a release:

1. confirm version in `wp-admin-agent.php`
2. rebuild frontend assets
3. run relevant tests
4. update `README.md` if setup or capability changed
5. add or update release notes such as `RELEASE_NOTES_vX.Y.Z.md`
6. package from a clean state
7. install the zip on a test WordPress site before publishing

If the release changes runtime behavior, also review:

- `knowledge-base/08-runtime-v1-current-state.md`
- `knowledge-base/09-runtime-v1-teaching-guide.md`
- `knowledge-base/10-runtime-v1-cheatsheet.md`
- `knowledge-base/11-runtime-v1-orchestration-analysis.md`

## Known Repo Realities

These are easy places for Codex to make bad assumptions.

- The plugin folder name is `wp-admin-assistant`, but the plugin name and main file are `WP Admin Agent` and `wp-admin-agent.php`.
- The outer `wpdemo` directory is not the plugin Git repo.
- `wp-env` npm scripts exist, but `.wp-env.json` is missing in this checkout.
- Built assets are committed in `assets/`; source-of-truth UI code is in `src/`.
- The demo environment is WooCommerce-enabled, so changes can affect both WordPress admin and store workflows.

## Preferred Editing Strategy

When making changes:

1. identify whether the source of truth is PHP in `includes/` or React in `src/`
2. avoid editing generated `assets/` by hand unless absolutely necessary
3. keep tool names stable unless a migration is intentional
4. keep confirmation policy aligned with new write behavior
5. run the narrowest relevant tests first, then a broader smoke test

## Recommended Reading Order

For a new Codex session:

1. `README-DEV.md`
2. `README.md`
3. this `AGENTS.md`
4. `includes/class-plugin.php`
5. `includes/class-rest-api.php`
6. `includes/class-agent.php`
7. the specific tool/provider files relevant to the task

## Deep-Dive Docs

Use the built-in knowledge base for detailed design context:

- `knowledge-base/01-overview.md`
- `knowledge-base/02-setup.md`
- `knowledge-base/03-data-flow.md`
- `knowledge-base/04-memory-prompt-history.md`
- `knowledge-base/05-adding-tools.md`
- `knowledge-base/06-adding-providers.md`
- `knowledge-base/07-embedding-bot.md`
- `knowledge-base/expanding-capabilities.md`

If the task is specifically about runtime orchestration, start with the Runtime V1 docs in `knowledge-base/08` through `11`.
