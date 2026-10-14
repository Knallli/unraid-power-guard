<?php
declare(strict_types=1);

namespace PowerGuard;

/**
 * Turns plan rows into concrete writes, applies them with baseline + journal,
 * and restores the baseline on revert.
 *
 * Only paths matching WRITABLE can ever be written. Values are validated
 * against what the kernel offers (bracket lists, available governors/EPP)
 * or must be plain numbers / identifiers.
 */
final class Engine
{
    private const WRITABLE = [
        '#^/proc/sys/(vm|fs|kernel|net/core|net/ipv4)/[a-z0-9_]+(/[a-z0-9_]+)?$#',
        '#^/sys/kernel/mm/transparent_hugepage/(enabled|defrag)$#',
        '#^/sys/devices/system/cpu/intel_pstate/(no_turbo|hwp_dynamic_boost)$#',
        '#^/sys/devices/system/cpu/cpufreq/boost$#',
        '#^/sys/devices/system/cpu/cpu\d+/cpufreq/(scaling_governor|energy_performance_preference)$#',
        '#^/sys/module/zfs/parameters/zfs_arc_max$#',
        '#^/sys/module/snd_hda_intel/parameters/power_save$#',
    ];

    /** The kernel rejects 0 for *_bytes; the paired *_ratio carries the original instead. */
    private const SKIP_ZERO = [
        '/proc/sys/vm/dirty_bytes', '/proc/sys/vm/dirty_ratio',
        '/proc/sys/vm/dirty_background_bytes', '/proc/sys/vm/dirty_background_ratio',
    ];

    /** Lower runs first: the governor must be set before EPP accepts a value. */
    private const ORDER = ['cpu.governor' => 0, 'cpu.epp' => 1];

    /** Target kinds this release can write; others are shown as "folgt in Phase 4". */
    public static function supports(?array $target): bool
    {
        return $target !== null && (isset($target['sysctl']) || isset($target['file'])
            || isset($target['cpufreq']) || isset($target['turbo']));
    }

    /**
     * Pending changes: managed-capable tweaks whose current value differs from the target.
     *
     * @param list<string>|null $ids restrict to these tweak ids
     * @return list<array>
     */
    public static function changes(Context $ctx, array $cfg, ?array $ids = null): array
    {
        $profile = in_array($cfg['profile'] ?? '', Registry::PROFILES, true) ? $cfg['profile'] : 'efficiency';
        $res = [];
        foreach (Registry::load($ctx) as $t) {
            $id = $t->id();
            if ($ids !== null && !in_array($id, $ids, true)) {
                continue;
            }
            $row = $t->evaluate($profile, $ctx, $cfg['tweaks'][$id] ?? []);
            if ($row['status'] !== Tweak::STATUS_RECOMMENDED) {
                continue;
            }
            $target = $t->spec('target');
            $change = [
                'id' => $id, 'title' => $row['title'], 'category' => $row['category'],
                'risk' => $row['risk'], 'timing' => $row['timing'], 'format' => $row['format'],
                'from' => $row['current'], 'to' => $row['target'], 'custom' => $row['mode'] === 'custom',
                'supported' => self::supports($target), 'writes' => [], 'pair' => null, 'error' => null,
            ];
            if ($change['supported']) {
                try {
                    $change['writes'] = self::writesFor($target, $row['target'], $ctx);
                    $change['pair'] = isset($target['pair']) ? self::sysctlPath($target['pair']) : null;
                } catch (\InvalidArgumentException $e) {
                    $change['error'] = $e->getMessage();
                }
            }
            $res[] = $change;
        }
        usort($res, static fn($a, $b) => (self::ORDER[$a['id']] ?? 5) <=> (self::ORDER[$b['id']] ?? 5));
        return $res;
    }

