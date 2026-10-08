<?php

namespace Tey\LaravelDDD\Listeners;

use Illuminate\Database\Events\MigrationsPruned;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Tey\LaravelDDD\Events\DomainMigrationsPruned;
use Tey\LaravelDDD\Support\DomainMigration;
use WeakMap;

class MigrationsPrunedSubscriber
{
    /**
     * Dispatchers this subscriber already listens on.
     *
     * @var WeakMap<Dispatcher, true>|null
     */
    protected static ?WeakMap $subscribed = null;

    public function handle(MigrationsPruned $event): void
    {
        $migrationDirs = DomainMigration::paths();
        $filesystem = new Filesystem;

        foreach ($migrationDirs as $path) {
            $filesystem->deleteDirectory($path, preserve: false);

            event(new DomainMigrationsPruned($event->connection, $path));
        }
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe(Dispatcher $events): void
    {
        // Subscribing again (e.g. a repeated provider boot) must not prune twice.
        static::$subscribed ??= new WeakMap;

        if (isset(static::$subscribed[$events])) {
            return;
        }

        static::$subscribed[$events] = true;

        $events->listen(MigrationsPruned::class, [$this, 'handle']);
    }
}
