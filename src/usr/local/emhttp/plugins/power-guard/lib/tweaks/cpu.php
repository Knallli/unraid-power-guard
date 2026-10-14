<?php
declare(strict_types=1);

namespace PowerGuard;

return static function (Context $ctx): array {
    $cpu = $ctx->hw['cpu'];
    $epp = in_array($cpu['driver'], ['intel_pstate', 'amd-pstate-epp'], true) && $cpu['driverStatus'] === 'active';

    return [
        [
            'id' => 'cpu.governor', 'category' => 'cpu', 'title' => 'CPU-Governor',
            'why' => $epp
                ? 'Bei aktivem ' . $cpu['driver'] . ' ist „powersave“ der dynamische HWP-Modus; die Feinsteuerung läuft über EPP. „performance“ erzwingt EPP 0 und kostet dauerhaft Leistung.'
                : 'Ohne HWP/EPP ist „schedutil“ der lastabhängige Standard; „performance“ hält den Maximaltakt.',
            'source' => 'https://docs.kernel.org/admin-guide/pm/intel_pstate.html',
            'target' => ['cpufreq' => 'scaling_governor'],
            'applicable' => static fn() => $cpu['governor'] !== null ?: 'Kein cpufreq-Treiber aktiv',
            'current' => static fn() => $cpu['governor'],
            'recommend' => static function (string $p) use ($epp, $cpu): ?string {
                if ($epp) {
                    return 'powersave';
                }
                if ($p === 'performance') {
                    return 'performance';
                }
                return in_array('schedutil', $cpu['governors'], true) ? 'schedutil' : null;
            },
        ],
        [
            'id' => 'cpu.epp', 'category' => 'cpu', 'title' => 'Energy Performance Preference (EPP)',
            'why' => 'Steuert, wie aggressiv die Hardware hochtaktet. „balance_power“ ist für 24/7-Betrieb der beste Kompromiss aus Leerlaufverbrauch und Reaktionszeit.',
            'source' => 'https://docs.kernel.org/admin-guide/pm/intel_pstate.html#energy-vs-performance-hints',
            'target' => ['cpufreq' => 'energy_performance_preference'],
            'applicable' => static fn() => ($cpu['eppOptions'] !== [] && $epp) ?: 'Kein EPP verfügbar (kein HWP/amd-pstate-epp)',
            'conflict' => static fn() => $cpu['governor'] === 'performance' ? 'Governor „performance“ sperrt EPP auf „performance“' : null,
            'current' => static fn() => $cpu['epp'],
            'recommend' => static fn(string $p) => ['efficiency' => 'balance_power', 'balanced' => 'balance_performance', 'performance' => 'performance'][$p],
        ],
        [
            'id' => 'cpu.hwp_boost', 'category' => 'cpu', 'title' => 'HWP Dynamic Boost',
            'why' => 'Hebt nach I/O-Wartezeiten kurz die Mindestperformance an. Verbessert Latenzen bei kaum messbarem Mehrverbrauch.',
            'source' => 'https://docs.kernel.org/admin-guide/pm/intel_pstate.html#hwp-dynamic-boost',
            'format' => 'bool', 'target' => ['file' => '/sys/devices/system/cpu/intel_pstate/hwp_dynamic_boost'],
            'applicable' => static fn() => $cpu['hwpBoost'] !== null ?: 'Nur mit intel_pstate + HWP',
            'current' => static fn() => $cpu['hwpBoost'],
            'recommend' => static fn() => 1,
        ],
        [
            'id' => 'cpu.turbo', 'category' => 'cpu', 'title' => 'Turbo Boost',
            'why' => 'Mit HWP/EPP entscheidet die CPU selbst, wann Turbo sich lohnt. Turbo abzuschalten verlängert Lastspitzen und spart im Homelab-Mix meist nichts („race to idle“). Ein Zeitplan kann nachts trotzdem ein sparsameres Profil wählen.',
            'format' => 'bool', 'target' => ['turbo' => $cpu['turboKnob']],
            'applicable' => static fn() => $cpu['turbo'] !== null ?: 'Kein Turbo-Schalter gefunden',
            'current' => static fn() => $cpu['turbo'] === null ? null : (int)$cpu['turbo'],
            'recommend' => static fn() => 1,
        ],
        [
            'id' => 'cpu.nmi_watchdog', 'category' => 'cpu', 'title' => 'NMI-Watchdog',
            'why' => 'Der Hard-Lockup-Detektor erzeugt regelmäßige Perf-Interrupts und verhindert tiefe Package-C-States. Laufzeit-Abschaltung sofort; der Kernel-Parameter folgt mit dem Bootloader-Modul.',
            'source' => 'https://docs.kernel.org/admin-guide/sysctl/kernel.html#nmi-watchdog',
            'format' => 'bool', 'target' => ['sysctl' => 'kernel.nmi_watchdog'],
            'applicable' => static fn() => $ctx->sysctl('kernel.nmi_watchdog') !== null ?: 'Kernel ohne Hard-Lockup-Detektor, es entstehen keine Watchdog-Wakeups',
            'current' => static fn() => $ctx->sysctlInt('kernel.nmi_watchdog'),
            'recommend' => static fn(string $p) => $p === 'performance' ? null : 0,
        ],
        [
            'id' => 'cpu.cstates', 'category' => 'cpu', 'title' => 'C-States (Leerlauf)',
            'why' => 'Es wird nichts begrenzt, solange die tiefen Zustände erreicht werden. „intel_idle.max_cstate“ ist nur als Notfall-Option bei Instabilität gedacht.',
            'applicable' => static fn() => $cpu['idleStates'] !== [] ?: 'Kein cpuidle',
            'current' => static function () use ($cpu): string {
                $on = array_values(array_filter($cpu['idleStates'], static fn($s) => !$s['disabled']));
                $names = array_column($on, 'name');
                return ($cpu['idleDriver'] ?? '?') . ': ' . implode(', ', $names);
            },
            'warn' => static function () use ($cpu): ?string {
                $off = array_column(array_filter($cpu['idleStates'], static fn($s) => $s['disabled']), 'name');
                return $off ? 'Deaktiviert: ' . implode(', ', $off) : null;
            },
        ],
    ];
};
