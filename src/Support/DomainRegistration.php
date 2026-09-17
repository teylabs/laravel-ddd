<?php

namespace Tey\LaravelDDD\Support;

use ArrayObject;
use Closure;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use WeakMap;

/**
 * Applies discovered entries to Laravel and tracks successful event registrations.
 *
 * @internal
 */
class DomainRegistration
{
    /** @var WeakMap<object, ArrayObject<string, true>>|null */
    private static ?WeakMap $registeredEvents = null;

    public function providers(Application $app, array $providers): void
    {
        foreach ($providers as $provider) {
            $app->register($provider);
        }
    }

    public function listeners(array $listeners): void
    {
        collect($listeners)->each(function (array $eventListeners, string $event) {
            foreach ($eventListeners as $listener) {
                $parts = is_array($listener) ? $listener : [$listener];
                $identity = array_map(fn ($part) => is_object($part)
                    ? ['object', spl_object_id($part)]
                    : ['value', $part], $parts);

                $this->registerOnce('listener:'.serialize([$event, $identity]), fn () => Event::listen($event, $listener));
            }
        });
    }

    public function subscribers(array $subscribers): void
    {
        collect($subscribers)->each(fn (string $subscriber) => $this->registerOnce(
            'subscriber:'.$subscriber, fn () => Event::subscribe($subscriber)
        ));
    }

    private function registerOnce(string $key, Closure $register): void
    {
        $dispatcher = Event::getFacadeRoot();
        $registrations = self::$registeredEvents ??= new WeakMap;

        $registered = $registrations[$dispatcher] ??= new ArrayObject;

        if (isset($registered[$key])) {
            return;
        }

        $register();

        // Keep only scalar identities: registry entries must not retain the
        // dispatcher (including through a closure bound to its application).
        $registered[$key] = true;
    }

    public function commands(Closure $commands): void
    {
        // Resolve the inventory when Artisan starts, not when run() schedules
        // registration. A later boot may replace the manager's shared inventory.
        ConsoleApplication::starting(function (ConsoleApplication $artisan) use ($commands) {
            foreach ($commands() as $command) {
                $artisan->resolve($command);
            }
        });
    }
}
