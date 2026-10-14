<?php
declare(strict_types=1);

namespace PowerGuard;

/**
 * One tunable. Declared as a spec array in lib/tweaks/*.php so that modules stay
 * small and data-like. Closures receive the shared Context.
 *
 * Spec keys:
 *   id, category, title, risk (low|medium|high), timing (now|reboot), why, source?
 *   current(Context): mixed               – live value (null = unreadable)
 *   recommend(string profile, Context)    – target value, null = informational only
 *   applicable(Context): true|string      – string is the "not applicable" reason
 *   conflict(Context): ?string            – reason if something else owns the setting
 *   warn(Context): ?string                – advisory that should be surfaced
 *   compare: 'eq' | 'atLeast'
 *   format: 'bytes' | 'kib' | 'bool' | null
 *   target: descriptor for the apply engine (P2), e.g. ['sysctl' => 'vm.max_map_count']
 */
final class Tweak
{
    public const STATUS_OPTIMAL = 'optimal';
    public const STATUS_RECOMMENDED = 'recommended';
    public const STATUS_CUSTOM = 'custom';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_NA = 'na';
    public const STATUS_INFO = 'info';
    public const STATUS_WARNING = 'warning';
    public const STATUS_UNMANAGED = 'unmanaged';

    private function __construct(private array $spec)
    {
    }

    public static function make(array $spec): self
    {
        foreach (['id', 'category', 'title', 'current'] as $k) {
            if (!isset($spec[$k])) {
                throw new \InvalidArgumentException("tweak spec missing '$k'");
            }
        }
        return new self($spec + [
            'risk' => 'low', 'timing' => 'now', 'why' => '', 'source' => null,
            'compare' => 'eq', 'format' => null, 'target' => null,
        ]);
    }

    public function id(): string
    {
        return $this->spec['id'];
    }

    public function spec(string $key): mixed
    {
        return $this->spec[$key] ?? null;
    }

    /**
     * @param array{mode?:string,value?:mixed} $override per-tweak user setting
     */
    public function evaluate(string $profile, Context $ctx, array $override = []): array
    {
        $s = $this->spec;
        $row = [
            'id' => $s['id'], 'category' => $s['category'], 'title' => $s['title'],
            'risk' => $s['risk'], 'timing' => $s['timing'], 'why' => $s['why'], 'source' => $s['source'],
            'format' => $s['format'], 'mode' => $override['mode'] ?? 'auto',
            'manageable' => $s['target'] !== null,
            'current' => null, 'recommended' => null, 'target' => null,
            'status' => self::STATUS_INFO, 'reason' => null,
        ];

        $app = isset($s['applicable']) ? ($s['applicable'])($ctx) : true;
        if ($app !== true) {
            $row['status'] = self::STATUS_NA;
            $row['reason'] = is_string($app) ? $app : null;
            return $row;
        }

        try {
            $row['current'] = ($s['current'])($ctx);
            $row['recommended'] = isset($s['recommend']) ? ($s['recommend'])($profile, $ctx) : null;
        } catch (\Throwable $e) {
            $row['status'] = self::STATUS_WARNING;
            $row['reason'] = 'Fehler bei der Auswertung: ' . $e->getMessage();
            return $row;
        }

        if ($row['mode'] === 'off') {
            $row['status'] = self::STATUS_UNMANAGED;
            return $row;
        }
        if (isset($s['conflict']) && ($c = ($s['conflict'])($ctx)) !== null) {
            $row['status'] = self::STATUS_CONFLICT;
            $row['reason'] = $c;
            return $row;
        }

        $custom = $row['mode'] === 'custom' && array_key_exists('value', $override);
        $row['target'] = $custom ? $override['value'] : $row['recommended'];

        if ($row['target'] === null) {
            $w = isset($s['warn']) ? ($s['warn'])($ctx) : null;
            $row['status'] = $w !== null ? self::STATUS_WARNING : self::STATUS_INFO;
            $row['reason'] = $w;
            return $row;
        }
        if ($row['current'] === null) {
            $row['status'] = self::STATUS_WARNING;
            $row['reason'] = 'Ist-Wert nicht lesbar';
            return $row;
        }

        $ok = $this->matches($row['current'], $row['target']);
        $row['status'] = $ok ? ($custom ? self::STATUS_CUSTOM : self::STATUS_OPTIMAL) : self::STATUS_RECOMMENDED;
        if (isset($s['warn']) && ($w = ($s['warn'])($ctx)) !== null) {
            $row['reason'] = $w;
            if ($ok) {
                $row['status'] = self::STATUS_WARNING;
            }
        }
        return $row;
    }

    private function matches(mixed $cur, mixed $target): bool
    {
        if ($this->spec['compare'] === 'atLeast' && is_numeric($cur) && is_numeric($target)) {
            return $cur >= $target;
        }
        if (is_array($cur) || is_array($target)) {
            return $cur == $target;
        }
        return (string)(is_bool($cur) ? (int)$cur : $cur) === (string)(is_bool($target) ? (int)$target : $target);
    }
}
