<?php

namespace Tey\LaravelDDD\Support;

use Closure;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;

/**
 * Applies discovered entries to Laravel without owning their lifecycle state.
 *
 * @internal
 */
class DomainRegistration
{
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
                Event::listen($event, $listener);
            }
        });
    }

    public function subscribers(array $subscribers): void
    {
        collect($subscribers)->each(fn (string $subscriber) => Event::subscribe($subscriber));
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
