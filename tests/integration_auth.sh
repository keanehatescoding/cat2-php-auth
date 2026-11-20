#!/usr/bin/env bash
set -euo pipefail

# Lightweight DB-backed integration test for CAT2 auth/security flows.
# Runs a temporary PHP built-in server against a temporary MariaDB/MySQL database.
# Intended for trusted test environments (VPS/CI/local) with DB admin access.
#
# Env vars:
#   TEST_DB_HOST         (default: localhost)
#   TEST_DB_NAME         (default: CAT2_integration_test)
#   TEST_DB_USER         (default: root)       - app/PHP connection user
#   TEST_DB_PASS         (default: empty)      - app/PHP connection password
#   MYSQL_ADMIN_USER     (default: TEST_DB_USER)
#   MYSQL_ADMIN_PASS     (default: TEST_DB_PASS)
#   MYSQL_USE_SUDO       (default: 0; set to 1 to prefix mariadb/mysql admin commands with sudo)
#   APP_DIR              (default: repo root)
#   PORT                 (default: 18081)
#   BASE_URL             (default: http://127.0.0.1:${PORT})
#
# WARNING: drops and recreates TEST_DB_NAME.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="${APP_DIR:-$ROOT_DIR}"
TEST_DB_HOST="${TEST_DB_HOST:-localhost}"
TEST_DB_NAME="${TEST_DB_NAME:-CAT2_integration_test}"
TEST_DB_USER="${TEST_DB_USER:-root}"
TEST_DB_PASS="${TEST_DB_PASS:-}"
MYSQL_ADMIN_USER="${MYSQL_ADMIN_USER:-$TEST_DB_USER}"
MYSQL_ADMIN_PASS="${MYSQL_ADMIN_PASS:-$TEST_DB_PASS}"
MYSQL_USE_SUDO="${MYSQL_USE_SUDO:-0}"
PORT="${PORT:-18081}"
BASE_URL="${BASE_URL:-http://127.0.0.1:${PORT}}"
SERVER_LOG="${SERVER_LOG:-/tmp/cat2-integration-server.$$.log}"
COOKIE_JAR="${COOKIE_JAR:-/tmp/cat2-integration-cookies.$$.txt}"
HDR_FILE="${HDR_FILE:-/tmp/cat2-integration-headers.$$.txt}"
BODY_FILE="${BODY_FILE:-/tmp/cat2-integration-body.$$.txt}"

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "Missing required command: $1" >&2; exit 1; }; }
need_cmd php
need_cmd curl

if command -v mariadb >/dev/null 2>&1; then
  MYSQL_BIN="mariadb"
elif command -v mysql >/dev/null 2>&1; then
  MYSQL_BIN="mysql"
else
  echo "Missing required command: mariadb or mysql" >&2
  exit 1
fi

mysql_admin=("$MYSQL_BIN" -h "$TEST_DB_HOST" -u "$MYSQL_ADMIN_USER")
if [[ "$MYSQL_USE_SUDO" == "1" ]]; then
  mysql_admin=(sudo "$MYSQL_BIN" -h "$TEST_DB_HOST" -u "$MYSQL_ADMIN_USER")
fi

export MYSQL_PWD="$MYSQL_ADMIN_PASS"
PHP_MYSQL_PWD="$TEST_DB_PASS"

