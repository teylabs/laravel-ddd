<?php

use Tey\LaravelDDD\LaravelDDDServiceProvider;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Support\DomainMigration;

it('does not register cached migration paths when discovery is disabled', function () {
    $paths = [base_path('src/Domain/Billing/Database/Migrations')];

    DomainCache::set('domain-migration-paths', $paths);
    config(['ddd.autoload.migrations' => false]);

    expect(DomainMigration::paths())->toBe([]);

    (new LaravelDDDServiceProvider($this->app))->packageRegistered();

    expect($this->app['migrator']->paths())->not->toContain($paths[0]);
    expect(DomainCache::get('domain-migration-paths'))->toBe($paths);
});

it('registers cached migration paths when discovery is enabled', function () {
    $paths = [base_path('src/Domain/Billing/Database/Migrations')];

    DomainCache::set('domain-migration-paths', $paths);
    config(['ddd.autoload.migrations' => true]);

    expect(DomainMigration::paths())->toBe($paths);

    (new LaravelDDDServiceProvider($this->app))->packageRegistered();

    expect($this->app['migrator']->paths())->toContain($paths[0]);
});

it('returns no migration paths when discovery is disabled without a manifest', function () {
    DomainCache::forget('domain-migration-paths');
    config(['ddd.autoload.migrations' => false]);

    expect(DomainMigration::paths())->toBe([]);
    expect(DomainCache::has('domain-migration-paths'))->toBeFalse();
});
