<?php

use Tey\LaravelDDD\LaravelDDDServiceProvider;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Support\DomainMigration;
use Tey\LaravelDDD\Support\Path;
use Tey\LaravelDDD\Tests\Support\DiscoveryCacheFixture;

it('does not register cached migration paths when discovery is disabled', function () {
    $paths = [Path::normalize(base_path('src/Domain/Billing/Database/Migrations'))];

    $written = DiscoveryCacheFixture::writeMigrationPaths(['src/Domain/Billing/Database/Migrations']);
    config(['ddd.autoload.migrations' => false]);

    expect(DomainMigration::paths())->toBe([]);

    (new LaravelDDDServiceProvider($this->app))->packageRegistered();

    expect($this->app['migrator']->paths())->not->toContain($paths[0]);
    expect(file_get_contents(DomainCache::path('migration-paths')))->toBe($written);
});

it('registers cached migration paths when discovery is enabled', function () {
    $paths = [Path::normalize(base_path('src/Domain/Billing/Database/Migrations'))];

    config(['ddd.autoload.migrations' => true]);
    DiscoveryCacheFixture::writeMigrationPaths(['src/Domain/Billing/Database/Migrations']);

    expect(DomainMigration::paths())->toBe($paths);

    (new LaravelDDDServiceProvider($this->app))->packageRegistered();

    expect($this->app['migrator']->paths())->toContain($paths[0]);
});

it('returns no migration paths when discovery is disabled without a manifest', function () {
    DomainCache::forget('migration-paths');
    config(['ddd.autoload.migrations' => false]);

    expect(DomainMigration::paths())->toBe([]);
    expect(DomainCache::has('migration-paths'))->toBeFalse();
});