cleanup() {
  set +e
  if [[ -n "${SERVER_PID:-}" ]] && kill -0 "$SERVER_PID" >/dev/null 2>&1; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
    wait "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  MYSQL_PWD="$MYSQL_ADMIN_PASS" "${mysql_admin[@]}" -e "DROP DATABASE IF EXISTS \`$TEST_DB_NAME\`;" >/dev/null 2>&1 || true
  rm -f "$COOKIE_JAR" "$HDR_FILE" "$BODY_FILE"
}
trap cleanup EXIT

echo "[setup] recreating test database $TEST_DB_NAME"
MYSQL_PWD="$MYSQL_ADMIN_PASS" "${mysql_admin[@]}" -e "DROP DATABASE IF EXISTS \`$TEST_DB_NAME\`; CREATE DATABASE \`$TEST_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# schema.sql contains `USE CAT2;` for production/container init; retarget to test DB.
sed "s/^USE CAT2;/USE \`$TEST_DB_NAME\`;/" "$APP_DIR/sql/schema.sql" | MYSQL_PWD="$MYSQL_ADMIN_PASS" "${mysql_admin[@]}"

rm -f "$COOKIE_JAR" "$SERVER_LOG" "$HDR_FILE" "$BODY_FILE"
echo "[setup] starting PHP server at $BASE_URL"
(
  cd "$APP_DIR"
  CAT2_DB_HOST="$TEST_DB_HOST" \
  CAT2_DB_NAME="$TEST_DB_NAME" \
  CAT2_DB_USER="$TEST_DB_USER" \
  CAT2_DB_PASS="$PHP_MYSQL_PWD" \
  php -S "127.0.0.1:${PORT}" >"$SERVER_LOG" 2>&1
) &
SERVER_PID=$!

# wait for server
for i in $(seq 1 40); do
  if curl -fsS "$BASE_URL/index.php" >/dev/null 2>&1; then break; fi
  sleep 0.25
  if [[ "$i" -eq 40 ]]; then echo "Server failed to start" >&2; tail -n 80 "$SERVER_LOG" >&2 || true; exit 1; fi
done

pass() { echo "[ok] $*"; }
fail() { echo "[fail] $*" >&2; echo "--- body ---" >&2; sed -n '1,120p' "$BODY_FILE" >&2 2>/dev/null || true; echo "--- server log ---" >&2; tail -n 80 "$SERVER_LOG" >&2 || true; exit 1; }

get_csrf() {
  local page="$1"
  curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/$page" \
    | grep -o 'name="csrf_token" value="[^"]*"' \
    | head -1 | sed -E 's/.*value="([^"]*)"/\1/'
}

get_login_challenge_answer() {
  local page="${1:-login.php}"
  local html a b
  html=$(curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/$page")
  a=$(printf '%s' "$html" | grep -o 'Security check: what is [0-9][0-9]* + [0-9][0-9]*' | head -1 | sed -E 's/.*what is ([0-9]+) \+ ([0-9]+).*/\1/')
  b=$(printf '%s' "$html" | grep -o 'Security check: what is [0-9][0-9]* + [0-9][0-9]*' | head -1 | sed -E 's/.*what is ([0-9]+) \+ ([0-9]+).*/\2/')
  if [[ -n "$a" && -n "$b" ]]; then
    echo $((a + b))
  else
    echo ""
  fi
}

# 1) Protected page redirects to login with safe next
code=$(curl -sS -o /dev/null -w '%{http_code}' -D "$HDR_FILE" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/dashboard.php")
loc=$(grep -i '^Location:' "$HDR_FILE" | tr -d '\r' | tail -1)
[[ "$code" == "302" && "$loc" == *"login.php?next="* ]] && pass "dashboard redirects unauthenticated user to login with next" || fail "dashboard redirect unexpected: code=$code loc=$loc"

# 2) Signup with invalid CSRF rejected
csrf=$(get_csrf signup.php)
code=$(curl -sS -o "$BODY_FILE" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST "$BASE_URL/signup.php" \
  --data-urlencode "csrf_token=invalid" \
  --data-urlencode "username=testuser1" \
  --data-urlencode "email=test1@example.com" \
  --data-urlencode "password=Testpass1" \
  --data-urlencode "website=")
grep -q "Invalid form submission" "$BODY_FILE" && pass "signup rejects invalid CSRF" || fail "signup invalid CSRF not rejected (code=$code)"

# 3) Valid signup succeeds
csrf=$(get_csrf signup.php)
code=$(curl -sS -o "$BODY_FILE" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST "$BASE_URL/signup.php" \
  --data-urlencode "csrf_token=$csrf" \
  --data-urlencode "username=testuser1" \
  --data-urlencode "email=test1@example.com" \
  --data-urlencode "password=Testpass1" \
  --data-urlencode "website=")
grep -q "Account created" "$BODY_FILE" && pass "valid signup creates account" || fail "valid signup did not show success (code=$code)"

# 4) Login with invalid CSRF rejected
csrf=$(get_csrf login.php)
code=$(curl -sS -o "$BODY_FILE" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST "$BASE_URL/login.php" \
  --data-urlencode "csrf_token=invalid" \
  --data-urlencode "login=testuser1" \
  --data-urlencode "password=Testpass1" \
  --data-urlencode "website=")
grep -q "Invalid form submission" "$BODY_FILE" && pass "login rejects invalid CSRF" || fail "login invalid CSRF not rejected (code=$code)"

# 5) Failed login shows generic error
csrf=$(get_csrf login.php)
code=$(curl -sS -o "$BODY_FILE" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST "$BASE_URL/login.php" \
  --data-urlencode "csrf_token=$csrf" \
  --data-urlencode "login=testuser1" \
  --data-urlencode "password=wrongpass" \
  --data-urlencode "website=")
grep -q "Invalid username/email or password" "$BODY_FILE" && pass "failed login shows generic error" || fail "failed login error message unexpected (code=$code)"

# 6) Repeated failures trigger step-up challenge
challenge_seen=0
for i in 1 2 3 4 5 6; do
  csrf=$(get_csrf login.php)
  curl -sS -o "$BODY_FILE" -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST "$BASE_URL/login.php" \
    --data-urlencode "csrf_token=$csrf" \
    --data-urlencode "login=testuser1" \
    --data-urlencode "password=wrongpass" \
    --data-urlencode "website=" >/dev/null || true
  if grep -q "Security check" "$BODY_FILE"; then challenge_seen=1; break; fi
done
[[ "$challenge_seen" -eq 1 ]] && pass "step-up challenge appears after repeated failures" || fail "step-up challenge not observed"

# 7) Wrong challenge answer rejected and counted
csrf=$(get_csrf login.php)
curl -sS -o "$BODY_FILE" -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST "$BASE_URL/login.php" \
  --data-urlencode "csrf_token=$csrf" \
  --data-urlencode "login=testuser1" \
  --data-urlencode "password=Testpass1" \
  --data-urlencode "challenge_answer=999999" \
  --data-urlencode "website=" >/dev/null
(grep -q "solve the security check" "$BODY_FILE" || grep -q "Too many failed login attempts" "$BODY_FILE") \
  && pass "wrong challenge answer is rejected/counted" || fail "wrong challenge answer not handled as expected"

# 8) Correct challenge answer allows login path to proceed (fresh user/session to avoid lockout coupling)
csrf=$(get_csrf signup.php)
curl -sS -o "$BODY_FILE" -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST "$BASE_URL/signup.php" \
  --data-urlencode "csrf_token=$csrf" \
  --data-urlencode "username=challuser" \
  --data-urlencode "email=challuser@example.com" \
  --data-urlencode "password=Testpass3" \
  --data-urlencode "website=" >/dev/null
rm -f "$COOKIE_JAR"
# trigger challenge for this user
for i in 1 2 3 4 5 6; do
  csrf=$(get_csrf login.php)
  curl -sS -o "$BODY_FILE" -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST "$BASE_URL/login.php" \
    --data-urlencode "csrf_token=$csrf" \
    --data-urlencode "login=challuser" \
    --data-urlencode "password=wrongpass" \
    --data-urlencode "website=" >/dev/null || true
  grep -q "Security check" "$BODY_FILE" && break
done
ans=$(get_login_challenge_answer login.php)
if [[ -z "$ans" ]]; then
  fail "could not parse login challenge answer"
fi
csrf=$(get_csrf login.php)
code=$(curl -sS -o /dev/null -w '%{http_code}' -D "$HDR_FILE" -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST "$BASE_URL/login.php" \
  --data-urlencode "csrf_token=$csrf" \
  --data-urlencode "login=challuser" \
  --data-urlencode "password=Testpass3" \
  --data-urlencode "challenge_answer=$ans" \
  --data-urlencode "website=")
loc=$(grep -i '^Location:' "$HDR_FILE" | tr -d '\r' | tail -1)
[[ "$code" == "302" && "$loc" == *"dashboard.php"* ]] && pass "correct challenge answer allows successful login" || fail "challenge-correct login unexpected: code=$code loc=$loc"

# 9) Safe next redirect honored after login (challenge may still be required due to prior failures)
rm -f "$COOKIE_JAR"
ans=$(get_login_challenge_answer "login.php?next=/feedback.php")
csrf=$(get_csrf "login.php?next=/feedback.php")
post_args=(
  -X POST "$BASE_URL/login.php"
  --data-urlencode "csrf_token=$csrf"
  --data-urlencode "login=challuser"
  --data-urlencode "password=Testpass3"
  --data-urlencode "next=/feedback.php"
  --data-urlencode "website="
)
if [[ -n "$ans" ]]; then
  post_args+=(--data-urlencode "challenge_answer=$ans")
fi
code=$(curl -sS -o /dev/null -w '%{http_code}' -D "$HDR_FILE" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${post_args[@]}")
loc=$(grep -i '^Location:' "$HDR_FILE" | tr -d '\r' | tail -1)
[[ "$code" == "302" && "$loc" == *"feedback.php"* ]] && pass "safe next redirect honored after login" || fail "safe next redirect unexpected: code=$code loc=$loc"

# 10) Unsafe next redirect rejected (falls back)
rm -f "$COOKIE_JAR"
ans=$(get_login_challenge_answer "login.php?next=//evil.example")
csrf=$(get_csrf "login.php?next=//evil.example")
post_args=(
  -X POST "$BASE_URL/login.php"
  --data-urlencode "csrf_token=$csrf"
  --data-urlencode "login=challuser"
  --data-urlencode "password=Testpass3"
  --data-urlencode "next=//evil.example"
  --data-urlencode "website="
)
if [[ -n "$ans" ]]; then
  post_args+=(--data-urlencode "challenge_answer=$ans")
fi
code=$(curl -sS -o /dev/null -w '%{http_code}' -D "$HDR_FILE" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${post_args[@]}")
loc=$(grep -i '^Location:' "$HDR_FILE" | tr -d '\r' | tail -1)
[[ "$code" == "302" && "$loc" == *"dashboard.php"* ]] && pass "unsafe next redirect falls back to dashboard" || fail "unsafe next redirect not rejected: code=$code loc=$loc"

# 11) Health endpoint returns JSON status
code=$(curl -sS -o "$BODY_FILE" -w '%{http_code}' "$BASE_URL/health.php")
grep -q '"status"' "$BODY_FILE" && [[ "$code" == "200" || "$code" == "503" || "$code" == "403" ]] \
  && pass "health endpoint returns JSON status (code=$code)" || fail "health endpoint unexpected response: code=$code"

echo ""
echo "All integration checks passed."
