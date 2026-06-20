#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CREATE_ISSUE_SH="$SCRIPT_DIR/create-issue.sh"

usage() {
  cat <<'EOF'
Create a standardized follow-up issue for wp-admin-asistant.

Usage:
  scripts/follow-up-issue.sh \
    --title "Short issue title" \
    --current "What happens now" \
    --expected "What should happen" \
    --impact "Why it matters"

Optional:
  --area "Likely affected area"
  --next-step "Suggested next implementation step"
  --acceptance "Acceptance criteria line"
  --label NAME
  --assignee NAME

Example:
  scripts/follow-up-issue.sh \
    --title "Long blog generation gets cut off" \
    --current "Rich post generation can stop mid-article when content is long and image fetch is involved." \
    --expected "Long posts should complete reliably or fail with a resumable workflow." \
    --impact "Content workflows are unreliable for real blog drafting." \
    --area "Provider max token cap, runtime orchestration, Pexels image flow" \
    --next-step "Make provider output caps configurable and split rich-post drafting from image fetch." \
    --acceptance "A 1200-1500 word draft completes without truncation on the recommended model." \
    --label bug \
    --label follow-up
EOF
}

require_file() {
  if [[ ! -x "$1" ]]; then
    echo "Required helper not found or not executable: $1" >&2
    exit 1
  fi
}

TITLE=""
CURRENT=""
EXPECTED=""
IMPACT=""
AREA=""
NEXT_STEP=""
ASSIGNEE=""
LABELS=()
ACCEPTANCE=()

while [[ $# -gt 0 ]]; do
  case "$1" in
    --title)
      TITLE="${2:-}"
      shift 2
      ;;
    --current)
      CURRENT="${2:-}"
      shift 2
      ;;
    --expected)
      EXPECTED="${2:-}"
      shift 2
      ;;
    --impact)
      IMPACT="${2:-}"
      shift 2
      ;;
    --area)
      AREA="${2:-}"
      shift 2
      ;;
    --next-step)
      NEXT_STEP="${2:-}"
      shift 2
      ;;
    --acceptance)
      ACCEPTANCE+=("${2:-}")
      shift 2
      ;;
    --label)
      LABELS+=("${2:-}")
      shift 2
      ;;
    --assignee)
      ASSIGNEE="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

for required in TITLE CURRENT EXPECTED IMPACT; do
  if [[ -z "${!required}" ]]; then
    echo "Missing required argument for $required" >&2
    usage >&2
    exit 1
  fi
done

require_file "$CREATE_ISSUE_SH"

BODY_FILE="$(mktemp)"
trap 'rm -f "$BODY_FILE"' EXIT

{
  printf '## Summary\n\n'
  printf '%s\n\n' "$TITLE"

  printf '## Current Behavior\n\n'
  printf '%s\n\n' "$CURRENT"

  printf '## Expected Behavior\n\n'
  printf '%s\n\n' "$EXPECTED"

  printf '## Impact\n\n'
  printf '%s\n\n' "$IMPACT"

  if [[ -n "$AREA" ]]; then
    printf '## Likely Affected Area\n\n'
    printf '%s\n\n' "$AREA"
  fi

  if [[ -n "$NEXT_STEP" ]]; then
    printf '## Proposed Next Step\n\n'
    printf '%s\n\n' "$NEXT_STEP"
  fi

  if [[ ${#ACCEPTANCE[@]} -gt 0 ]]; then
    printf '## Acceptance Checks\n\n'
    for item in "${ACCEPTANCE[@]}"; do
      printf -- '- %s\n' "$item"
    done
    printf '\n'
  fi

  printf '## Source\n\n'
  printf -- '- Raised during Codex work on `wp-admin-assistant`\n'
  printf -- '- Created via `scripts/follow-up-issue.sh`\n'
} > "$BODY_FILE"

ARGS=(--title "$TITLE" --body-file "$BODY_FILE")

for label in "${LABELS[@]}"; do
  ARGS+=(--label "$label")
done

if [[ -n "$ASSIGNEE" ]]; then
  ARGS+=(--assignee "$ASSIGNEE")
fi

exec "$CREATE_ISSUE_SH" "${ARGS[@]}"
