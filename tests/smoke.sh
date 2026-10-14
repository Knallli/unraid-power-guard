#!/bin/bash
# Read-only smoke test against a real Unraid host.
#   tests/smoke.sh <plugin-dir>     (e.g. /tmp/pg-test)
# Uses a throw-away config dir; nothing on /boot or in sysfs is changed.
set -u
DIR=${1:-/usr/local/emhttp/plugins/power-guard}
CFG=$(mktemp -d)
export POWER_GUARD_CONFIG_DIR=$CFG POWER_GUARD_STATE_DIR=$CFG/state
cd "$DIR" || exit 1
fail=0
ok()  { printf 'ok    %s\n' "$1"; }
bad() { printf 'FAIL  %s\n' "$1"; fail=1; }

api() {
  php -r 'parse_str($argv[1], $p); $_SERVER["REQUEST_METHOD"] = "POST"; $_POST = $p; include "include/api.php";' "$1" 2>&1
}
expect() { # name, needle, output
  if grep -qF -- "$2" <<<"$3"; then ok "$1"; else bad "$1: '$2' not in: ${3:0:200}"; fi
}

for f in $(find . -name '*.php') bin/power-guard; do
  php -l "$f" >/dev/null 2>&1 || bad "lint $f"
done
ok "php -l"

expect "cli plan"        '"rows"'        "$(php bin/power-guard plan 2>&1)"
expect "cli apply needs ids" '--ids=' "$(php bin/power-guard apply 2>&1)"
expect "api plan"        '"score"'       "$(api 'action=plan')"
expect "api telemetry"   '"rapl"'        "$(api 'action=telemetry')"
expect "api unknown"     'unknown action' "$(api 'action=bogus')"
expect "api bad profile" 'unknown profile' "$(api 'action=plan&profile=turbo')"
expect "api profile"     '"balanced"'    "$(api 'action=config&patch={"profile":"balanced"}')"
expect "api active needs managed" 'Erst Empfehlungen anwenden' "$(api 'action=config&patch={"mode":"active"}')"
expect "api custom"      '2097152'       "$(api 'action=config&patch={"tweak":{"id":"vm.max_map_count","mode":"custom","value":"2097152"}}')"
expect "api bad id"      'Ungültige'     "$(api 'action=config&patch={"tweak":{"id":"x;rm -rf","mode":"off"}}')"
expect "api bad value"   'Ungültiger Wert' "$(api 'action=config&patch={"tweak":{"id":"vm.swappiness","mode":"custom","value":"$(id)"}}')"
expect "plan uses custom" '-> 2097152'   "$(php bin/power-guard plan --table 2>&1 | grep max_map_count)"
expect "config on disk"  '"profile": "balanced"' "$(cat "$CFG/config.json")"

# Protection invariant (read-only check): every protected USB device must be "on".
viol=$(php bin/power-guard detect | php -r '$h=json_decode(stream_get_contents(STDIN),true); foreach($h["usb"]["devices"] as $d) if($d["violation"]) echo $d["id"]," ";')
[ -z "$viol" ] && ok "usb protection" || printf 'warn  usb protection: not "on": %s\n' "$viol"

rm -rf "$CFG"
exit $fail
