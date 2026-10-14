<?php
declare(strict_types=1);

namespace PowerGuard;

return static function (Context $ctx): array {
    $MiB = 1048576;
    $usb = $ctx->hasUsbStorage();
    $mem = $ctx->memBytes();
    $hasSwap = ($ctx->hw['memory']['swapKiB'] ?? 0) > 0 || $ctx->hw['memory']['zram'] !== [];
    $thp = static fn(string $f) => Sys::bracketed(Sys::read("/sys/kernel/mm/transparent_hugepage/$f"));
    $sysctl = static fn(string $key, string $title, array $more) => $more + [
        'id' => $key, 'category' => 'memory', 'title' => $title,
        'target' => ['sysctl' => $key],
        'applicable' => static fn() => $ctx->sysctl($key) !== null ?: 'Nicht vorhanden',
        'current' => static fn() => $ctx->sysctlInt($key),
    ];

    $dirtyWhy = $usb
        ? 'Das Array hängt an USB. Mit dem Prozent-Default (20 % RAM) sammeln sich viele GiB Schreibcache, die beim Flush minutenlang blockieren. Kleine absolute Limits halten den Datenstrom gleichmäßig.'
        : 'Bei viel RAM erlaubt der Prozent-Default sehr große Schreibcaches. Absolute Limits verkürzen Flush-Stalls.';
    $dirty = static fn(string $p, int $eff, int $perf) => ($p === 'performance' && !$usb) ? $perf : $eff;

    return [
        $sysctl('vm.dirty_bytes', 'Dirty-Limit (hart)', [
            'format' => 'bytes', 'why' => $dirtyWhy, 'risk' => 'low',
            'source' => 'https://docs.kernel.org/admin-guide/sysctl/vm.html#dirty-bytes',
            'current' => static function () use ($ctx) {
                $b = $ctx->sysctlInt('vm.dirty_bytes');
                return $b === 0 ? $ctx->sysctlInt('vm.dirty_ratio') . ' %' : $b;
            },
            'recommend' => static fn(string $p) => $dirty($p, 1024 * $MiB, 2048 * $MiB),
        ]),
        $sysctl('vm.dirty_background_bytes', 'Dirty-Limit (Hintergrund)', [
            'format' => 'bytes', 'why' => 'Ab diesem Wert beginnt das Zurückschreiben im Hintergrund. Etwa ein Viertel des harten Limits.',
            'source' => 'https://docs.kernel.org/admin-guide/sysctl/vm.html#dirty-background-bytes',
            'current' => static function () use ($ctx) {
                $b = $ctx->sysctlInt('vm.dirty_background_bytes');
                return $b === 0 ? $ctx->sysctlInt('vm.dirty_background_ratio') . ' %' : $b;
            },
            'recommend' => static fn(string $p) => $dirty($p, 256 * $MiB, 512 * $MiB),
        ]),
        $sysctl('vm.dirty_expire_centisecs', 'Dirty-Ablaufzeit', [
            'why' => 'Kernel-Default (30 s) passt. Kann manuell überschrieben werden.',
        ]),
        $sysctl('vm.dirty_writeback_centisecs', 'Writeback-Intervall', [
            'why' => 'Kernel-Default (5 s) passt. Größere Werte sparen kaum Strom, solange Platten ohnehin laufen.',
        ]),
        [
            'id' => 'mm.thp', 'category' => 'memory', 'title' => 'Transparent Huge Pages',
            'why' => '„always“ verursacht Latenzspitzen und Speicheraufblähung bei Redis/Valkey, PostgreSQL und MongoDB. Mit „madvise“ bekommen nur Programme Huge Pages, die sie anfordern (z. B. QEMU).',
            'source' => 'https://docs.kernel.org/admin-guide/mm/transhuge.html',
            'target' => ['file' => '/sys/kernel/mm/transparent_hugepage/enabled'],
            'applicable' => static fn() => $thp('enabled')[0] !== null ?: 'THP nicht verfügbar',
            'current' => static fn() => $thp('enabled')[0],
            'recommend' => static fn() => 'madvise',
        ],
        [
            'id' => 'mm.thp_defrag', 'category' => 'memory', 'title' => 'THP-Defragmentierung',
            'why' => 'Nur bei madvise-Anforderungen synchron kompaktieren, sonst entstehen Stalls im Page-Fault-Pfad.',
            'source' => 'https://docs.kernel.org/admin-guide/mm/transhuge.html',
            'target' => ['file' => '/sys/kernel/mm/transparent_hugepage/defrag'],
            'applicable' => static fn() => $thp('defrag')[0] !== null ?: 'THP nicht verfügbar',
            'current' => static fn() => $thp('defrag')[0],
            'recommend' => static fn() => 'madvise',
        ],
        $sysctl('vm.max_map_count', 'Max. Memory-Mappings', [
            'compare' => 'atLeast',
            'why' => 'Moderne Distributionen (Fedora, Ubuntu, Arch) nutzen 1048576. Spiele-Server (ARK, Steam), Elasticsearch und JVMs brauchen das. Ersetzt das User Script.',
            'recommend' => static fn() => 1048576,
        ]),
        $sysctl('vm.overcommit_memory', 'Memory-Overcommit', [
            'why' => 'Redis/Valkey verlangen „1“, sonst kann BGSAVE/Fork scheitern. Ohne solche Container bleibt der Default (0).',
            'source' => 'https://redis.io/docs/latest/operate/oss_and_stack/management/admin/',
            'recommend' => static fn() => $ctx->containerImageMatches('/(redis|valkey|keydb|dragonfly)/i') ? 1 : null,
        ]),
        $sysctl('vm.swappiness', 'Swappiness', [
            'applicable' => static fn() => $hasSwap ?: 'Kein Swap und kein zram',
            'recommend' => static fn() => ($ctx->hw['memory']['zram'] !== []) ? 100 : null,
            'why' => 'Mit zram ist ein hoher Wert sinnvoll (komprimiert statt Cache verwerfen).',
        ]),
        $sysctl('vm.vfs_cache_pressure', 'VFS-Cache-Druck', [
            'applicable' => static fn() => !($ctx->env['cacheDirs'] ?? false) ?: 'Wird von cache_dirs verwaltet',
            'why' => 'Default 100. Wird nur ohne cache_dirs angezeigt.',
        ]),
        $sysctl('vm.min_free_kbytes', 'Freie Speicherreserve', [
            'format' => 'kib', 'compare' => 'atLeast',
            'why' => 'ZFS-ARC und VMs fordern große zusammenhängende Speicherblöcke an. Etwa 0,2 % RAM Reserve verhindert Allokationsfehler (page allocation failure) unter Last.',
            'recommend' => static function () use ($ctx, $mem): ?int {
                if (!($ctx->hw['zfs']['loaded'] ?? false) && !$ctx->hasVms()) {
                    return null;
                }
                return (int)max(65536, min(262144, round($mem * 0.002 / 1024 / 1024) * 1024));
            },
        ]),
    ];
};
