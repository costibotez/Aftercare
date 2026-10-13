#!/usr/bin/env bash
#
# Installs a plugin zip into a WordPress checkout and runs the compatibility
# checks: activation, every admin screen, settings save, the RUM endpoint, the
# ledger CSV export, a seeded regression with its emails, then deactivation
# and uninstall. Fails on any PHP warning, notice, deprecation or database
# error that mentions the plugin.
#
# Usage: bin/compat-test.sh <wordpress-dir> <plugin-zip>
#
# Database settings come from DB_NAME, DB_USER, DB_PASSWORD and DB_HOST. The
# database must exist and be empty.

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
WP_DIR="$( cd "${1:?WordPress directory required}" && pwd )"
ZIP="$( cd "$( dirname "${2:?plugin zip required}" )" && pwd )/$( basename "$2" )"
PORT="${PORT:-8899}"
export AFTERCARE_TEST_URL="http://localhost:$PORT"
CHECKS="$ROOT/bin/compat/checks.php"
LOG="$WP_DIR/wp-content/debug.log"
WORK="$( mktemp -d )"
SERVER_PID=""

cleanup() {
	[ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
	rm -rf "$WORK"
}
trap cleanup EXIT

fail() {
	echo "FAIL $*" >&2
	exit 1
}

cat > "$WP_DIR/wp-config.php" <<PHP
<?php
define( 'DB_NAME', '${DB_NAME:-wordpress}' );
define( 'DB_USER', '${DB_USER:-root}' );
define( 'DB_PASSWORD', '${DB_PASSWORD:-}' );
define( 'DB_HOST', '${DB_HOST:-127.0.0.1}' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '$LOG' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP

# Swallow outgoing mail so requests made over HTTP do not depend on sendmail.
mkdir -p "$WP_DIR/wp-content/mu-plugins"
cat > "$WP_DIR/wp-content/mu-plugins/compat-no-mail.php" <<'PHP'
<?php
add_filter( 'pre_wp_mail', '__return_true' );
PHP

rm -rf "$WP_DIR/wp-content/plugins/aftercare"
unzip -q "$ZIP" -d "$WP_DIR/wp-content/plugins"
rm -f "$LOG"

php "$CHECKS" "$WP_DIR" install
php "$CHECKS" "$WP_DIR" activate

php -S "localhost:$PORT" -t "$WP_DIR" > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $( seq 1 20 ); do
	curl -s -o /dev/null "$AFTERCARE_TEST_URL/wp-login.php" && break
	sleep 0.5
done

JAR="$WORK/cookies.txt"
BASE="$AFTERCARE_TEST_URL"
curl -s -c "$JAR" -b "$JAR" -o /dev/null "$BASE/wp-login.php"
curl -s -c "$JAR" -b "$JAR" -o /dev/null -d 'log=admin&pwd=password&wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php"

# Run the flow first so the screens below render with real data.
php "$CHECKS" "$WP_DIR" flow

page() {
	local path="$1" expect="$2" code
	code="$( curl -s -b "$JAR" -o "$WORK/page.html" -w '%{http_code}' "$BASE/wp-admin/$path" )"
	[ "$code" = "$expect" ] || fail "$path returned $code, expected $expect"
	echo "PASS $path returns $code"
}

page 'index.php' 200
page 'admin.php?page=aftercare' 200
page 'admin.php?page=aftercare-ledger' 200
page 'admin.php?page=aftercare-incidents' 200
page 'admin.php?page=aftercare-incidents&incident=1' 200
page 'admin.php?page=aftercare-settings' 200
page 'site-health.php' 200
page 'site-health.php?tab=debug' 200
page 'plugins.php' 200

# The free build ships no reports screen.
if [ ! -f "$WP_DIR/wp-content/plugins/aftercare/src/Reports/Builder.php" ]; then
	page 'admin.php?page=aftercare-reports' 403
fi

curl -s -b "$JAR" -o "$WORK/front.html" "$BASE/"
grep -q 'wp-admin-bar-aftercare' "$WORK/front.html" || fail 'admin bar item missing on the front end'
echo 'PASS admin bar item on the front end'

# Settings save through the real form and its nonce.
curl -s -b "$JAR" -o "$WORK/settings.html" "$BASE/wp-admin/admin.php?page=aftercare-settings"
NONCE="$( grep -m1 -o 'name="_wpnonce" value="[^"]*"' "$WORK/settings.html" | sed 's/.*value="//;s/"$//' )"
[ -n "$NONCE" ] || fail 'settings nonce not found'
REDIRECT="$( curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' \
	-d "action=aftercare_save_settings&_wpnonce=$NONCE&rum_enabled=1&rum_sample_rate=100&alert_email=admin@example.com" \
	"$BASE/wp-admin/admin-post.php" )"
[[ "$REDIRECT" == *updated=1* ]] || fail "settings save redirected to '$REDIRECT'"
echo 'PASS settings save'

curl -s -o "$WORK/front.html" "$BASE/"
grep -q 'id="aftercare-rum-js"' "$WORK/front.html" || fail 'RUM beacon not enqueued'
echo 'PASS RUM beacon enqueued'

rum() {
	curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d "$1" "$BASE/?rest_route=/aftercare/v1/rum"
}
[ "$( rum "{\"metric\":\"LCP\",\"value\":1800,\"url\":\"$BASE/\"}" )" = 204 ] || fail 'RUM endpoint rejected a valid reading'
[ "$( rum '{"metric":"XYZ","value":1,"url":"x"}' )" = 400 ] || fail 'RUM endpoint accepted an invalid metric'
echo 'PASS RUM endpoint validates input'

curl -s -b "$JAR" -o "$WORK/ledger.html" "$BASE/wp-admin/admin.php?page=aftercare-ledger"
EXPORT="$( grep -m1 -o 'admin-post.php?action=aftercare_ledger_export[^"]*' "$WORK/ledger.html" | sed 's/&#038;/\&/g; s/&amp;/\&/g' )"
[ -n "$EXPORT" ] || fail 'ledger export link not found'
curl -s -b "$JAR" -o "$WORK/ledger.csv" "$BASE/wp-admin/$EXPORT"
head -1 "$WORK/ledger.csv" | grep -q '^occurred_at,event_type,summary' || fail 'ledger CSV export'
echo 'PASS ledger CSV export'

kill "$SERVER_PID" 2>/dev/null || true
SERVER_PID=""

php "$CHECKS" "$WP_DIR" teardown

# Any PHP message from the plugin or any database error fails the run. Core
# messages about unreachable update servers are not the plugin's concern.
if [ -f "$LOG" ] && grep -E 'plugins/aftercare/|WordPress database error' "$LOG" > "$WORK/problems.log"; then
	cat "$WORK/problems.log" >&2
	fail 'debug.log has messages from the plugin'
fi
if [ -f "$LOG" ] && [ -s "$LOG" ]; then
	echo 'Other debug.log entries (not from the plugin):'
	cat "$LOG"
fi
echo 'PASS no plugin messages in debug.log'
echo "All compatibility checks passed on WordPress $( sed -nE "s/^\\\$wp_version = '([^']+)';/\1/p" "$WP_DIR/wp-includes/version.php" )"
