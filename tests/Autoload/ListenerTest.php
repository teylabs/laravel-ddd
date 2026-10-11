<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Tests\BootsTestApplication;
use Tey\LaravelDDD\Tests\Support\DiscoveryCacheFixture;

uses(BootsTestApplication::class);

beforeEach(function () {
    $this->setupTestApplication();

    DomainCache::clear();
    Artisan::call('ddd:clear');
});

afterEach(function () {
    DomainCache::clear();
    Artisan::call('ddd:clear');
});

describe('when ddd.autoload.listeners = false', function () {
    it('skips handling listeners', function () {
        config()->set('ddd.autoload.listeners', false);

        $mock = AutoloadManager::partialMock();
        $mock->shouldNotReceive('handleListeners');
        $mock->run();
    });

    it('does not register the listeners', function () {
        config()->set('ddd.autoload.listeners', false);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        expect($mock->getRegisteredListeners())->toBeEmpty()
            ->and($mock->getRegisteredSubscribers())->toBeEmpty();
    });
});

describe('when ddd.autoload.listeners = true', function () {
    it('handles the listeners', function () {
        config()->set('ddd.autoload.listeners', true);

        $mock = AutoloadManager::partialMock();
        $mock->shouldReceive('handleListeners')->once();
        $mock->run();
    });

    it('discovers listeners with handle method', function () {
        config()->set('ddd.autoload.listeners', true);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        $listeners = $mock->getRegisteredListeners();

        expect($listeners)->toHaveKey('Domain\Invoicing\Events\InvoiceCreated')
            ->and($listeners['Domain\Invoicing\Events\InvoiceCreated'])->toContain('Domain\Invoicing\Listeners\SendInvoiceNotification');
    });

    it('discovers event subscribers', function () {
        config()->set('ddd.autoload.listeners', true);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        $subscribers = $mock->getRegisteredSubscribers();

        expect($subscribers)->toHaveKey('Domain\Invoicing\Listeners\InvoiceEventSubscriber');
    });

    it('registers listeners with Event facade', function () {
        Event::fake();

        config()->set('ddd.autoload.listeners', true);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        Event::assertListening(
            'Domain\Invoicing\Events\InvoiceCreated',
            'Domain\Invoicing\Listeners\SendInvoiceNotification'
        );
    });

    it('registers subscribers with Event facade', function () {
        config()->set('ddd.autoload.listeners', true);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        $subscribers = $mock->getRegisteredSubscribers();

        expect($subscribers)->not->toBeEmpty();
    });
});

describe('caching', function () {
    it('remembers the last cached state', function () {
        config()->set('ddd.autoload.listeners', true);

        DiscoveryCacheFixture::write(['listeners' => ['listeners' => [], 'subscribers' => []]]);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        expect(is_file(DomainCache::path('discovery')))->toBeTrue();

        $listeners = $mock->getRegisteredListeners();
        expect($listeners)->toHaveCount(0);
    });

    it('can bust the cache', function () {
        DiscoveryCacheFixture::write(['listeners' => ['listeners' => [], 'subscribers' => []]]);
        DomainCache::clear();

        config()->set('ddd.autoload.listeners', true);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        $listeners = $mock->getRegisteredListeners();
        expect($listeners)->not->toBeEmpty();
    });
});
