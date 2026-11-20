#!/usr/bin/env bash
set -euo pipefail
URL="https://bidii.online/cat2/health.php"
TOKEN_FILE="/etc/cat2-health-token"
ALERT_HOOK="/etc/cat2-alert.sh"
LOG="/var/log/cat2-health-monitor.log"
TOKEN=""
if [ -f "$TOKEN_FILE" ]; then TOKEN=$(tr -d '\n' < "$TOKEN_FILE"); fi
if [ -n "$TOKEN" ]; then
  CODE=$(curl -sS -o /tmp/cat2-health.out -w '%{http_code}' -H "Authorization: Bearer ${TOKEN}" "$URL" || echo 000)
else
  CODE=$(curl -sS -o /tmp/cat2-health.out -w '%{http_code}' "$URL" || echo 000)
fi
TS=$(date -u +%Y-%m-%dT%H:%M:%SZ)
if [ "$CODE" = "200" ]; then
  echo "${TS} ok ${CODE}" >>"$LOG"
  exit 0
fi
echo "${TS} fail ${CODE}" >>"$LOG"
if [ -x "$ALERT_HOOK" ]; then "$ALERT_HOOK" "health check failed code=${CODE}" || true; fi
exit 1
