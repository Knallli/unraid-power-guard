<?php
declare(strict_types=1);

namespace PowerGuard;

/**
 * Thin, read-mostly access layer to sysfs/procfs and external commands.
 * `$root` lets tests point all file access at a fixture tree.
 */
final class Sys
{
    public static string $root = '';

    public static function path(string $path): string
    {
        return self::$root . $path;
    }

    public static function exists(string $path): bool
    {
        return file_exists(self::path($path));
    }

    public static function read(string $path): ?string
    {
        $p = self::path($path);
        if (!is_file($p) || !is_readable($p)) {
            return null;
        }
        $v = @file_get_contents($p);
        return $v === false ? null : trim($v);
    }

    public static function readRaw(string $path): ?string
    {
        $p = self::path($path);
        if (!is_file($p) || !is_readable($p)) {
            return null;
        }
        $v = @file_get_contents($p);
        return $v === false ? null : $v;
    }

    /**
     * Write a sysfs/procfs value. Callers must validate path and value first (Engine).
     * Under a fixture root the kernel's side effects are emulated so tests behave like a real system.
     */
    public static function write(string $path, string $value): bool
    {
        if (self::$root !== '') {
            return self::fakeWrite($path, $value);
        }
        return @file_put_contents($path, $value) !== false;
    }

    /** Selected value for "[a] b c" style files, trimmed content otherwise. */
    public static function readValue(string $path): ?string
    {
        $v = self::read($path);
        if ($v !== null && str_contains($v, '[')) {
            return self::bracketed($v)[0];
        }
        return $v === null ? null : preg_replace('/\s+/', ' ', $v);
    }

    public static function log(string $msg): void
    {
        if (self::$root === '') {
            self::run(['logger', '-t', 'power-guard', '--', $msg]);
        }
    }

    private const FAKE_PAIRS = [
        '/proc/sys/vm/dirty_bytes' => '/proc/sys/vm/dirty_ratio',
        '/proc/sys/vm/dirty_ratio' => '/proc/sys/vm/dirty_bytes',
        '/proc/sys/vm/dirty_background_bytes' => '/proc/sys/vm/dirty_background_ratio',
        '/proc/sys/vm/dirty_background_ratio' => '/proc/sys/vm/dirty_background_bytes',
    ];

    private static function fakeWrite(string $path, string $value): bool
    {
        $p = self::path($path);
        if (!is_file($p)) {
            return false;
        }
        $old = (string)file_get_contents($p);
        if (str_contains($old, '[')) {
            [, $opts] = self::bracketed($old);
            if (!in_array($value, $opts, true)) {
                return false; // EINVAL
            }
            $value = implode(' ', array_map(static fn($o) => $o === $value ? "[$o]" : $o, $opts));
        } elseif (isset(self::FAKE_PAIRS[$path])) {
            if ((int)$value === 0 && str_ends_with($path, '_bytes')) {
                return false; // kernel minimum is two pages
            }
            file_put_contents(self::path(self::FAKE_PAIRS[$path]), "0\n");
        }
        return file_put_contents($p, $value . "\n") !== false;
    }

    public static function readInt(string $path): ?int
    {
        $v = self::read($path);
        return ($v !== null && preg_match('/^-?\d+$/', $v)) ? (int)$v : null;
    }

    /** @return list<string> absolute (un-prefixed) paths */
    public static function glob(string $pattern): array
    {
        $res = glob(self::path($pattern)) ?: [];
        $n = strlen(self::$root);
        return array_map(static fn(string $p): string => substr($p, $n), $res);
    }

    public static function realpath(string $path): ?string
    {
        $r = realpath(self::path($path));
        if ($r === false) {
            return null;
        }
        return self::$root !== '' && str_starts_with($r, self::$root) ? substr($r, strlen(self::$root)) : $r;
    }

    public static function linkName(string $path): ?string
    {
        $r = self::realpath($path);
        return $r === null ? null : basename($r);
    }

    /**
     * Run a command without a shell; every argument is escaped.
     * Returns stdout (trimmed) or null if the binary is missing / it failed and $allowFail is false.
     *
     * @param list<string> $argv
     */
    public static function run(array $argv, int $timeout = 5, bool $allowFail = false): ?string
    {
        if (self::$root !== '') {
            return self::fixtureCommand($argv);
        }
        if (!self::which($argv[0])) {
            return null;
        }
        $cmd = 'timeout ' . $timeout . ' ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>/dev/null';
        $out = [];
        $rc = 0;
        exec($cmd, $out, $rc);
        if ($rc !== 0 && !$allowFail) {
            return null;
        }
        return trim(implode("\n", $out));
    }

    public static function which(string $bin): bool
    {
        if (str_contains($bin, '/')) {
            return is_executable($bin);
        }
        foreach (explode(':', getenv('PATH') ?: '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin') as $dir) {
            if ($dir !== '' && is_executable($dir . '/' . $bin)) {
                return true;
            }
        }
        return false;
    }

    /** Tests can drop canned command output into <root>/.cmd/<argv joined by _>. */
    private static function fixtureCommand(array $argv): ?string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', implode('_', $argv));
        $f = self::$root . '/.cmd/' . $name;
        return is_file($f) ? trim((string)file_get_contents($f)) : null;
    }

    /** "[default] performance powersave" -> ['default', ['default','performance','powersave']] */
    public static function bracketed(?string $v): array
    {
        if ($v === null) {
            return [null, []];
        }
        $sel = null;
        $opts = [];
        foreach (preg_split('/\s+/', trim($v)) ?: [] as $tok) {
            if ($tok === '') {
                continue;
            }
            if (preg_match('/^\[(.+)\]$/', $tok, $m)) {
                $sel = $m[1];
                $tok = $m[1];
            }
            $opts[] = $tok;
        }
        return [$sel, $opts];
    }

    /** "0-3,8,10-11" -> [0,1,2,3,8,10,11] */
    public static function cpuList(?string $v): array
    {
        $res = [];
        if ($v === null || $v === '') {
            return $res;
        }
        foreach (explode(',', $v) as $part) {
            if (preg_match('/^(\d+)-(\d+)$/', trim($part), $m)) {
                for ($i = (int)$m[1]; $i <= (int)$m[2]; $i++) {
                    $res[] = $i;
                }
            } elseif (preg_match('/^\d+$/', trim($part))) {
                $res[] = (int)$part;
            }
        }
        return $res;
    }

    /** Collapse distinct per-item values: all equal -> value, else 'mixed'. */
    public static function uniform(array $values): ?string
    {
        $values = array_values(array_unique(array_filter($values, static fn($v) => $v !== null)));
        if ($values === []) {
            return null;
        }
        return count($values) === 1 ? (string)$values[0] : 'mixed';
    }
}
