<?php
declare(strict_types=1);

namespace PowerGuard;

/**
 * Read-only hardware / platform detection. Never writes anything.
 * Result is a plain array so it can be cached and JSON-encoded.
 */
final class Hardware
{
    private const CPU = '/sys/devices/system/cpu';

    public static function detect(): array
    {
        return [
            'os'         => self::os(),
            'cpu'        => self::cpu(),
            'memory'     => self::memory(),
            'boot'       => self::boot(),
            'gpu'        => self::gpu(),
            'nics'       => self::nics(),
            'usb'        => self::usb(),
            'pcie'       => self::pcie(),
            'sata'       => self::sata(),
            'nvme'       => self::nvme(),
            'zfs'        => self::zfs(),
            'rapl'       => Sys::exists('/sys/class/powercap/intel-rapl:0/energy_uj'),
            'containers' => self::containers(),
            'detected'   => time(),
        ];
    }

    public static function os(): array
    {
        $ver = null;
        $raw = Sys::read('/etc/unraid-version');
        if ($raw !== null && preg_match('/version="([^"]+)"/', $raw, $m)) {
            $ver = $m[1];
        }
        return [
            'unraid' => $ver,
            'kernel' => Sys::read('/proc/sys/kernel/osrelease'),
            'php'    => PHP_VERSION,
        ];
    }

    public static function cpu(): array
    {
        $info = Sys::readRaw('/proc/cpuinfo') ?? '';
        $field = static function (string $name) use ($info): ?string {
            return preg_match('/^' . preg_quote($name, '/') . '\s*:\s*(.+)$/m', $info, $m) ? trim($m[1]) : null;
        };
        $online = Sys::cpuList(Sys::read(self::CPU . '/online'));
        $pCores = Sys::cpuList(Sys::read('/sys/devices/cpu_core/cpus'));
        $eCores = Sys::cpuList(Sys::read('/sys/devices/cpu_atom/cpus'));

        $gov = $epp = $cur = [];
        foreach ($online as $c) {
            $base = self::CPU . "/cpu$c/cpufreq";
            $gov[$c] = Sys::read("$base/scaling_governor");
            $epp[$c] = Sys::read("$base/energy_performance_preference");
            $cur[$c] = Sys::readInt("$base/scaling_cur_freq");
        }
        $c0 = self::CPU . '/cpu0/cpufreq';
        $pstate = self::CPU . '/intel_pstate';

        $noTurbo = Sys::readInt("$pstate/no_turbo");
        $boost = Sys::readInt(self::CPU . '/cpufreq/boost');
        $turbo = $noTurbo !== null ? $noTurbo === 0 : ($boost !== null ? $boost === 1 : null);

        $states = [];
        foreach (Sys::glob(self::CPU . '/cpu0/cpuidle/state*') as $s) {
            $states[] = [
                'name'     => Sys::read("$s/name"),
                'latency'  => Sys::readInt("$s/latency"),
                'disabled' => Sys::readInt("$s/disable") === 1,
            ];
        }

        $flags = $field('flags') ?? '';
        return [
            'vendor'       => $field('vendor_id'),
            'model'        => $field('model name'),
            'family'       => ($f = $field('cpu family')) !== null ? (int)$f : null,
            'modelId'      => ($f = $field('model')) !== null ? (int)$f : null,
            'threads'      => count($online),
            'hybrid'       => $pCores !== [] && $eCores !== [],
            'pCores'       => $pCores,
            'eCores'       => $eCores,
            'driver'       => Sys::read("$c0/scaling_driver"),
            'driverStatus' => Sys::read("$pstate/status") ?? Sys::read(self::CPU . '/amd_pstate/status'),
            'hwp'          => str_contains($flags, ' hwp'),
            'governor'     => Sys::uniform($gov),
            'governors'    => preg_split('/\s+/', Sys::read("$c0/scaling_available_governors") ?? '') ?: [],
            'epp'          => Sys::uniform($epp),
            'eppPerCpu'    => $epp,
            'eppOptions'   => array_values(array_filter(preg_split('/\s+/', Sys::read("$c0/energy_performance_available_preferences") ?? '') ?: [])),
            'turbo'        => $turbo,
            'turboKnob'    => $noTurbo !== null ? 'intel_pstate/no_turbo' : ($boost !== null ? 'cpufreq/boost' : null),
            'hwpBoost'     => Sys::readInt("$pstate/hwp_dynamic_boost"),
            'minKHz'       => Sys::readInt("$c0/cpuinfo_min_freq"),
            'maxKHz'       => Sys::readInt("$c0/cpuinfo_max_freq"),
            'curKHz'       => $cur,
            'idleDriver'   => Sys::read(self::CPU . '/cpuidle/current_driver'),
            'idleGovernor' => Sys::read(self::CPU . '/cpuidle/current_governor'),
            'idleStates'   => $states,
        ];
    }

