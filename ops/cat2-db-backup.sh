#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="/var/backups/cat2"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${BACKUP_DIR}/cat2-${TS}.sql.gz"
TMP="${OUT}.tmp"
LOG="/var/log/cat2-db-backup.log"
OFFSITE_HOOK="/etc/cat2-backup-offsite.sh"
ALERT_SCRIPT="/etc/cat2-alert.sh"
SUCCESS=0

alert() {
  local msg="$1"
  if [ -x "$ALERT_SCRIPT" ]; then
    "$ALERT_SCRIPT" "$msg" >/dev/null 2>&1 || true
  fi
}

on_exit() {
  local rc=$?
  if [ "$SUCCESS" -ne 1 ]; then
    alert "CAT2 DB backup failed (rc=${rc}, file=${OUT})"
    echo "backup_failed rc=${rc} file=${OUT}" >>"$LOG" 2>&1 || true
  fi
}
trap on_exit EXIT

umask 077
mkdir -p "$BACKUP_DIR"

mariadb-dump --defaults-extra-file=/etc/cat2-backup.cnf --single-transaction --routines --triggers --databases CAT2 | gzip -c > "$TMP"
gzip -t "$TMP"
mariadb -e 'DROP DATABASE IF EXISTS CAT2_restore_verify; CREATE DATABASE CAT2_restore_verify;'
zcat "$TMP" | sed 's/`CAT2`/`CAT2_restore_verify`/g' | mariadb
mariadb -e 'USE CAT2_restore_verify; SHOW TABLES;' >/dev/null
mariadb -e 'DROP DATABASE IF EXISTS CAT2_restore_verify;'
mv "$TMP" "$OUT"
find "$BACKUP_DIR" -type f -name 'cat2-*.sql.gz' -mtime +14 -delete

OFFSITE_STATUS="not_configured"
if [ -x "$OFFSITE_HOOK" ]; then
  if "$OFFSITE_HOOK" "$OUT" >>"$LOG" 2>&1; then
    OFFSITE_STATUS="ok"
  else
    OFFSITE_STATUS="failed"
    echo "offsite hook failed" >>"$LOG"
    alert "CAT2 DB backup offsite upload failed for $(basename "$OUT")"
  fi
fi

if [ "$OFFSITE_STATUS" = "failed" ]; then
  echo "backup_ok_local_offsite_failed ${OUT}" >>"$LOG"
else
  echo "backup_ok ${OUT} offsite=${OFFSITE_STATUS}" >>"$LOG"
fi
SUCCESS=1
