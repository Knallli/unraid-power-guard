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
}
