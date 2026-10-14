#!/bin/bash
# Apply/revert test against a fake sysfs/procfs copied (read-only) from this host.
#   tests/engine.sh <plugin-dir>
# The live system is never written: POWER_GUARD_ROOT redirects every access.
set -u
DIR=$(cd "${1:-/usr/local/emhttp/plugins/power-guard}" && pwd)
WORK=$(mktemp -d)
FAKE=$WORK/root
export POWER_GUARD_ROOT=$FAKE POWER_GUARD_CONFIG_DIR=$WORK/cfg POWER_GUARD_STATE_DIR=$WORK/state
PG="php $DIR/bin/power-guard"
fail=0
ok()  { printf 'ok    %s\n' "$1"; }
bad() { printf 'FAIL  %s\n' "$1"; fail=1; }
val() { tr -d '\n' < "$FAKE$1"; }

# --- build the fake tree --------------------------------------------------------
copy() { for f in "$@"; do [ -f "$f" ] && [ -r "$f" ] || continue; mkdir -p "$FAKE$(dirname "$f")"; cat "$f" > "$FAKE$f" 2>/dev/null; done; }
copy /etc/unraid-version /proc/cpuinfo /proc/meminfo /proc/cmdline /proc/spl/kstat/zfs/arcstats
copy /proc/sys/kernel/osrelease /proc/sys/vm/* /proc/sys/fs/inotify/*
copy /proc/sys/net/core/{default_qdisc,rmem_max,wmem_max}
copy /proc/sys/net/ipv4/{tcp_congestion_control,tcp_available_congestion_control,tcp_mtu_probing}
copy /sys/devices/system/cpu/online /sys/devices/system/cpu/intel_pstate/* /sys/devices/system/cpu/cpufreq/boost
copy /sys/devices/system/cpu/cpu*/cpufreq/* /sys/devices/system/cpu/cpu0/cpuidle/state*/{name,latency,disable}
copy /sys/devices/system/cpu/cpuidle/current_driver /sys/devices/cpu_core/cpus /sys/devices/cpu_atom/cpus
copy /sys/kernel/mm/transparent_hugepage/{enabled,defrag} /sys/module/zfs/parameters/{zfs_arc_max,zfs_arc_min}
mkdir -p "$FAKE/sys/module/zfs"
# Make sure there is something to change regardless of the host's current state.
echo 265000 > "$FAKE/proc/sys/vm/max_map_count"
echo 'always defer defer+madvise [madvise] never' > "$FAKE/sys/kernel/mm/transparent_hugepage/defrag"
echo '[always] madvise never' > "$FAKE/sys/kernel/mm/transparent_hugepage/enabled"
echo 20 > "$FAKE/proc/sys/vm/dirty_ratio"; echo 0 > "$FAKE/proc/sys/vm/dirty_bytes"
echo 0 > "$FAKE/sys/module/zfs/parameters/zfs_arc_max"
for c in "$FAKE"/sys/devices/system/cpu/cpu*/cpufreq; do echo balance_performance > "$c/energy_performance_preference"; done

# --- tests ----------------------------------------------------------------------
pre=$($PG preview)
for id in vm.max_map_count mm.thp vm.dirty_bytes zfs.arc_max cpu.epp; do
  grep -q "\"id\":\"$id\"" <<<"$pre" && ok "preview lists $id" || bad "preview misses $id"
done
grep -q '"id":"nic.ring.eth0"[^}]*"supported":false' <<<"$pre" && ok "ring buffer marked unsupported" || ok "ring buffer not pending (skip)"

out=$($PG apply 2>&1); [ $? -eq 2 ] && ok "apply without ids refused" || bad "apply without ids: $out"

