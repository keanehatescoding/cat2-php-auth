#!/usr/bin/env bash
set -euo pipefail
MSG="${1:-CAT2 alert}"
ENV_FILE="/etc/cat2-alert.env"
LOG="/var/log/cat2-alert.log"
HOSTNAME_STR="$(hostname -f 2>/dev/null || hostname)"
TS="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

[ -f "$ENV_FILE" ] || exit 0
# shellcheck disable=SC1090
. "$ENV_FILE"

PAYLOAD_TEXT="[CAT2 Alert] ${MSG} (host=${HOSTNAME_STR}, time=${TS})"

if [ -n "${SLACK_WEBHOOK_URL:-}" ]; then
  curl -sS -m 10 -X POST -H 'Content-type: application/json'     --data "$(printf '{"text":"%s"}' "$(printf '%s' "$PAYLOAD_TEXT" | sed 's/\\/\\\\/g; s/"/\\"/g')")"     "$SLACK_WEBHOOK_URL" >>"$LOG" 2>&1 || true
  echo "" >>"$LOG" 2>&1 || true
fi

logger -t cat2-alert "$MSG" 2>/dev/null || true
