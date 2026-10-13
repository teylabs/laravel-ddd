<?php

namespace Tey\LaravelDDD\Support;

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Foundation\Application;
use WeakMap;

/**
 * Registers discovered commands with Artisan when it starts.
 *
 * The inventory is read when Artisan starts, not when the hook is added: the
 * most recently booted AutoloadManager of the application decides.
 *
 * @internal
 */
final class ArtisanCommands
{
    /** @var WeakMap<Application, AutoloadManager>|null */
    private static ?WeakMap $latest = null;

    public static function booted(Application $app, AutoloadManager $manager): void
    {
        self::$latest ??= new WeakMap;
        self::$latest[$app] = $manager;
    }

    public static function hook(Application $app, AutoloadManager $fallback): void
    {
        (new self)->register($app, $fallback);
    }

    private function register(Application $app, AutoloadManager $fallback): void
    {
        ConsoleApplication::starting(function (ConsoleApplication $artisan) use ($app, $fallback) {
            foreach ((self::$latest[$app] ?? $fallback)->getRegisteredCommands() as $command) {
                $artisan->resolve($command);
            }
        });
    }
}
