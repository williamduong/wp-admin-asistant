# Runtime V1 Session And SSE Migration Risks

This note records the current browser-owned session and SSE assumptions that must not regress during the Runtime V1 session backbone refactor.

## Current ownership model

- The active unsaved chat session is owned by the browser via `localStorage` key `waa_chat_v1`.
- `messages` is the display transcript for the current browser session.
- `history` is the API-facing normalized transcript sent back to `/chat`.
- Saved conversations in the database are secondary; inline browser history currently takes precedence over database conversation history.

## Behaviors that must be preserved

- `loadFromStorage()` must fall back to a clean empty state when stored JSON is missing or invalid.
- `sendMessage()` must snapshot the existing `apiHistory` before streaming so one in-flight turn does not rewrite its own request context.
- SSE `usage` events are the iteration boundary for tool-call history chunking.
- A tool iteration must be flushed into `apiHistory` before the next iteration starts.
- `navigate` must be deferred until stream completion and surfaced through `pendingNavUrl`.
- Stream errors must produce an assistant-visible error state instead of silently dropping the turn.
- `clearMessages()` must reset memory and remove `waa_chat_v1`.

## Migration cautions

- Moving session ownership from browser to server too early risks breaking unsaved chat continuity across refreshes.
- Replacing SSE without preserving `usage` boundaries risks corrupting tool-result ordering in `apiHistory`.
- Loading saved conversations without reconstructing API history still resets future turns to a fresh context; this is known current behavior and should be changed intentionally, not accidentally.
- Parallel test runs can deadlock the shared `wp-env` test database, so runtime verification should stay serial unless the test environment is isolated.
