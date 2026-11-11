#!/usr/bin/env bash
# Run every suite against a throwaway WordPress site.
#   VR_TEST_ROOT         site folder (built by setup-site.sh when missing; default tests/.site)
#   VR_SIGNING_KEY_FILE  Ed25519 release key, for the signed self-update tests
#   VR_OLD_PLUGIN        a 0.1.3 copy for the upgrade test (default: taken from git history)
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
src="$(cd "$here/.." && pwd)"
export VR_TEST_ROOT="${VR_TEST_ROOT:-$here/.site}"
export VR_PLUGIN_SRC="$src"
export VR_SCRATCH="${VR_SCRATCH:-$(mktemp -d)}"
: "${VR_SIGNING_KEY_FILE:?Set VR_SIGNING_KEY_FILE}"
site="$VR_TEST_ROOT"

[ -f "$site/wp/wp-settings.php" ] || "$here/setup-site.sh" "$site" || exit 1
cp "$here/site/vr-test-stubs.php" "$site/wp/wp-content/mu-plugins/"
cp "$here/site/router.php" "$here/site/install.php" "$here/site/activate.php" "$site/"

if [ -z "${VR_OLD_PLUGIN:-}" ]; then
  mkdir -p "$VR_SCRATCH/old013"
  git -C "$src" archive d77594c | tar -x -C "$VR_SCRATCH/old013" --one-top-level=visual-realm
  export VR_OLD_PLUGIN="$VR_SCRATCH/old013/visual-realm"
fi

stop_server() {
  # Kill the master and its worker processes; a surviving worker would keep serving the old configuration.
  pkill -f "php -S localhost:8890" 2>/dev/null; rm -f "$site/server.pid"
  for _ in $(seq 1 30); do curl -s -o /dev/null http://localhost:8890/ || return 0; sleep 0.2; done
}
start_server() {
  (cd "$site" && PHP_CLI_SERVER_WORKERS=4 nohup php -S localhost:8890 -t wp router.php > "$site/server.log" 2>&1 & echo $! > "$site/server.pid")
  for _ in $(seq 1 30); do curl -s -o /dev/null http://localhost:8890/ && return; sleep 0.3; done
}
deploy() {
  rm -rf "$site/wp/wp-content/plugins/visual-realm"
  mkdir -p "$site/wp/wp-content/plugins/visual-realm"
  (cd "$src" && tar --exclude=.git --exclude=tests --exclude=dist --exclude=.github -cf - .) | tar -x -C "$site/wp/wp-content/plugins/visual-realm"
  php "$site/activate.php" >/dev/null
  # The suites call from this machine: let localhost through the address allowlist and name the admin account.
  php -r '$_SERVER["HTTP_HOST"]="localhost:8890";$_SERVER["REQUEST_URI"]="/";require $argv[1]."/wp/wp-load.php";
    $s=(array)get_option("vr_settings",array());$s["extra_ips"]="127.0.0.1\n::1";$s["restrict_ips"]=1;$s["allowed_users"]=array(1);$s["disabled_tools"]=array();$s["extra_post_types"]="";
    update_option("vr_settings",$s,false);update_option("vr_access_rev",3,false);delete_option("vr_blocked_log");
    global $wpdb;$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"%vr_rl_%\" OR option_name LIKE \"%vr_blk_%\"");' "$site"
}

: > "$site/wp/wp-content/debug.log"
fail=0
run() { echo "=== $1 ==="; shift; python3 "$@" 2>&1 | tee "$VR_SCRATCH/last.log" | grep -E "^FAIL|passed$"; [ "${PIPESTATUS[0]}" -eq 0 ] || fail=1; }

stop_server; deploy
unset SIMULATE_SITEGROUND; start_server
run "e2e (normal host)" "$here/e2e_test.py"
stop_server

deploy
export SIMULATE_SITEGROUND=1; start_server
run "e2e (SiteGround simulation)" "$here/e2e_test.py"
stop_server; unset SIMULATE_SITEGROUND

deploy; start_server
run "security" "$here/security_test.py"
stop_server

deploy; start_server
run "upgrade and self-update" "$here/upgrade_test.py"
stop_server

warn="$(grep "plugins/visual-realm/" "$site/wp/wp-content/debug.log" 2>/dev/null | grep -iE "warning|notice|fatal|deprecated" | tail -20)"
if [ -n "$warn" ]; then
  echo "$warn"; echo "PHP warnings from the plugin (above)"; fail=1
fi
[ "$fail" -eq 0 ] && echo "ALL SUITES PASSED" || echo "SOME SUITES FAILED"
exit "$fail"
