#!/usr/bin/env bash
set -euo pipefail

BACKUP_FILE="${1:-}"
LOG="/var/log/cat2-db-backup.log"
ENV_FILE="/etc/cat2-backup-aws.env"
ALERT_SCRIPT="/etc/cat2-alert.sh"

alert() {
  local msg="$1"
  if [ -x "$ALERT_SCRIPT" ]; then
    "$ALERT_SCRIPT" "$msg" >/dev/null 2>&1 || true
  fi
}

on_exit() {
  local rc=$?
  if [ "$rc" -ne 0 ]; then
    alert "CAT2 offsite backup hook failed (rc=${rc}, file=${BACKUP_FILE:-unknown})"
  fi
}
trap on_exit EXIT

[ -n "$BACKUP_FILE" ] && [ -f "$BACKUP_FILE" ] || exit 0
[ -f "$ENV_FILE" ] || { echo "offsite: env file missing" >>"$LOG"; exit 1; }

set -a
# shellcheck disable=SC1090
. "$ENV_FILE"
set +a

: "${CAT2_BACKUP_S3_URI:?CAT2_BACKUP_S3_URI required}"

aws s3 cp "$BACKUP_FILE" "${CAT2_BACKUP_S3_URI}/$(basename "$BACKUP_FILE")" --only-show-errors >>"$LOG" 2>&1

# Optional remote retention: keep last 30 days in S3 prefix
CUTOFF=$(date -u -d '30 days ago' +%Y-%m-%d 2>/dev/null || true)
if [ -n "$CUTOFF" ]; then
  aws s3 ls "${CAT2_BACKUP_S3_URI}/" 2>/dev/null | while read -r d t size name; do
    [ -n "${name:-}" ] || continue
    if [[ "$name" == cat2-*.sql.gz ]] && [[ "$d" < "$CUTOFF" ]]; then
      aws s3 rm "${CAT2_BACKUP_S3_URI}/${name}" --only-show-errors >>"$LOG" 2>&1 || true
    fi
  done
fi

echo "offsite_ok $(basename "$BACKUP_FILE")" >>"$LOG"
