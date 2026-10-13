<?php

use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Tests\BootsTestApplication;
use Tey\LaravelDDD\Tests\Fixtures\Enums\Feature;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\Inventory;

uses(BootsTestApplication::class);

/**
 * Both v4 cache files are present (or absent).
 */
function expectDddCache(bool $present): void
{
    expect(DomainCache::has('discovery'))->toBe($present)
        ->and(DomainCache::has('migration-paths'))->toBe($present);
}

function cachedInventory(string $key): Inventory
{
    return Inventory::fromArray(DomainCache::get($key)['inventory']);
}

beforeEach(function () {
    $this->setupTestApplication();

    DomainCache::clear();

    $this->originalComposerContents = file_get_contents(base_path('composer.json'));
});

afterEach(function () {
    DomainCache::clear();

    file_put_contents(base_path('composer.json'), $this->originalComposerContents);

    $this->artisan('optimize:clear')->assertSuccessful()->execute();
});

it('can optimize discovered domain providers, commands, listeners, migrations', function () {
    expectDddCache(false);

    $this
        ->artisan('ddd:optimize')
        ->expectsOutputToContain('Caching DDD providers, commands, listeners, migration paths.')
        ->expectsOutputToContain('domain discovery')
        ->expectsOutputToContain('domain migration paths')
        ->execute();

    $discovery = cachedInventory('discovery');

    expect($discovery->classes(DiscoveryType::Provider))
        ->toContain('Domain\Invoicing\Providers\InvoiceServiceProvider');

    expect($discovery->classes(DiscoveryType::Command))
        ->toContain('Domain\Invoicing\Commands\InvoiceDeliver');

    expect($discovery->ofType(DiscoveryType::Listener))->toBeArray();

    $paths = collect(cachedInventory('migration-paths')->directories('domain-migration'))->join("\n");

    expect($paths)->toContain('src/Domain/Invoicing/Database/Migrations');
});

it('ignores and clears v3 cache files', function () {
    DomainCache::set('domain-providers', ['Domain\\Gone\\Provider']);

    $this->artisan('ddd:optimize')->assertSuccessful()->execute();
    $this->artisan('ddd:clear')->assertSuccessful()->execute();

    expect(DomainCache::has('domain-providers'))->toBeFalse();
    expectDddCache(false);
});

it('can clear the cache', function () {
    $this->artisan('ddd:optimize')->assertSuccessful()->execute();

    expectDddCache(true);

    $this
        ->artisan('ddd:clear')
        ->expectsOutputToContain('Domain cache cleared successfully.')
        ->execute();

    expectDddCache(false);
});

it('is cleared by laravel cache clearing', function () {
    expectDddCache(false);

    $this->artisan('ddd:optimize')->assertSuccessful()->execute();

    expectDddCache(true);

    $this->artisan('cache:clear')->assertSuccessful()->execute();

    expectDddCache(false);
});

describe('laravel optimize', function () {
    test('optimize will include ddd:optimize', function () {
        expectDddCache(false);

        $this->artisan('optimize')->assertSuccessful()->execute();

        expectDddCache(true);

        $this->artisan('optimize:clear')->assertSuccessful()->execute();
    });

    test('optimize:clear will clear ddd cache', function () {
        $this->artisan('ddd:optimize')->assertSuccessful()->execute();

        expectDddCache(true);

        $this->artisan('optimize:clear')->assertSuccessful()->execute();

        expectDddCache(false);

        $this->artisan('optimize:clear')->assertSuccessful()->execute();
    });
})->skipOnLaravelVersionsBelow(Feature::LaravelPackageOptimizeCommands->value);