    /**
     * @param list<string>|null $ids null = everything pending (or, at boot, the managed set)
     * @return array{applied:list<string>, failed:list<array>, skipped:list<array>}
     */
    public static function apply(?array $ids, bool $boot = false): array
    {
        $result = ['applied' => [], 'failed' => [], 'skipped' => []];
        $lock = State::lock();
        try {
            $cfg = State::config();
            if ($boot) {
                if ($cfg['mode'] !== 'active' || $cfg['managed'] === []) {
                    return $result;
                }
                $ids = $cfg['managed'];
            }
            $ctx = Context::build();
            $baseline = State::baseline();

            foreach (self::changes($ctx, $cfg, $ids) as $c) {
                if (!$c['supported'] || $c['error'] !== null || $c['writes'] === []) {
                    $result['skipped'][] = ['id' => $c['id'], 'reason' => $c['error'] ?? 'In dieser Version nicht anwendbar'];
                    continue;
                }
                $missing = array_filter($c['writes'], static fn($w) => !Sys::exists($w['path']));
                if ($missing) {
                    // e.g. ZFS not loaded yet during early boot; the "started" event retries.
                    $result['skipped'][] = ['id' => $c['id'], 'reason' => 'Pfad fehlt: ' . reset($missing)['path']];
                    continue;
                }

                if (!isset($baseline[$c['id']])) {
                    $values = [];
                    foreach (array_merge(array_column($c['writes'], 'path'), $c['pair'] ? [$c['pair']] : []) as $p) {
                        $values[$p] = Sys::readValue($p);
                    }
                    $baseline[$c['id']] = ['t' => time(), 'values' => $values];
                    State::saveBaseline($baseline); // before writing, so a crash can still be reverted
                }

                $err = self::write($c['writes']);
                if ($err === null) {
                    $result['applied'][] = $c['id'];
                    State::journal(['action' => 'apply', 'id' => $c['id'], 'from' => $c['from'], 'to' => $c['to'], 'boot' => $boot, 'ok' => true]);
                } else {
                    self::restore($baseline[$c['id']]['values']);
                    $result['failed'][] = ['id' => $c['id'], 'reason' => $err];
                    State::journal(['action' => 'apply', 'id' => $c['id'], 'from' => $c['from'], 'to' => $c['to'], 'boot' => $boot, 'ok' => false, 'msg' => $err]);
                }
            }

            if (!$boot) {
                $managed = array_merge($cfg['managed'], $result['applied']);
                State::setManaged($managed, 'active');
            }
            if ($result['applied'] || $result['failed']) {
                Sys::log(sprintf('%s: %d angewendet, %d fehlgeschlagen', $boot ? 'boot' : 'apply', count($result['applied']), count($result['failed'])));
            }
        } finally {
            State::unlock($lock);
        }
        return $result;
    }

    /**
     * @param list<string>|null $ids null = everything in the baseline (and leave managed mode)
     * @return array{reverted:list<string>, failed:list<array>}
     */
    public static function revert(?array $ids): array
    {
        $result = ['reverted' => [], 'failed' => []];
        $lock = State::lock();
        try {
            $baseline = State::baseline();
            $cfg = State::config();
            foreach (array_reverse(array_keys($baseline)) as $id) {
                if ($ids !== null && !in_array($id, $ids, true)) {
                    continue;
                }
                $err = self::restore($baseline[$id]['values']);
                if ($err === null) {
                    unset($baseline[$id]);
                    $result['reverted'][] = $id;
                    State::journal(['action' => 'revert', 'id' => $id, 'ok' => true]);
                } else {
                    $result['failed'][] = ['id' => $id, 'reason' => $err];
                    State::journal(['action' => 'revert', 'id' => $id, 'ok' => false, 'msg' => $err]);
                }
            }
            State::saveBaseline($baseline);
            // Nothing left to manage means there is nothing to re-apply at boot: back to observe.
            $remaining = $ids === null ? [] : array_values(array_diff($cfg['managed'], $ids));
            State::setManaged($remaining, $remaining === [] ? 'observe' : null);
            if ($result['reverted'] || $result['failed']) {
                Sys::log(sprintf('revert: %d zurückgesetzt, %d fehlgeschlagen', count($result['reverted']), count($result['failed'])));
            }
        } finally {
            State::unlock($lock);
        }
        return $result;
    }

