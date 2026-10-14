<?php
declare(strict_types=1);

namespace PowerGuard;

/** Everything a tweak needs to decide: hardware snapshot, conflicts, helpers. */
final class Context
{
    public function __construct(public readonly array $hw, public readonly array $env = [])
    {
    }

    public static function build(?array $hw = null): self
    {
        $hw ??= Hardware::detect();
        return new self($hw, Conflicts::environment());
    }

    public function sysctl(string $key): ?string
    {
        $v = Sys::read('/proc/sys/' . str_replace('.', '/', $key));
        return $v === null ? null : preg_replace('/\s+/', ' ', $v);
    }

    public function sysctlInt(string $key): ?int
    {
        $v = $this->sysctl($key);
        return ($v !== null && is_numeric($v)) ? (int)$v : null;
    }

    public function memBytes(): int
    {
        return (int)($this->hw['memory']['totalKiB'] ?? 0) * 1024;
    }

    public function hasUsbStorage(): bool
    {
        foreach ($this->hw['usb']['devices'] ?? [] as $d) {
            if (in_array('storage', $d['roles'], true)) {
                return true;
            }
        }
        return false;
    }

    public function hasVms(): bool
    {
        return Sys::glob('/sys/class/net/vnet*') !== [] || Sys::exists('/var/run/libvirt/libvirt-sock');
    }

    public function containerImageMatches(string $regex): bool
    {
        foreach ($this->hw['containers']['images'] ?? [] as $img) {
            if (preg_match($regex, $img)) {
                return true;
            }
        }
        return false;
    }

    /** Fastest link speed of an "up" physical NIC in Mb/s. */
    public function maxNicSpeed(): ?int
    {
        $max = null;
        foreach ($this->hw['nics'] ?? [] as $n) {
            if ($n['speed'] !== null && ($max === null || $n['speed'] > $max)) {
                $max = $n['speed'];
            }
        }
        return $max;
    }
}