    public static function memory(): array
    {
        $mi = Sys::readRaw('/proc/meminfo') ?? '';
        $kb = static fn(string $k): ?int => preg_match('/^' . $k . ':\s+(\d+)/m', $mi, $m) ? (int)$m[1] : null;
        // zram0 often exists with the module loaded but is unused until it gets a disksize.
        $zram = array_values(array_map('basename', array_filter(
            Sys::glob('/sys/block/zram*'),
            static fn(string $z): bool => (int)Sys::read("$z/disksize") > 0
        )));
        return [
            'totalKiB' => $kb('MemTotal'),
            'swapKiB'  => $kb('SwapTotal'),
            'zram'     => $zram,
            'thp'      => Sys::bracketed(Sys::read('/sys/kernel/mm/transparent_hugepage/enabled'))[0],
        ];
    }

    public static function boot(): array
    {
        $loaders = [];
        foreach (['grub' => '/boot/grub/grub.cfg', 'syslinux' => '/boot/syslinux/syslinux.cfg'] as $type => $file) {
            $raw = Sys::readRaw($file);
            if ($raw === null) {
                continue;
            }
            $loaders[] = [
                'type'    => $type,
                'file'    => $file,
                'eol'     => str_contains($raw, "\r\n") ? 'crlf' : 'lf',
                'entries' => $type === 'grub'
                    ? preg_match_all('/^\s*menuentry\s/m', $raw)
                    : preg_match_all('/^\s*label\s/mi', $raw),
            ];
        }
        $fs = Sys::run(['findmnt', '-n', '-o', 'FSTYPE', '/boot']);
        return [
            'loader'  => $loaders[0]['type'] ?? null,
            'loaders' => $loaders,
            'bootFs'  => $fs ?: null,
            'cmdline' => Sys::read('/proc/cmdline'),
        ];
    }

    public static function gpu(): array
    {
        $res = [];
        foreach (Sys::glob('/sys/class/drm/card[0-9]') as $card) {
            $dev = "$card/device";
            $pci = Sys::linkName($dev);
            $res[] = [
                'card'   => basename($card),
                'pci'    => $pci,
                'driver' => Sys::linkName("$dev/driver"),
                'vendor' => Sys::read("$dev/vendor"),
                'device' => Sys::read("$dev/device"),
                'pm'     => Sys::read("$dev/power/control"),
            ];
        }
        return [
            'cards'    => $res,
            'i915Guc'  => Sys::readInt('/sys/module/i915/parameters/enable_guc'),
            'xeLoaded' => Sys::exists('/sys/module/xe'),
        ];
    }

