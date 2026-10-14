<?php
declare(strict_types=1);

namespace PowerGuard;

return static function (Context $ctx): array {
    $pcie = $ctx->hw['pcie'];
    $usb = $ctx->hw['usb'];
    $protectedPci = array_flip($usb['storageControllers']);

    return [
        [
            'id' => 'pcie.aspm_policy', 'category' => 'pm', 'title' => 'PCIe ASPM-Policy',
            'risk' => 'medium',
            'why' => 'ASPM lässt PCIe-Links im Leerlauf in L0s/L1 schlafen. Das ist Voraussetzung für tiefe Package-C-States (PC8/PC10).',
            'source' => 'https://docs.kernel.org/admin-guide/kernel-parameters.html (pcie_aspm.policy)',
            'target' => ['file' => '/sys/module/pcie_aspm/parameters/policy'],
            'applicable' => static fn() => $pcie['policy'] !== null ?: 'pcie_aspm nicht verfügbar',
            // Without OS control the policy file is inert; what counts is LnkCtl.
            'conflict' => static fn() => ($pcie['osControl'] || $pcie['linksL1'] > 0) ? null
                : 'Die Firmware gibt dem Betriebssystem keine ASPM-Kontrolle (FADT/_OSC) und hat ASPM selbst nicht aktiviert. Im BIOS ASPM bzw. „Native ASPM“ auf Enabled/Auto stellen.',
            'current' => static fn() => $pcie['osControl']
                ? $pcie['policy'] . ($pcie['links'] ? " (L1 auf {$pcie['linksL1']}/{$pcie['links']} Links)" : '')
                : "Firmware-gesteuert, L1 auf {$pcie['linksL1']}/{$pcie['links']} Links",
            'recommend' => static fn(string $p) => $pcie['osControl']
                ? ['efficiency' => 'powersupersave', 'balanced' => 'powersave', 'performance' => 'default'][$p]
                : null,
            // Firmware owns ASPM: the policy file is inert, only missing L1 is worth a warning.
            'warn' => static function () use ($pcie): ?string {
                $off = $pcie['links'] - $pcie['linksL1'];
                return !$pcie['osControl'] && $off > 0
                    ? "Die Firmware steuert ASPM und lässt $off Link(s) ohne L1. Das kann tiefe Package-C-States verhindern; im BIOS ASPM für alle Ports aktivieren."
                    : null;
            },
        ],
        [
            'id' => 'pcie.aspm_nic', 'category' => 'pm', 'title' => 'ASPM an der Netzwerkkarte',
            'risk' => 'high',
            'why' => 'Intel I225/I226 (igc) neigt mit ASPM L1 zu Link-Abbrüchen („PCIe link lost“) und Latenzspitzen. Wenn das im Log auftaucht, L1 für diese Karte abschalten (BIOS oder pcie_aspm=off für den Port).',
            'applicable' => static function () use ($ctx) {
                foreach ($ctx->hw['nics'] as $n) {
                    if ($n['driver'] === 'igc') {
                        return true;
                    }
                }
                return 'Keine igc-Karte';
            },
            'current' => static function () use ($ctx, $pcie): string {
                $res = [];
                foreach ($ctx->hw['nics'] as $n) {
                    foreach ($pcie['devices'] as $d) {
                        if ($d['pci'] === $n['pci']) {
                            $res[] = "{$n['name']}: " . ($d['aspm'] ?? '?');
                        }
                    }
                }
                return implode(', ', $res) ?: '?';
            },
            'warn' => static function () use ($ctx, $pcie): ?string {
                foreach ($ctx->hw['nics'] as $n) {
                    foreach ($pcie['devices'] as $d) {
                        if ($n['driver'] === 'igc' && $d['pci'] === $n['pci'] && str_contains((string)$d['aspm'], 'L1')) {
                            return 'L1 ist am I226 aktiv. Bei Link-Abbrüchen im Syslog ist das die erste Ursache.';
                        }
                    }
                }
                return null;
            },
        ],
        [
            'id' => 'pci.runtime_pm', 'category' => 'pm', 'title' => 'PCI Runtime-PM',
            'why' => 'Unbenutzte PCI-Funktionen dürfen schlafen. Ausgenommen werden automatisch: USB-Controller mit angeschlossenem Speicher, mei_me und optional die GPU. Die Umsetzung folgt in Phase 4; hier nur der Ist-Stand.',
            'current' => static function () use ($pcie): string {
                $auto = count(array_filter($pcie['devices'], static fn($d) => $d['control'] === 'auto'));
                return "$auto von " . count($pcie['devices']) . ' auf auto';
            },
            'warn' => static function () use ($pcie, $protectedPci): ?string {
                $bad = array_filter($pcie['devices'], static fn($d) => isset($protectedPci[$d['pci']]) && $d['control'] === 'auto');
                return $bad ? 'USB-Speicher-Controller mit Runtime-PM: ' . implode(', ', array_column($bad, 'pci')) : null;
            },
        ],
        [
            'id' => 'usb.protection', 'category' => 'pm', 'title' => 'USB-Schutzregeln',
            'risk' => 'low',
            'why' => 'USB-Speicher samt vorgelagerten Hubs, Eingabegeräte, USV und KVM müssen dauerhaft auf „on“ stehen. Autosuspend führt sonst zu Platten-Resets, verlorenen USV-Ereignissen oder hängender Eingabe.',
            'current' => static function () use ($usb): string {
                $prot = array_filter($usb['devices'], static fn($d) => $d['protected'] && !$d['root']);
                $bad = array_filter($prot, static fn($d) => $d['violation']);
                return count($prot) . ' geschützt, ' . count($bad) . ' nicht auf „on“';
            },
            'warn' => static function () use ($usb): ?string {
                $bad = array_filter($usb['devices'], static fn($d) => $d['violation']);
                if (!$bad) {
                    return null;
                }
                return 'Auf „auto“: ' . implode(', ', array_map(
                    static fn($d) => $d['id'] . ' ' . ($d['product'] ?? $d['vid'] . ':' . $d['pid']) . ' (' . implode('/', $d['roles']) . ')',
                    $bad
                ));
            },
        ],
        [
            'id' => 'sata.alpm', 'category' => 'pm', 'title' => 'SATA Link-PM (ALPM)',
            'why' => '„med_power_with_dipm“ ist der stromsparende, aber stabile Modus. „min_power“ kann bei manchen Platten zu Fehlern führen.',
            'source' => 'https://docs.kernel.org/admin-guide/kernel-parameters.html',
            'applicable' => static fn() => $ctx->hw['sata'] !== [] ?: 'Kein SATA-Host-Controller',
            'current' => static fn() => Sys::uniform(array_column($ctx->hw['sata'], 'policy')),
            'recommend' => static fn(string $p) => $p === 'performance' ? 'max_performance' : 'med_power_with_dipm',
        ],
        [
            'id' => 'nvme.apst', 'category' => 'pm', 'title' => 'NVMe APST',
            'why' => 'Der Kernel-Default aktiviert APST. „nvme_core.default_ps_max_latency_us“ wird nur bei Problemen (Controller-Resets) gesetzt.',
            'applicable' => static fn() => $ctx->hw['nvme']['controllers'] !== [] ?: 'Keine NVMe',
            'current' => static fn() => $ctx->hw['nvme']['apstMaxLatency'] === 0 ? 'aus' : 'an (max. ' . $ctx->hw['nvme']['apstMaxLatency'] . ' µs)',
        ],
        [
            'id' => 'audio.power_save', 'category' => 'pm', 'title' => 'HDA-Audio Power-Save',
            'applicable' => static fn() => Sys::exists('/sys/module/snd_hda_intel/parameters/power_save') ?: 'snd_hda_intel nicht geladen',
            'target' => ['file' => '/sys/module/snd_hda_intel/parameters/power_save'],
            'current' => static fn() => Sys::readInt('/sys/module/snd_hda_intel/parameters/power_save'),
            'recommend' => static fn() => 1,
        ],
    ];
};
