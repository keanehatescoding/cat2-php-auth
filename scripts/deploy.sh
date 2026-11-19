#!/usr/bin/env bash
set -euo pipefail

# Example deployment helper for CAT2 (host install).
# Usage:
#   TARGET_HOST=keane@20.174.162.13 TARGET_PATH=/var/www/html/cat2 ./scripts/deploy.sh
#
# Notes:
# - Requires SSH access (and sudo on target if files are owned by www-data/root).
# - Syncs tracked app files; excludes git metadata and local env artifacts.
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
tar czf "$TMP_TAR" \
  config.php dashboard.php feedback.php health.php index.php \
  layout_footer.php layout_header.php login.php logout.php \
  serverless_demo.php signup.php style.css password-meter.js \
  README.md SECURITY.md sql serverless tests scripts docs 2>/dev/null || \
tar czf "$TMP_TAR" \
  config.php dashboard.php feedback.php health.php index.php \
  layout_footer.php layout_header.php login.php logout.php \
  serverless_demo.php signup.php style.css password-meter.js \
  README.md SECURITY.md sql serverless tests scripts

scp $SSH_OPTS "$TMP_TAR" "$TARGET_HOST:/tmp/cat2-deploy.tar.gz"
ssh $SSH_OPTS "$TARGET_HOST" "sudo tar xzf /tmp/cat2-deploy.tar.gz -C '$TARGET_PATH' \
  && sudo chown -R www-data:www-data '$TARGET_PATH' \
  && rm -f /tmp/cat2-deploy.tar.gz \
  && sudo php -l '$TARGET_PATH/config.php' '$TARGET_PATH/login.php' '$TARGET_PATH/health.php' >/dev/null \
  && echo DEPLOYED_TO='$TARGET_PATH'"