    public static function nics(): array
    {
        $res = [];
        foreach (Sys::glob('/sys/class/net/*') as $n) {
            if (!Sys::exists("$n/device")) {
                continue; // virtual: bridge, veth, tun, …
            }
            $if = basename($n);
            $speed = Sys::readInt("$n/speed");
            $nic = [
                'name'     => $if,
                'pci'      => Sys::linkName("$n/device"),
                'driver'   => Sys::linkName("$n/device/driver"),
                'state'    => Sys::read("$n/operstate"),
                'speed'    => ($speed !== null && $speed > 0) ? $speed : null,
                'mtu'      => Sys::readInt("$n/mtu"),
                'ring'     => null,
                'eee'      => null,
                'wol'      => null,
                'wolModes' => null,
            ];
            if (($g = Sys::run(['ethtool', '-g', $if])) !== null) {
                $parts = preg_split('/Current hardware settings:/', $g);
                $num = static fn(string $s, string $k): ?int => preg_match('/^' . $k . ':\s+(\d+)/m', $s, $m) ? (int)$m[1] : null;
                if (count($parts) === 2) {
                    $nic['ring'] = [
                        'rx' => $num($parts[1], 'RX'), 'tx' => $num($parts[1], 'TX'),
                        'rxMax' => $num($parts[0], 'RX'), 'txMax' => $num($parts[0], 'TX'),
                    ];
                }
            }
            if (($e = Sys::run(['ethtool', '--show-eee', $if], 5, true)) !== null && preg_match('/EEE status:\s*(\w+)/', $e, $m)) {
                $nic['eee'] = $m[1] === 'enabled';
            }
            if (($b = Sys::run(['ethtool', $if], 5, true)) !== null) {
                if (preg_match('/^\s*Wake-on:\s*(\w+)/m', $b, $m)) {
                    $nic['wol'] = $m[1];
                }
                if (preg_match('/Supports Wake-on:\s*(\w+)/', $b, $m)) {
                    $nic['wolModes'] = $m[1];
                }
            }
            $res[] = $nic;
        }
        return $res;
    }

    /**
     * USB topology with protection classification. A device is protected if it is
     * mass storage, HID, a UPS, a KVM, or a hub on the path to mass storage.
     */
    public static function usb(): array
    {
        $devs = [];
        foreach (Sys::glob('/sys/bus/usb/devices/*') as $d) {
            $id = basename($d);
            if (!preg_match('/^(usb\d+|\d+-[\d.]+)$/', $id) || !Sys::exists("$d/idVendor")) {
                continue;
            }
            $ifs = [];
            foreach (Sys::glob("$d/$id:*") as $i) {
                $ifs[] = ['class' => Sys::read("$i/bInterfaceClass"), 'driver' => Sys::linkName("$i/driver")];
            }
            $real = Sys::realpath($d) ?? '';
            $root = preg_match('/^usb\d+$/', $id) === 1;
            $devs[$id] = [
                'id'       => $id,
                'vid'      => Sys::read("$d/idVendor"),
                'pid'      => Sys::read("$d/idProduct"),
                'vendor'   => self::clean(Sys::read("$d/manufacturer")),
                'product'  => self::clean(Sys::read("$d/product")),
                'speed'    => Sys::readInt("$d/speed"),
                'control'  => Sys::read("$d/power/control"),
                'root'     => $root,
                'parent'   => $root ? null : self::usbParent($id),
                'pci'      => preg_match('#/([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-9a-f])/usb\d+#', $real, $m) ? $m[1] : null,
                'classes'  => self::usbClasses($ifs, Sys::read("$d/bDeviceClass")),
                'drivers'  => array_values(array_unique(array_filter(array_column($ifs, 'driver')))),
                'roles'    => [],
                'protected' => false,
            ];
        }

        foreach ($devs as $id => &$dev) {
            $name = strtolower(($dev['vendor'] ?? '') . ' ' . ($dev['product'] ?? ''));
            $drv = $dev['drivers'];
            if (in_array('storage', $dev['classes'], true) || array_intersect($drv, ['usb-storage', 'uas'])) {
                $dev['roles'][] = 'storage';
            }
            if ($dev['vid'] === '051d' || preg_match('/\b(ups|uninterruptible|back-ups|smart-ups)\b/', $name)) {
                $dev['roles'][] = 'ups';
            } elseif (in_array('hid', $dev['classes'], true)) {
                $dev['roles'][] = 'hid';
            }
            if (str_contains($name, 'kvm')) {
                $dev['roles'][] = 'kvm';
            }
        }
        unset($dev);

        // Hubs (and root hubs) above storage must stay awake as well.
        $storageControllers = [];
        foreach ($devs as $id => $dev) {
            if (!in_array('storage', $dev['roles'], true)) {
                continue;
            }
            if ($dev['pci'] !== null) {
                $storageControllers[$dev['pci']] = true;
            }
            for ($p = $dev['parent']; $p !== null && isset($devs[$p]); $p = $devs[$p]['parent']) {
                if (!in_array('storage-path', $devs[$p]['roles'], true)) {
                    $devs[$p]['roles'][] = 'storage-path';
                }
            }
        }
        foreach ($devs as &$dev) {
            $dev['protected'] = $dev['roles'] !== [];
            // Protected devices must be pinned to "on"; root hubs are covered by the controller rule.
            $dev['violation'] = $dev['protected'] && !$dev['root'] && $dev['control'] !== 'on';
        }
        unset($dev);

        uksort($devs, 'strnatcmp');
        return [
            'devices'            => array_values($devs),
            'storageControllers' => array_keys($storageControllers),
        ];
    }

