<?php
declare(strict_types=1);

namespace PowerGuard;

final class Registry
{
    public const PROFILES = ['efficiency', 'balanced', 'performance'];

    public const CATEGORIES = [
        'cpu'    => 'CPU & Energie',
        'pm'     => 'PCIe / Geräte-PM',
        'memory' => 'Speicher / VM',
        'zfs'    => 'ZFS',
        'net'    => 'Netzwerk',
    ];

    /** @return list<Tweak> */
    public static function load(Context $ctx): array
    {
        $tweaks = [];
        foreach (glob(__DIR__ . '/tweaks/*.php') ?: [] as $file) {
            $factory = require $file;
            foreach ($factory($ctx) as $spec) {
                $tweaks[] = Tweak::make($spec);
            }
        }
        return $tweaks;
    }

    /** Evaluate all tweaks for a profile; returns rows plus score. */
    public static function plan(Context $ctx, array $config): array
    {
        $profile = in_array($config['profile'] ?? '', self::PROFILES, true) ? $config['profile'] : 'efficiency';
        $rows = [];
        foreach (self::load($ctx) as $t) {
            $rows[] = $t->evaluate($profile, $ctx, $config['tweaks'][$t->id()] ?? []);
        }
        $order = array_flip(array_keys(self::CATEGORIES));
        usort($rows, static fn(array $a, array $b): int => ($order[$a['category']] ?? 99) <=> ($order[$b['category']] ?? 99));
        return ['profile' => $profile, 'rows' => $rows, 'score' => self::score($rows)];
    }

    /**
     * Share of actionable tweaks that are at target, weighted by risk
     * (a pending low-risk tweak costs more than a pending high-risk one,
     * because high-risk tweaks are opt-in by nature).
     */
    public static function score(array $rows): array
    {
        $weights = ['low' => 3, 'medium' => 2, 'high' => 1];
        $total = $got = 0;
        $counts = [];
        foreach ($rows as $r) {
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
            $w = $weights[$r['risk']] ?? 1;
            switch ($r['status']) {
                case Tweak::STATUS_OPTIMAL:
                case Tweak::STATUS_CUSTOM:
                    $total += $w;
                    $got += $w;
                    break;
                case Tweak::STATUS_RECOMMENDED:
                case Tweak::STATUS_CONFLICT:
                case Tweak::STATUS_WARNING:
                    $total += $w;
                    break;
            }
        }
        return ['value' => $total > 0 ? (int)round($got / $total * 100) : 100, 'counts' => $counts];
    }
}
