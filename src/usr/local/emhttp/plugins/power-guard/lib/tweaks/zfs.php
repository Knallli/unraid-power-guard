<?php
declare(strict_types=1);

namespace PowerGuard;

return static function (Context $ctx): array {
    $zfs = $ctx->hw['zfs'];
    if (!($zfs['loaded'] ?? false)) {
        return [[
            'id' => 'zfs.arc_max', 'category' => 'zfs', 'title' => 'ZFS ARC-Limit',
            'applicable' => static fn() => 'ZFS nicht geladen', 'current' => static fn() => null,
        ]];
    }
    $GiB = 1073741824;
    $mem = $ctx->memBytes();
    $rows = [[
        'id' => 'zfs.arc_max', 'category' => 'zfs', 'title' => 'ZFS ARC-Limit',
        'format' => 'bytes', 'risk' => 'low',
        'why' => 'Ohne Limit darf der ARC fast den gesamten RAM belegen. Er gibt ihn zwar frei, aber zu langsam für Container und VMs, die plötzlich Speicher brauchen. RAM/4 (mindestens 4 GiB) lässt genug Cache und hält den Rest frei.',
        'source' => 'https://openzfs.github.io/openzfs-docs/Performance%20and%20Tuning/Module%20Parameters.html#zfs-arc-max',
        'target' => ['file' => '/sys/module/zfs/parameters/zfs_arc_max'],
        'current' => static fn() => ($zfs['arc']['paramMax'] ?? 0) ?: 'auto (' . round(($zfs['arc']['c_max'] ?? 0) / $GiB, 1) . ' GiB)',
        'recommend' => static function (string $p) use ($mem, $GiB): int {
            $div = $p === 'performance' ? 3 : 4;
            return (int)max(4 * $GiB, round($mem / $div / $GiB) * $GiB);
        },
    ]];

    foreach ($zfs['pools'] as $pool) {
        $name = $pool['name'];
        $rows[] = [
            'id' => "zfs.pool.$name", 'category' => 'zfs', 'title' => "Pool „{$name}“",
            'why' => 'Ab etwa 80 % Belegung muss ZFS nach freien Blöcken suchen, die Schreibleistung bricht ein. Hohe Fragmentierung verstärkt das. Autotrim hält SSDs schnell.',
            'source' => 'https://openzfs.github.io/openzfs-docs/Performance%20and%20Tuning/Workload%20Tuning.html#free-space',
            'current' => static fn() => sprintf('%d %% belegt, %s %% frag., autotrim %s', $pool['cap'], $pool['frag'] ?? '–', $pool['autotrim'] ?? '?'),
            'warn' => static function () use ($pool): ?string {
                $w = [];
                if ($pool['cap'] >= 80) {
                    $w[] = 'Belegung über 80 %: Daten verschieben oder Pool erweitern';
                }
                if ($pool['health'] !== 'ONLINE') {
                    $w[] = 'Status ' . $pool['health'];
                }
                return $w ? implode('; ', $w) : null;
            },
        ];
    }
    return $rows;
};