    private static function usbParent(string $id): ?string
    {
        // 2-1.3 -> 2-1 ; 2-1 -> usb2
        if (preg_match('/^(\d+-[\d.]+)\.\d+$/', $id, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d+)-\d+$/', $id, $m)) {
            return 'usb' . $m[1];
        }
        return null;
    }

    private static function usbClasses(array $ifs, ?string $devClass): array
    {
        $map = ['08' => 'storage', '03' => 'hid', '09' => 'hub', '0e' => 'video', '01' => 'audio', 'e0' => 'wireless', '02' => 'comm', '0a' => 'comm'];
        $res = [];
        foreach (array_merge([$devClass], array_column($ifs, 'class')) as $c) {
            if ($c !== null && isset($map[$c])) {
                $res[] = $map[$c];
            }
        }
        return array_values(array_unique($res));
    }

    private static function clean(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = trim(preg_replace('/[^\P{C}]+/u', '', mb_convert_encoding($s, 'UTF-8', 'UTF-8')) ?? '');
        return $s === '' ? null : $s;
    }

    /**
     * ASPM: the policy file exists even if firmware withholds control. Per-link
     * `link/l1_aspm` attributes only appear for links the OS actually manages.
     */
    public static function pcie(): array
    {
        [$policy, $opts] = Sys::bracketed(Sys::read('/sys/module/pcie_aspm/parameters/policy'));
        $links = Sys::glob('/sys/bus/pci/devices/*/link/l1_aspm');
        $dmesg = Sys::run(['dmesg']) ?? '';
        $fadtDenied = (bool)preg_match('/FADT indicates ASPM is unsupported/i', $dmesg);
        $oscDenied = (bool)preg_match('/_OSC: OS (?:does not|doesn\'t) control.*ASPM|not requesting control.*ASPM|ASPM: .*disabled/i', $dmesg);

        $lnk = self::lnkCtl();
        $devices = [];
        foreach (Sys::glob('/sys/bus/pci/devices/*') as $d) {
            $pci = basename($d);
            $devices[] = [
                'pci'     => $pci,
                'class'   => Sys::read("$d/class"),
                'vendor'  => Sys::read("$d/vendor"),
                'device'  => Sys::read("$d/device"),
                'driver'  => Sys::linkName("$d/driver"),
                'control' => Sys::read("$d/power/control"),
                'l1'      => Sys::readInt("$d/link/l1_aspm"),
                'l1_2'    => Sys::readInt("$d/link/l1_2_aspm"),
                'aspm'    => $lnk[$pci] ?? null,
            ];
        }
        $withLink = array_filter($devices, static fn($d) => $d['aspm'] !== null);
        $l1 = array_filter($withLink, static fn($d) => str_contains($d['aspm'], 'L1'));
        return [
            'policy'     => $policy,
            'policies'   => $opts,
            'osControl'  => $links !== [] && !$fadtDenied,
            'fadtDenied' => $fadtDenied,
            'oscDenied'  => $oscDenied,
            'links'      => count($withLink),
            'linksL1'    => count($l1),
            'devices'    => $devices,
        ];
    }

