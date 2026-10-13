<?php

use Illuminate\Support\Facades\Artisan;
use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Tests\BootsTestApplication;
use Tey\LaravelDDD\Tests\Support\DiscoveryCacheFixture;

uses(BootsTestApplication::class);

beforeEach(function () {
    $this->commands = [
        'application:sync' => 'Application\Commands\ApplicationSync',
        'invoice:deliver' => 'Domain\Invoicing\Commands\InvoiceDeliver',
        'log:prune' => 'Infrastructure\Commands\LogPrune',
    ];

    $this->setupTestApplication();

    DomainCache::clear();
    Artisan::call('ddd:clear');
});

afterEach(function () {
    DomainCache::clear();
    Artisan::call('ddd:clear');
});

describe('when ddd.autoload.commands = false', function () {
    it('skips handling commands', function () {
        config()->set('ddd.autoload.commands', false);

        $mock = AutoloadManager::partialMock();
        $mock->shouldNotReceive('handleCommands');
        $mock->run();

        expect($mock->getRegisteredCommands())->toBeEmpty();
    });
});

describe('when ddd.autoload.commands = true', function () {
    it('registers the commands', function () {
        config()->set('ddd.autoload.commands', true);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        $expected = array_values($this->commands);
        $registered = array_values($mock->getRegisteredCommands());
        expect($expected)->each(fn ($item) => $item->toBeIn($registered));
        expect($registered)->toHaveCount(count($expected));
    });
});

describe('caching', function () {
    it('remembers the last cached state', function () {
        config()->set('ddd.autoload.commands', true);

        DiscoveryCacheFixture::write(['commands' => []]);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        $registered = array_values($mock->getRegisteredCommands());
        expect($registered)->toHaveCount(0);
    });

    it('can bust the cache', function () {
        DiscoveryCacheFixture::write(['commands' => []]);
        DomainCache::clear();

        config()->set('ddd.autoload.commands', true);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        $expected = array_values($this->commands);
        $registered = array_values($mock->getRegisteredCommands());
        expect($expected)->each(fn ($item) => $item->toBeIn($registered));
        expect($registered)->toHaveCount(count($expected));
    });
});
