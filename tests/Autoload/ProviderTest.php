<?php

use Illuminate\Support\Facades\Artisan;
use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Tests\BootsTestApplication;
use Tey\LaravelDDD\Tests\Support\DiscoveryCacheFixture;

uses(BootsTestApplication::class);

beforeEach(function () {
    $this->providers = [
        'Application\Providers\ApplicationServiceProvider',
        'Domain\Invoicing\Providers\InvoiceServiceProvider',
        'Infrastructure\Providers\InfrastructureServiceProvider',
    ];

    $this->setupTestApplication();

    DomainCache::clear();
    Artisan::call('ddd:clear');

    expect(config('ddd.autoload_ignore'))->toEqualCanonicalizing([
        'Tests',
        'Database/Migrations',
    ]);
});

afterEach(function () {
    DomainCache::clear();
    Artisan::call('ddd:clear');
});

describe('when ddd.autoload.providers = false', function () {
    it('skips handling providers', function () {
        config()->set('ddd.autoload.providers', false);

        $mock = AutoloadManager::partialMock();
        $mock->shouldNotReceive('handleProviders');
        $mock->run();
    });

    it('does not register the providers', function () {
        config()->set('ddd.autoload.providers', false);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        expect($mock->getRegisteredProviders())->toBeEmpty();

        expect(fn () => app('invoicing-singleton'))->toThrow(Exception::class);
        expect(fn () => app('application-singleton'))->toThrow(Exception::class);
        expect(fn () => app('infrastructure-singleton'))->toThrow(Exception::class);
    });
});

describe('when ddd.autoload.providers = true', function () {
    it('handles the providers', function () {
        config()->set('ddd.autoload.providers', true);

        $mock = AutoloadManager::partialMock();
        $mock->shouldReceive('handleProviders')->once();
        $mock->run();
    });

    it('registers the providers', function () {
        config()->set('ddd.autoload.providers', true);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        expect(is_file(DomainCache::path('discovery')))->toBeFalse();

        $expected = array_values($this->providers);
        $registered = array_values($mock->getRegisteredProviders());
        expect($expected)->each(fn ($item) => $item->toBeIn($registered));
        expect($registered)->toHaveCount(count($expected));

        expect(app('application-singleton'))->toEqual('application-singleton');
        expect(app('invoicing-singleton'))->toEqual('invoicing-singleton');
        expect(app('infrastructure-singleton'))->toEqual('infrastructure-singleton');
    });
});

describe('caching', function () {
    it('remembers the last cached state', function () {
        config()->set('ddd.autoload.providers', true);

        DiscoveryCacheFixture::write(['providers' => []]);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        expect(is_file(DomainCache::path('discovery')))->toBeTrue();

        $registered = array_values($mock->getRegisteredProviders());
        expect($registered)->toHaveCount(0);
    });

    it('can bust the cache', function () {
        DiscoveryCacheFixture::write(['providers' => []]);
        DomainCache::clear();

        config()->set('ddd.autoload.providers', true);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        $expected = array_values($this->providers);
        $registered = array_values($mock->getRegisteredProviders());
        expect($expected)->each(fn ($item) => $item->toBeIn($registered));
        expect($registered)->toHaveCount(count($expected));

        expect(app('application-singleton'))->toEqual('application-singleton');
        expect(app('invoicing-singleton'))->toEqual('invoicing-singleton');
        expect(app('infrastructure-singleton'))->toEqual('infrastructure-singleton');
    });
});
