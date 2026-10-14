<?php
declare(strict_types=1);

namespace PowerGuard;

/** Shared entry points for CLI and HTTP API. */
final class Service
{
    public static function version(): string
    {
        $v = Sys::read(PLUGIN_DIR . '/VERSION');
        return $v ?? 'dev';
    }

    public static function status(): array
    {
        $hw = Hardware::detect();
        $ctx = new Context($hw, Conflicts::environment());
        $cfg = State::config();
        return [
            'version'   => self::version(),
            'config'    => $cfg,
            'hardware'  => $hw,
            'plan'      => Registry::plan($ctx, $cfg),
            'conflicts' => Conflicts::scan(),
            'categories' => Registry::CATEGORIES,
            'journal'   => State::journalRead(30),
        ];
    }

    public static function plan(?string $profile = null): array
    {
        $cfg = State::config();
        if ($profile !== null) {
            $cfg['profile'] = $profile;
        }
        return Registry::plan(Context::build(), $cfg);
    }

    /** Before/after list for the preview dialog. */
    public static function preview(?array $ids = null): array
    {
        return Engine::changes(Context::build(), State::config(), $ids);
    }

    /** Accepts only well-formed tweak ids. */
    public static function ids(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === 'all') {
            return null;
        }
        $list = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?? explode(',', (string)$raw));
        if (!is_array($list)) {
            throw new \InvalidArgumentException('Ungültige Auswahl');
        }
        $ids = [];
        foreach ($list as $id) {
            if (!is_string($id) || !preg_match('/^[a-z0-9_.:-]{1,64}$/i', $id)) {
                throw new \InvalidArgumentException('Ungültige Tweak-ID');
            }
            $ids[] = $id;
        }
        return $ids;
    }
}
