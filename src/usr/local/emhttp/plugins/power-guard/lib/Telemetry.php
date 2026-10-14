<?php
declare(strict_types=1);

namespace PowerGuard;

/**
 * Cheap point-in-time sample. Rates (watts, residency %) are derived by the
 * client from two consecutive samples, so the server stays stateless.
 */
final class Telemetry
{
    public static function sample(): array
    {
        return [
            't'     => microtime(true),
            'rapl'  => self::rapl(),
            'freq'  => self::freq(),
            'idle'  => self::idle(),
            'temps' => self::temps(),
        ];
    }

    private static function rapl(): array
    {
        $res = [];
        foreach (Sys::glob('/sys/class/powercap/intel-rapl:*') as $z) {
            $uj = Sys::read("$z/energy_uj");
            if ($uj === null) {
                continue;
            }
            $res[] = [
                'zone' => basename($z),
                'name' => Sys::read("$z/name"),
                'uj'   => (int)$uj,
                'max'  => Sys::readInt("$z/max_energy_range_uj"),
            ];
        }
        return $res;
    }

    private static function freq(): array
    {
        $res = [];
        foreach (Sys::cpuList(Sys::read('/sys/devices/system/cpu/online')) as $c) {
            $res[$c] = Sys::readInt("/sys/devices/system/cpu/cpu$c/cpufreq/scaling_cur_freq");
        }
        return $res;
    }

    /** Cumulative per-state residency (µs) summed over all CPUs. */
    private static function idle(): array
    {
        $res = [];
        foreach (Sys::cpuList(Sys::read('/sys/devices/system/cpu/online')) as $c) {
            foreach (Sys::glob("/sys/devices/system/cpu/cpu$c/cpuidle/state*") as $s) {
                $name = Sys::read("$s/name") ?? basename($s);
                $res[$name] = ($res[$name] ?? 0) + (int)Sys::read("$s/time");
            }
        }
        return $res;
    }

    private static function temps(): array
    {
        $res = [];
        foreach (Sys::glob('/sys/class/hwmon/hwmon*') as $h) {
            $chip = Sys::read("$h/name") ?? basename($h);
            foreach (Sys::glob("$h/temp*_input") as $in) {
                $v = Sys::readInt($in);
                if ($v === null || $v <= 0) {
                    continue;
                }
                $label = Sys::read(str_replace('_input', '_label', $in));
                $res[] = ['chip' => $chip, 'label' => $label ?? basename($in, '_input'), 'c' => round($v / 1000, 1)];
            }
        }
        return $res;
    }
}
