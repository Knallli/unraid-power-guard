<?php
declare(strict_types=1);

namespace PowerGuard;

return static function (Context $ctx): array {
    $MiB = 1048576;
    $speed = $ctx->maxNicSpeed() ?? 1000;
    $buf = $speed >= 10000 ? 64 * $MiB : ($speed >= 2500 ? 32 * $MiB : 16 * $MiB);
    $sysctl = static fn(string $key, string $title, array $more) => $more + [
        'id' => $key, 'category' => 'net', 'title' => $title,
        'target' => ['sysctl' => $key],
        'applicable' => static fn() => $ctx->sysctl($key) !== null ?: 'Nicht vorhanden',
        'current' => static fn() => $ctx->sysctl($key),
    ];

    $rows = [
        $sysctl('net.core.default_qdisc', 'Standard-Qdisc', [
            'why' => '„fq“ ist Voraussetzung für sauberes BBR-Pacing.',
            'recommend' => static fn() => 'fq',
        ]),
        $sysctl('net.ipv4.tcp_congestion_control', 'TCP-Staukontrolle', [
            'why' => 'BBR liefert über WAN/VPN (Tailscale) deutlich höheren Durchsatz bei geringerer Latenz als CUBIC.',
            'source' => 'https://github.com/google/bbr',
            'recommend' => static fn() => str_contains($ctx->sysctl('net.ipv4.tcp_available_congestion_control') ?? '', 'bbr') ? 'bbr' : null,
        ]),
        $sysctl('net.core.rmem_max', 'Max. Empfangspuffer', [
            'format' => 'bytes', 'compare' => 'atLeast',
            'why' => "Passend zur schnellsten NIC ({$speed} Mb/s), damit ein TCP-Fenster das Bandbreiten-Verzögerungs-Produkt abdeckt.",
            'current' => static fn() => $ctx->sysctlInt('net.core.rmem_max'),
            'recommend' => static fn() => $buf,
        ]),
        $sysctl('net.core.wmem_max', 'Max. Sendepuffer', [
            'format' => 'bytes', 'compare' => 'atLeast',
            'why' => "Passend zur schnellsten NIC ({$speed} Mb/s).",
            'current' => static fn() => $ctx->sysctlInt('net.core.wmem_max'),
            'recommend' => static fn() => $buf,
        ]),
        $sysctl('net.ipv4.tcp_mtu_probing', 'TCP MTU Probing', [
            'why' => 'Erkennt PMTU-Blackholes (häufig bei VPN/Tailscale, PPPoE) und senkt die MSS selbstständig, statt Verbindungen hängen zu lassen.',
            'source' => 'https://docs.kernel.org/networking/ip-sysctl.html',
            'current' => static fn() => $ctx->sysctlInt('net.ipv4.tcp_mtu_probing'),
            'recommend' => static fn() => 1,
        ]),
        $sysctl('fs.inotify.max_user_instances', 'inotify-Instanzen', [
            'compare' => 'atLeast', 'category' => 'net',
            'why' => 'Viele Container (' . ($ctx->hw['containers']['running'] ?? '?') . ') öffnen je eigene inotify-Instanzen; 128 reicht dann nicht („too many open files“).',
            'current' => static fn() => $ctx->sysctlInt('fs.inotify.max_user_instances'),
            'recommend' => static fn() => 1024,
        ]),
        $sysctl('fs.inotify.max_user_watches', 'inotify-Watches', [
            'compare' => 'atLeast',
            'why' => 'Für Syncthing, Plex-Scanner und Dateiüberwachung in Containern.',
            'current' => static fn() => $ctx->sysctlInt('fs.inotify.max_user_watches'),
            'recommend' => static fn() => 524288,
        ]),
    ];

    foreach ($ctx->hw['nics'] as $nic) {
        $if = $nic['name'];
        if ($nic['ring'] !== null) {
            $rows[] = [
                'id' => "nic.ring.$if", 'category' => 'net', 'title' => "Ring-Buffer $if ({$nic['driver']})",
                'why' => 'Größere RX/TX-Ringe fangen Burst-Traffic ab, ohne dass Pakete verworfen werden. Kosten: wenige MiB RAM.',
                'target' => ['ethtool-ring' => $if],
                'current' => static fn() => $nic['ring']['rx'] . '/' . $nic['ring']['tx'],
                'recommend' => static function (string $p) use ($nic): string {
                    $want = $p === 'performance' ? 2048 : 1024;
                    $rx = min($want, $nic['ring']['rxMax'] ?? $want);
                    $tx = min($want, $nic['ring']['txMax'] ?? $want);
                    return "$rx/$tx";
                },
            ];
        }
        if ($nic['eee'] !== null) {
            $buggy = in_array($nic['driver'], ['igc'], true);
            $rows[] = [
                'id' => "nic.eee.$if", 'category' => 'net', 'title' => "Energy Efficient Ethernet $if",
                'risk' => $buggy ? 'high' : 'medium', 'format' => 'bool',
                'why' => $buggy
                    ? 'Beim Intel I225/I226 (igc) führt EEE zu Link-Abbrüchen und Latenzspitzen. Bleibt aus.'
                    : 'EEE spart etwa 0,3–0,5 W pro Port, kann aber je nach Switch die Latenz erhöhen.',
                'target' => ['ethtool-eee' => $if],
                'current' => static fn() => (int)$nic['eee'],
                'recommend' => static fn(string $p) => $buggy ? 0 : null,
            ];
        }
        if ($nic['wol'] !== null) {
            $rows[] = [
                'id' => "nic.wol.$if", 'category' => 'net', 'title' => "Wake-on-LAN $if",
                'why' => 'Nur Anzeige. Aktives WoL („g“) hält die PHY im Suspend versorgt; die NIC-PM richtet sich danach.',
                'current' => static fn() => $nic['wol'],
            ];
        }
    }
    return $rows;
};
