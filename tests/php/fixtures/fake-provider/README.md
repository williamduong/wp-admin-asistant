# Fake Provider Fixtures

This folder is the Wave 0 bootstrap inventory for deterministic Runtime V1 provider behavior.

Current fixture set:

- `runtime-v1.json`: happy-path reply, one tool-use turn, and one tool-result follow-up turn

Planned scenarios to add as Epic 1 expands:

- permission denied safety cases
- malformed or missing tool arguments
- unexpected tool name from provider
- empty assistant text before a tool call
- repeated tool-use loops near iteration limit
- SSE-facing error and fallback summaries after provider exceptions
