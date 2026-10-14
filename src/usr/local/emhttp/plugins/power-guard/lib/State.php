<?php
declare(strict_types=1);

namespace PowerGuard;

/**
 * Persistent config on flash, volatile baseline/journal in RAM.
 * Paths can be redirected with POWER_GUARD_CONFIG_DIR / POWER_GUARD_STATE_DIR (tests).
 */
final class State
{
    public const MODES = ['observe', 'active'];

    public static function configDir(): string
    {
        return getenv('POWER_GUARD_CONFIG_DIR') ?: '/boot/config/plugins/power-guard';
    }

    public static function stateDir(): string
    {
        return getenv('POWER_GUARD_STATE_DIR') ?: '/var/local/power-guard';
    }

    public static function defaults(): array
    {
        return [
            'version' => 1,
            'mode'    => 'observe',
            'profile' => 'efficiency',
            'tweaks'  => new \stdClass(),
        ];
    }

    public static function config(): array
    {
        $raw = @file_get_contents(self::configDir() . '/config.json');
        $cfg = $raw !== false ? json_decode($raw, true) : null;
        $cfg = is_array($cfg) ? $cfg + self::defaults() : self::defaults();
        $cfg['tweaks'] = is_array($cfg['tweaks']) ? $cfg['tweaks'] : [];
        return $cfg;
    }

    /** Validates and stores the user-editable parts of the config. */
    public static function saveConfig(array $patch): array
    {
        $cfg = self::config();
        if (isset($patch['profile'])) {
            if (!in_array($patch['profile'], Registry::PROFILES, true)) {
                throw new \InvalidArgumentException('Unbekanntes Profil');
            }
            $cfg['profile'] = $patch['profile'];
        }
        if (isset($patch['mode'])) {
            if (!in_array($patch['mode'], self::MODES, true)) {
                throw new \InvalidArgumentException('Unbekannter Modus');
            }
            $cfg['mode'] = $patch['mode'];
        }
        if (isset($patch['tweak']) && is_array($patch['tweak'])) {
            $id = (string)($patch['tweak']['id'] ?? '');
            $mode = (string)($patch['tweak']['mode'] ?? 'auto');
            if (!preg_match('/^[a-z0-9_.:-]{1,64}$/i', $id) || !in_array($mode, ['auto', 'custom', 'off'], true)) {
                throw new \InvalidArgumentException('Ungültige Tweak-Einstellung');
            }
            if ($mode === 'auto') {
                unset($cfg['tweaks'][$id]);
            } else {
                $entry = ['mode' => $mode];
                if ($mode === 'custom') {
                    $v = $patch['tweak']['value'] ?? null;
                    if (!is_scalar($v) || trim((string)$v) === '' || strlen((string)$v) > 128 || !preg_match('/^[\w .:\/-]+$/u', (string)$v)) {
                        throw new \InvalidArgumentException('Ungültiger Wert');
                    }
                    $entry['value'] = is_numeric($v) ? $v + 0 : $v;
                }
                $cfg['tweaks'][$id] = $entry;
            }
        }
        self::writeJson(self::configDir() . '/config.json', $cfg);
        return $cfg;
    }

    public static function writeJson(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Kann $dir nicht anlegen");
        }
        if (($data['tweaks'] ?? null) === []) {
            $data['tweaks'] = new \stdClass();
        }
        $tmp = $file . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Kann $file nicht schreiben");
        }
    }
}
