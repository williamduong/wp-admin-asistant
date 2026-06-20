#!/usr/bin/env bash
set -euo pipefail

REPO="williamduong/wp-admin-asistant"

usage() {
  cat <<'EOF'
Create a GitHub issue for the wp-admin-asistant repo.

Usage:
  scripts/create-issue.sh --title "Issue title"
  scripts/create-issue.sh --title "Issue title" --body "Issue body"
  scripts/create-issue.sh --title "Issue title" --body-file /path/to/body.md
  scripts/create-issue.sh --title "Issue title" --label bug --label docs
  scripts/create-issue.sh --web

Options:
  --title TEXT       Issue title.
  --body TEXT        Inline issue body.
  --body-file PATH   Read issue body from a file.
  --label NAME       Add a label. Repeat to add multiple labels.
  --assignee NAME    Assign to a GitHub user.
  --web              Open the interactive web issue creation flow.
  -h, --help         Show this help text.

Examples:
  scripts/create-issue.sh --title "Rate limit too aggressive for long posts"
  scripts/create-issue.sh --title "Docs: explain tool customization" --body-file /tmp/issue.md
EOF
}

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

ensure_auth() {
  if gh auth status >/dev/null 2>&1; then
    return 0
  fi

  cat >&2 <<'EOF'
GitHub CLI is installed but not authenticated.

Run one of these first:
  gh auth login --hostname github.com --git-protocol ssh --web

Or, if you already have a token:
  export GITHUB_TOKEN=YOUR_TOKEN
  gh auth login --with-token < <(printf '%s' "$GITHUB_TOKEN")
EOF
  exit 1
}

TITLE=""
BODY=""
BODY_FILE=""
ASSIGNEE=""
WEB=0
LABELS=()

while [[ $# -gt 0 ]]; do
  case "$1" in
    --title)
      TITLE="${2:-}"
      shift 2
      ;;
    --body)
      BODY="${2:-}"
      shift 2
      ;;
    --body-file)
      BODY_FILE="${2:-}"
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
    --web)
      WEB=1
      shift
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

require_cmd gh
ensure_auth

ARGS=(issue create --repo "$REPO")

if [[ $WEB -eq 1 ]]; then
  exec gh "${ARGS[@]}" --web
fi

if [[ -z "$TITLE" ]]; then
  echo "Missing required argument: --title" >&2
  usage >&2
  exit 1
fi

ARGS+=(--title "$TITLE")

if [[ -n "$BODY" && -n "$BODY_FILE" ]]; then
  echo "Use either --body or --body-file, not both." >&2
  exit 1
fi

if [[ -n "$BODY_FILE" ]]; then
  if [[ ! -f "$BODY_FILE" ]]; then
    echo "Body file not found: $BODY_FILE" >&2
    exit 1
  fi
  ARGS+=(--body-file "$BODY_FILE")
elif [[ -n "$BODY" ]]; then
  ARGS+=(--body "$BODY")
fi

for label in "${LABELS[@]}"; do
  ARGS+=(--label "$label")
done

if [[ -n "$ASSIGNEE" ]]; then
  ARGS+=(--assignee "$ASSIGNEE")
fi

exec gh "${ARGS[@]}"
