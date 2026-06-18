# WP Admin Agent v0.2.0

`v0.2.0` is the first Runtime V1 milestone release. It upgrades the plugin from a basic tool-calling chat widget into a safer, more structured admin assistant with session continuity, confirmation policy, stronger regression coverage, and the first WooCommerce control surface.

## Highlights

- Runtime V1 backbone for session persistence and restored conversations
- Action classification and confirmation framework for sensitive writes
- Hallucination guards and safer async/background task handling
- Improved session history UX with search, rename, archive, and new-session flow
- Task-status rail that separates execution state from the chat transcript
- First WooCommerce setup and store-operation toolset
- Expanded docs and teaching material for architecture, orchestration, and Runtime V1 concepts

## New

### Runtime V1 architecture

- Added conversation persistence with `conversation_id`
- Restored conversations can continue live runtime loops instead of starting from scratch
- Normalized runtime history before provider calls
- Added context-window trimming to reduce runaway history growth
- Added dedicated orchestration documentation and teaching guides

### Confirmation and policy system

- Added runtime-owned confirmation flow for sensitive and destructive actions
- Added structured confirmation metadata:
  - action type
  - risk level
  - impact
  - current/proposed state
  - custom confirm/cancel labels
- Added conditional confirmation rules for content actions like publish/private/trash
- Added install and site-level protection for plugin, theme, settings, and security operations

### UX improvements

- Added `TaskRail` for:
  - awaiting approval
  - working state
  - background task queued state
- Improved confirmation UX copy and removed duplicate/noisy confirmation rendering
- Added session management improvements:
  - new session flow
  - search
  - sorting
  - rename
  - archive
  - smarter auto-titling

### WooCommerce tools

Added the first WooCommerce tool batch:

- `get_woocommerce_status`
- `update_woocommerce_settings`
- `list_woocommerce_products`
- `create_woocommerce_product`
- `update_woocommerce_product`
- `list_woocommerce_orders`
- `update_woocommerce_order_status`
- `create_woocommerce_coupon`

These tools let the assistant inspect store readiness, update core store settings, manage products, review orders, change order status, and create coupon codes.

## Improved

- Better session/history restoration behavior across turns
- Safer handling of SSE/tool iterations and pending confirmations
- Smarter conversation titles based on actual tool outcomes
- More complete browser-level and PHP integration coverage for policy rules
- System Prompt tab cleanup and prompt/runtime guidance clarity
- Open-source hygiene improvements:
  - removed hard-coded public infrastructure references
  - replaced token-like placeholders with generic examples
  - generalized deploy workflow to use secrets instead of environment-specific values

## Testing and quality

Added or expanded:

- PHP integration harness for runtime safety
- deterministic fake provider and fixtures
- JS runtime regression tests
- Playwright e2e coverage for:
  - confirm/cancel flows
  - content policy flows
  - hallucination guards

## Upgrade notes

- Default plugin version is now `0.2.0`
- Ollama default URL now falls back to `http://localhost:11434`
- Deploy workflow now expects generic repository secrets such as:
  - `REMOTE_PATH`
  - `WP_SITE_URL`
  - `WP_SITE_TITLE`
  - `WP_ADMIN_USER`
  - `WP_ADMIN_PASSWORD`
  - `WP_ADMIN_EMAIL`
  - optional `BOOTSTRAP_PLUGIN_SLUGS`
  - optional `OLLAMA_URL`
  - optional `DEFAULT_PROVIDER`

## Known limitations

- Runtime V1 is still a bounded orchestration runtime, not a full autonomous multi-agent planner
- Async/background workflows are improved, but not yet a complete job framework
- WooCommerce coverage currently targets core setup and basic store operations, not full advanced store administration

## Suggested GitHub release title

`WP Admin Agent v0.2.0 — Runtime V1, confirmation safety, session UX, and WooCommerce tools`