    /** @return list<array{path:string,value:string}> */
    public static function writesFor(array $target, mixed $value, Context $ctx): array
    {
        $v = is_bool($value) ? (string)(int)$value : (string)$value;
        if (isset($target['sysctl'])) {
            $writes = [['path' => self::sysctlPath($target['sysctl']), 'value' => $v]];
        } elseif (isset($target['file'])) {
            $writes = [['path' => $target['file'], 'value' => $v]];
        } elseif (isset($target['cpufreq'])) {
            $writes = [];
            foreach (Sys::cpuList(Sys::read('/sys/devices/system/cpu/online')) as $c) {
                $writes[] = ['path' => "/sys/devices/system/cpu/cpu$c/cpufreq/{$target['cpufreq']}", 'value' => $v];
            }
        } elseif (($target['turbo'] ?? null) === 'intel_pstate/no_turbo') {
            $writes = [['path' => '/sys/devices/system/cpu/intel_pstate/no_turbo', 'value' => $v === '1' ? '0' : '1']];
        } elseif (($target['turbo'] ?? null) === 'cpufreq/boost') {
            $writes = [['path' => '/sys/devices/system/cpu/cpufreq/boost', 'value' => $v]];
        } else {
            throw new \InvalidArgumentException('Unbekanntes Ziel');
        }
        foreach ($writes as $w) {
            self::validate($w['path'], $w['value']);
        }
        return $writes;
    }

    public static function sysctlPath(string $key): string
    {
        return '/proc/sys/' . str_replace('.', '/', $key);
    }

    public static function validate(string $path, string $value): void
    {
        $ok = false;
        foreach (self::WRITABLE as $re) {
            if (preg_match($re, $path)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new \InvalidArgumentException("Pfad nicht freigegeben: $path");
        }
        $raw = Sys::read($path);
        if ($raw !== null && str_contains($raw, '[')) {
            [, $opts] = Sys::bracketed($raw);
            if (!in_array($value, $opts, true)) {
                throw new \InvalidArgumentException("„{$value}“ ist nicht erlaubt (" . implode(', ', $opts) . ')');
            }
            return;
        }
        $avail = null;
        if (str_ends_with($path, '/scaling_governor')) {
            $avail = Sys::read(dirname($path) . '/scaling_available_governors');
        } elseif (str_ends_with($path, '/energy_performance_preference')) {
            $avail = Sys::read(dirname($path) . '/energy_performance_available_preferences');
        } elseif ($path === '/proc/sys/net/ipv4/tcp_congestion_control') {
            $avail = Sys::read('/proc/sys/net/ipv4/tcp_available_congestion_control');
        }
        if ($avail !== null) {
            if (!in_array($value, preg_split('/\s+/', $avail) ?: [], true)) {
                throw new \InvalidArgumentException("„{$value}“ ist nicht verfügbar ($avail)");
            }
            return;
        }
        if ($raw !== null && preg_match('/^-?\d+$/', $raw)) {
            if (!preg_match('/^\d{1,19}$/', $value)) {
                throw new \InvalidArgumentException("„{$value}“ ist keine Zahl");
            }
            return;
        }
        if (!preg_match('/^[a-z0-9_]{1,32}$/i', $value)) {
            throw new \InvalidArgumentException("Ungültiger Wert „{$value}“");
        }
    }

    /** Write and read back. Returns an error message or null. */
    private static function write(array $writes): ?string
    {
        foreach ($writes as $w) {
            if (!Sys::write($w['path'], $w['value'])) {
                return "Schreiben fehlgeschlagen: {$w['path']}";
            }
            $back = Sys::readValue($w['path']);
            if ($back !== $w['value']) {
                return "Kernel hat {$w['path']} nicht übernommen (gelesen: " . ($back ?? '–') . ')';
            }
        }
        return null;
    }

    /** @param array<string,?string> $values */
    private static function restore(array $values): ?string
    {
        $err = null;
        foreach ($values as $path => $v) {
            if ($v === null || ($v === '0' && in_array($path, self::SKIP_ZERO, true))) {
                continue;
            }
            if (Sys::readValue($path) === $v) {
                continue;
            }
            if (!Sys::write($path, $v) || Sys::readValue($path) !== $v) {
                $err ??= "Wiederherstellen fehlgeschlagen: $path";
            }
        }
        return $err;
    }
}
