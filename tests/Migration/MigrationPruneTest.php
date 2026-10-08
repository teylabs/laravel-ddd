<?php

use Illuminate\Database\Events\MigrationsPruned;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tey\LaravelDDD\Events\DomainMigrationsPruned;
use Tey\LaravelDDD\Listeners\MigrationsPrunedSubscriber;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Support\DomainMigration;
use Tey\LaravelDDD\Support\Path;

it('prunes domain migrations when schema:dump --prune is called', function () {
    $this->setupTestApplication();

    Event::fake([DomainMigrationsPruned::class]);

    $migrationFile = app()->basePath('src/Domain/Invoicing/Database/Migrations/2024_10_14_215911_do_nothing.php');

    expect($migrationFile)->toBeFile();

    Artisan::call('schema:dump', [
        '--prune' => true,
    ]);

    Event::assertDispatched(DomainMigrationsPruned::class, function (DomainMigrationsPruned $event) {
        return Path::normalize($event->path) === Path::normalize(app()->basePath('src/Domain/Invoicing/Database/Migrations'));
    });

    expect($migrationFile)->not->toBeFile();
})->skipOnWindows();

it('prunes and announces each domain migration directory once when subscribed twice', function () {
    $this->setupTestApplication();

    // Cached paths are announced even after the first handler has deleted them.
    DomainCache::set('domain-migration-paths', DomainMigration::discoverPaths());

    Event::subscribe(MigrationsPrunedSubscriber::class);

    $announced = [];

    Event::listen(DomainMigrationsPruned::class, function (DomainMigrationsPruned $event) use (&$announced) {
        $announced[] = Path::normalize($event->path);
    });

    event(new MigrationsPruned(DB::connection(), database_path('schema/testing-schema.sql')));

    expect($announced)->not->toBeEmpty()
        ->and($announced)->toBe(array_values(array_unique($announced)));
});
