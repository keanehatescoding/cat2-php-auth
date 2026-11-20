#!/usr/bin/env bash
set -euo pipefail

# Example deployment helper for CAT2 (host install).
# Usage:
#   TARGET_HOST=keane@20.174.162.13 TARGET_PATH=/var/www/html/cat2 ./scripts/deploy.sh
#
# Notes:
# - Requires SSH access (and sudo on target if files are owned by www-data/root).
# - Excludes git metadata and local env artifacts from the deployment archive.
# - Review and adapt for your environment before use.

TARGET_HOST="${TARGET_HOST:-}"
TARGET_PATH="${TARGET_PATH:-/var/www/html/cat2}"
SSH_OPTS="${SSH_OPTS:--o BatchMode=yes -o ConnectTimeout=15}"

if [[ -z "$TARGET_HOST" ]]; then
  echo "TARGET_HOST is required (e.g. user@host)" >&2
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

php -l config.php >/dev/null
if [[ -f tests/smoke.php ]]; then
  php tests/smoke.php >/dev/null
fi

TMP_TAR="$(mktemp /tmp/cat2-deploy.XXXXXX.tar.gz)"
trap 'rm -f "$TMP_TAR"' EXIT

# Deploy core app files (adjust list as needed).
# Exclude local env files that may contain secrets.
TAR_EXCLUDES=(--exclude='serverless/.env' --exclude='serverless/**/.env' --exclude='./serverless/.env')
TAR_FILES=(
  config.php dashboard.php feedback.php health.php index.php
  layout_footer.php layout_header.php login.php logout.php
  serverless_demo.php signup.php style.css password-meter.js
  README.md SECURITY.md sql serverless tests scripts
)
if [[ -d docs ]]; then
  TAR_FILES+=(docs)
fi
tar czf "$TMP_TAR" "${TAR_EXCLUDES[@]}" "${TAR_FILES[@]}"

LOCK_NAME="cat2-deploy-$(printf '%s' "$TARGET_PATH" | sha256sum | cut -c1-16).lock"
REMOTE_TMP="/tmp/cat2-deploy.$RANDOM.$RANDOM.$$.tar.gz"

# shellcheck disable=SC2086
scp $SSH_OPTS "$TMP_TAR" "$TARGET_HOST:$REMOTE_TMP"
# shellcheck disable=SC2086
ssh $SSH_OPTS "$TARGET_HOST" "TARGET_PATH=$(printf '%q' "$TARGET_PATH") REMOTE_TMP=$(printf '%q' "$REMOTE_TMP") LOCK_NAME=$(printf '%q' "$LOCK_NAME") bash -s" <<'REMOTE'
set -euo pipefail
LOCK_PATH="/var/lock/${LOCK_NAME}"
cleanup() { rm -f "$REMOTE_TMP"; }
trap cleanup EXIT
sudo mkdir -p /var/lock
(
  flock 9
  sudo tar xzf "$REMOTE_TMP" -C "$TARGET_PATH"
  sudo chown -R www-data:www-data "$TARGET_PATH"
  for f in config.php login.php health.php; do
    sudo php -l "$TARGET_PATH/$f" >/dev/null
  done
  echo "DEPLOYED_TO=$TARGET_PATH"
) 9>"$LOCK_PATH"
REMOTE
