<?php
declare(strict_types=1);

namespace PowerGuard;

/**
 * Finds other tools that tune the same knobs. Read-only.
 */
final class Conflicts
{
    /** Lines in scripts/cron/go that touch knobs Power Guard manages. */
    private const TUNING_RE = '/(sysctl\s+(-w\s+)?[a-z]|\/proc\/sys\/|scaling_governor|energy_performance_preference|no_turbo|cpufreq\/boost|set_turbo|rc\.tweaks|hwp_dynamic_boost|zfs_arc_max|transparent_hugepage|power\/control|link_power_management_policy|pcie_aspm|ethtool\s+-[GKA])/i';

    private const PLUGINS = [
        'autotweak' => ['AutoTweak', 'conflict', 'Setzt Governor, EPP, ASPM, USB-/PCI-PM und sysctls – überschneidet sich vollständig.'],
        'tips.and.tweaks' => ['Tips and Tweaks', 'conflict', 'Setzt Governor, Turbo-Zeitplan, Dirty-Limits und NIC-Optionen.'],
        'ca.turbo' => ['Turbo Write', 'info', 'Schaltet nur den Array-Schreibmodus (md_write_method), kein CPU-Turbo. Kein Konflikt.'],
    ];

    private static ?array $env = null;

    /** Facts tweaks use to step aside (e.g. cache_dirs owns vfs_cache_pressure). */
    public static function environment(): array
    {
        if (self::$env !== null) {
            return self::$env;
        }
        $installed = [];
        foreach (array_keys(self::PLUGINS) as $p) {
            $installed[$p] = Sys::exists("/boot/config/plugins/$p.plg");
        }
        $cacheDirs = Sys::run(['pgrep', '-f', 'cache_dirs']) !== null;
        return self::$env = [
            'plugins' => $installed,
            'cacheDirs' => $cacheDirs || Sys::exists('/boot/config/plugins/dlandon.cache.dirs.plg') && Sys::exists('/var/run/cache_dirs.pid'),
        ];
    }

    public static function scan(): array
    {
        $res = [];
        $env = self::environment();

        foreach (self::PLUGINS as $id => [$name, $sev, $detail]) {
            $cfg = Sys::glob("/boot/config/plugins/$id/*.cfg");
            if ($env['plugins'][$id]) {
                $res[] = [
                    'id' => "plugin:$id", 'severity' => $sev, 'title' => "$name ist installiert",
                    'detail' => $detail, 'source' => "/boot/config/plugins/$id.plg",
                    'action' => $sev === 'conflict' ? "$name über den Migrationsassistenten ablösen und danach deinstallieren." : null,
                    'settings' => $cfg ? self::parseCfg($cfg[0]) : null,
                ];
            } elseif ($cfg && $sev === 'conflict') {
                $res[] = [
                    'id' => "leftover:$id", 'severity' => 'info', 'title' => "Reste von $name",
                    'detail' => 'Plugin ist entfernt, die Konfiguration liegt noch auf dem Flash. Sie wird für die Migration gelesen.',
                    'source' => $cfg[0], 'action' => null, 'settings' => self::parseCfg($cfg[0]),
                ];
            }
        }

        // Cron entries (flash .cron files are merged into /etc/cron.d/root by Unraid).
        foreach (Sys::glob('/boot/config/plugins/*/*.cron') as $f) {
            $hits = self::matchLines($f);
            if ($hits === []) {
                continue;
            }
            $missing = [];
            foreach ($hits as $line) {
                if (preg_match('#(/usr/local/emhttp/plugins/[^\s]+)#', $line, $m) && !Sys::exists($m[1])) {
                    $missing[] = $m[1];
                }
            }
            $res[] = [
                'id' => 'cron:' . basename(dirname($f)) . '/' . basename($f), 'severity' => 'conflict',
                'title' => 'Cron-Job ändert CPU/Kernel-Einstellungen',
                'detail' => $missing
                    ? 'Der Job ruft ein nicht mehr vorhandenes Skript auf (' . implode(', ', array_unique($missing)) . ') und ist damit verwaist.'
                    : 'Der Job überschreibt periodisch Werte, die Power Guard verwaltet.',
                'source' => $f, 'lines' => $hits,
                'action' => "Datei $f entfernen und danach „update_cron“ ausführen (oder neu starten). Den Zeitplan übernimmt der Profil-Zeitplan von Power Guard.",
            ];
        }

        foreach (Sys::glob('/boot/config/plugins/user.scripts/scripts/*/script') as $f) {
            $hits = self::matchLines($f);
            if ($hits === []) {
                continue;
            }
            $name = basename(dirname($f));
            $res[] = [
                'id' => 'userscript:' . $name, 'severity' => 'warning',
                'title' => "User Script „{$name}“ setzt Kernel-Werte",
                'detail' => 'Wenn Power Guard denselben Wert verwaltet, gewinnt, wer zuletzt läuft.',
                'source' => $f, 'lines' => $hits,
                'action' => 'Prüfen, ob Power Guard den Wert übernimmt, dann den Zeitplan des Skripts auf „Schedule Disabled“ stellen.',
            ];
        }

        if (($hits = self::matchLines('/boot/config/go')) !== []) {
            $res[] = [
                'id' => 'go', 'severity' => 'warning', 'title' => '/boot/config/go enthält Tuning-Zeilen',
                'detail' => 'Diese Zeilen laufen bei jedem Boot vor Power Guard.', 'source' => '/boot/config/go',
                'lines' => $hits, 'action' => 'Zeilen entfernen, die Power Guard übernimmt.',
            ];
        }

        foreach (Sys::glob('/etc/sysctl.d/*.conf') as $f) {
            $res[] = [
                'id' => 'sysctl.d:' . basename($f), 'severity' => 'info', 'title' => 'sysctl-Datei ' . basename($f),
                'detail' => 'Wird respektiert und nicht verändert.', 'source' => $f,
                'lines' => array_values(array_filter(array_map('trim', explode("\n", Sys::readRaw($f) ?? '')), static fn($l) => $l !== '' && $l[0] !== '#')),
                'action' => null,
            ];
        }

        if ($env['cacheDirs']) {
            $res[] = [
                'id' => 'cache_dirs', 'severity' => 'info', 'title' => 'cache_dirs ist aktiv',
                'detail' => 'vm.vfs_cache_pressure wird von cache_dirs gesetzt und von Power Guard nicht verwaltet.',
                'source' => null, 'action' => null,
            ];
        }

        $rank = ['conflict' => 0, 'warning' => 1, 'info' => 2];
        usort($res, static fn($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);
        return $res;
    }

    /** @return list<string> */
    private static function matchLines(string $file): array
    {
        $raw = Sys::readRaw($file);
        if ($raw === null) {
            return [];
        }
        $hits = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $t = trim($line);
            // drop_caches/compact_memory are one-shot actions, not settings.
            if ($t !== '' && $t[0] !== '#' && preg_match(self::TUNING_RE, $t) && !preg_match('/drop_caches|compact_memory/', $t)) {
                $hits[] = mb_strimwidth($t, 0, 200, '…');
            }
        }
        return $hits;
    }

    private static function parseCfg(string $file): array
    {
        $res = [];
        foreach (preg_split('/\r?\n/', Sys::readRaw($file) ?? '') ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=\s*"?([^"]*)"?\s*$/', $line, $m)) {
                $res[$m[1]] = $m[2];
            }
        }
        return $res;
    }
}