    /**
     * Effective ASPM state per PCI function from the LnkCtl register, which is
     * what actually counts, regardless of who (firmware or OS) configured it.
     *
     * @return array<string,string> pci => "L1" | "L0s L1" | "Disabled"
     */
    private static function lnkCtl(): array
    {
        $out = Sys::run(['lspci', '-D', '-vv'], 10);
        if ($out === null) {
            return [];
        }
        $res = [];
        $cur = null;
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-9a-f])\s/', $line, $m)) {
                $cur = $m[1];
            } elseif ($cur !== null && preg_match('/LnkCtl:\s+ASPM ([^;]+);/', $line, $m)) {
                $res[$cur] = trim(str_replace(['Enabled', ' and '], ['', ' '], $m[1]));
            }
        }
        return $res;
    }

    public static function sata(): array
    {
        $res = [];
        foreach (Sys::glob('/sys/class/scsi_host/host*/link_power_management_policy') as $f) {
            $res[] = ['host' => basename(dirname($f)), 'policy' => Sys::read($f)];
        }
        return $res;
    }

    public static function nvme(): array
    {
        $res = [];
        foreach (Sys::glob('/sys/class/nvme/nvme*') as $n) {
            $res[] = [
                'name'   => basename($n),
                'model'  => Sys::read("$n/model"),
                'pci'    => Sys::linkName("$n/device"),
            ];
        }
        return [
            'controllers' => $res,
            'apstMaxLatency' => Sys::readInt('/sys/module/nvme_core/parameters/default_ps_max_latency_us'),
        ];
    }

    public static function zfs(): array
    {
        if (!Sys::exists('/sys/module/zfs')) {
            return ['loaded' => false, 'pools' => [], 'arc' => null];
        }
        $arc = [];
        foreach (explode("\n", Sys::readRaw('/proc/spl/kstat/zfs/arcstats') ?? '') as $line) {
            if (preg_match('/^(size|c_max|c_min|c)\s+\d+\s+(\d+)$/', trim($line), $m)) {
                $arc[$m[1]] = (int)$m[2];
            }
        }
        $pools = [];
        $out = Sys::run(['zpool', 'list', '-Hp', '-o', 'name,size,alloc,frag,cap,health']);
        foreach (array_filter(explode("\n", $out ?? '')) as $line) {
            $c = explode("\t", $line);
            if (count($c) < 6) {
                continue;
            }
            $pools[$c[0]] = [
                'name' => $c[0], 'size' => (int)$c[1], 'alloc' => (int)$c[2],
                'frag' => is_numeric($c[3]) ? (int)$c[3] : null, 'cap' => (int)$c[4], 'health' => $c[5],
                'autotrim' => null,
            ];
        }
        if ($pools !== [] && ($at = Sys::run(['zpool', 'get', '-Hp', '-o', 'name,value', 'autotrim'])) !== null) {
            foreach (explode("\n", $at) as $line) {
                $c = explode("\t", $line);
                if (count($c) === 2 && isset($pools[$c[0]])) {
                    $pools[$c[0]]['autotrim'] = $c[1];
                }
            }
        }
        return [
            'loaded'   => true,
            'pools'    => array_values($pools),
            'arc'      => $arc + [
                'paramMax' => Sys::readInt('/sys/module/zfs/parameters/zfs_arc_max'),
                'paramMin' => Sys::readInt('/sys/module/zfs/parameters/zfs_arc_min'),
            ],
        ];
    }

    public static function containers(): array
    {
        $out = Sys::run(['docker', 'ps', '--format', '{{.Image}}'], 8);
        if ($out === null) {
            return ['running' => null, 'images' => []];
        }
        $images = array_values(array_filter(explode("\n", $out)));
        return ['running' => count($images), 'images' => $images];
    }
}