$PG apply --ids=vm.max_map_count,mm.thp,vm.dirty_bytes,zfs.arc_max,cpu.epp --quiet; rc=$?
[ $rc -eq 0 ] && ok "apply exit 0" || bad "apply exit $rc: $($PG journal)"
[ "$(val /proc/sys/vm/max_map_count)" = 1048576 ] && ok "max_map_count written" || bad "max_map_count=$(val /proc/sys/vm/max_map_count)"
grep -q '\[madvise\]' "$FAKE/sys/kernel/mm/transparent_hugepage/enabled" && ok "thp madvise" || bad "thp: $(val /sys/kernel/mm/transparent_hugepage/enabled)"
[ "$(val /proc/sys/vm/dirty_bytes)" = 1073741824 ] && [ "$(val /proc/sys/vm/dirty_ratio)" = 0 ] && ok "dirty_bytes set, ratio cleared" || bad "dirty: $(val /proc/sys/vm/dirty_bytes)/$(val /proc/sys/vm/dirty_ratio)"
[ "$(val /sys/module/zfs/parameters/zfs_arc_max)" != 0 ] && ok "arc_max written" || bad "arc_max unchanged"
epp=$(cat "$FAKE"/sys/devices/system/cpu/cpu*/cpufreq/energy_performance_preference | sort -u)
[ "$epp" = balance_power ] && ok "epp on all cpus" || bad "epp: $epp"
grep -q '"mode": "active"' "$WORK/cfg/config.json" && grep -q 'vm.max_map_count' "$WORK/cfg/config.json" && ok "config: active + managed" || bad "config: $(cat "$WORK/cfg/config.json")"
grep -q '"/proc/sys/vm/dirty_ratio": "20"' "$WORK/state/baseline.json" && ok "baseline keeps ratio" || bad "baseline: $(cat "$WORK/state/baseline.json")"

$PG plan --table | grep -E '^(optimal|custom) +vm.max_map_count' >/dev/null && ok "plan shows optimal after apply" || bad "plan after apply"

# Invalid custom value must never reach sysfs.
php -r 'parse_str($argv[1],$p); $_SERVER["REQUEST_METHOD"]="POST"; $_POST=$p; include "'"$DIR"'/include/api.php";' \
  'action=config&patch={"tweak":{"id":"mm.thp_defrag","mode":"custom","value":"bogus"}}' >/dev/null
out=$($PG apply --ids=mm.thp_defrag)
grep -q 'nicht erlaubt' <<<"$out" && ok "invalid enum value rejected" || bad "invalid enum: $out"
grep -q '\[madvise\]' "$FAKE/sys/kernel/mm/transparent_hugepage/defrag" && ok "defrag untouched" || bad "defrag changed"

# Simulated reboot: kernel defaults come back, boot apply restores managed set only.
echo 265000 > "$FAKE/proc/sys/vm/max_map_count"
echo '[always] madvise never' > "$FAKE/sys/kernel/mm/transparent_hugepage/enabled"
echo 128 > "$FAKE/proc/sys/fs/inotify/max_user_instances"
rm -f "$WORK/state/baseline.json"
$PG apply --boot --quiet
[ "$(val /proc/sys/vm/max_map_count)" = 1048576 ] && ok "boot re-applies managed" || bad "boot: max_map_count=$(val /proc/sys/vm/max_map_count)"
[ "$(val /proc/sys/fs/inotify/max_user_instances)" = 128 ] && ok "boot leaves unmanaged alone" || bad "boot touched unmanaged"

# Single revert, then revert all.
$PG revert --ids=mm.thp --quiet
grep -q '\[always\]' "$FAKE/sys/kernel/mm/transparent_hugepage/enabled" && ok "revert single" || bad "revert thp: $(val /sys/kernel/mm/transparent_hugepage/enabled)"
grep -q '"mm.thp"' "$WORK/cfg/config.json" && bad "thp still managed" || ok "thp no longer managed"
$PG revert --quiet
[ "$(val /proc/sys/vm/max_map_count)" = 265000 ] && ok "revert all" || bad "revert all: $(val /proc/sys/vm/max_map_count)"
grep -q '"mode": "observe"' "$WORK/cfg/config.json" && ok "mode back to observe" || bad "mode after revert"

# Boot in observe mode must be a no-op.
echo 265000 > "$FAKE/proc/sys/vm/max_map_count"
$PG apply --boot --quiet
[ "$(val /proc/sys/vm/max_map_count)" = 265000 ] && ok "observe: boot no-op" || bad "observe boot wrote"

n=$($PG journal | grep -o '"action"' | wc -l)
[ "$n" -ge 8 ] && ok "journal has $n entries" || bad "journal entries: $n"

# Path whitelist: a tweak target outside the whitelist is rejected by the engine.
out=$(cd "$DIR" && php -r 'require "lib/bootstrap.php"; try { PowerGuard\Engine::validate("/sys/class/net/eth0/mtu","9000"); echo "accepted"; } catch (InvalidArgumentException $e) { echo "rejected"; }')
[ "$out" = rejected ] && ok "path whitelist" || bad "whitelist: $out"

rm -rf "$WORK"
exit $fail
