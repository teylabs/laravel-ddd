<?php

use Domain\Invoicing\Events\InvoiceTracked;
use Illuminate\Support\Facades\Event;
use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Tests\BootsTestApplication;

uses(BootsTestApplication::class);

beforeEach(function () {
    $this->setupTestApplication();
    config()->set('ddd.autoload.listeners', true);
    DomainCache::clear();
});

afterEach(fn () => DomainCache::clear());

it('rediscovers stale listeners without rewriting the manifest', function ($entry) {
    $manifest = ['listeners' => [], 'subscribers' => []];
    if ($entry === 'subscriber') {
        $manifest['subscribers'][] = 'Domain\\RemovedSubscriber';
    } else {
        $manifest['listeners'][InvoiceTracked::class][] = $entry;
    }
    DomainCache::set('domain-listeners', $manifest);

    (new AutoloadManager)->run();
    Event::dispatch($event = new InvoiceTracked);

    expect($event->calls)->toContain('domain-listener')
        ->and(DomainCache::get('domain-listeners'))->toBe($manifest);
})->with([
    'class' => ['Domain\\RemovedListener'],
    'method string' => ['Domain\\RemovedListener@handle'],
    'method array' => [['Domain\\RemovedListener', 'handle']],
    'subscriber' => ['subscriber'],
]);

it('rediscovers stale provider and command inventories', function ($category) {
    DomainCache::set('domain-'.$category, ['Domain\\RemovedAsset']);
    $manager = new AutoloadManager;
    $expected = $category === 'providers' ? $manager->discoverProviders() : $manager->discoverCommands();
    expect($expected)->not->toBeEmpty();

    $manager->boot();

    $actual = $category === 'providers' ? $manager->getRegisteredProviders() : $manager->getRegisteredCommands();
    expect(array_values($actual))->toBe(array_values($expected));
})->with(['providers', 'commands']);

it('keeps a valid empty manifest authoritative', function () {
    DomainCache::set('domain-listeners', ['listeners' => [], 'subscribers' => []]);
    (new AutoloadManager)->run();
    Event::dispatch($event = new InvoiceTracked);
    expect($event->calls)->not->toContain('domain-listener');
});

it('does not swallow autoload errors while validating the cache', function () {
    $loader = function ($class) {
        if ($class === 'Domain\\BrokenListener') {
            throw new RuntimeException('Broken dependency');
        }
    };
    spl_autoload_register($loader);
    try {
        DomainCache::set('domain-listeners', [
            'listeners' => [InvoiceTracked::class => ['Domain\\BrokenListener']],
            'subscribers' => [],
        ]);
        expect(fn () => (new AutoloadManager)->boot())->toThrow(RuntimeException::class, 'Broken dependency');
    } finally {
        spl_autoload_unregister($loader);
    }
});

it('uses healthy cached inventories without rescanning', function () {
    $source = new AutoloadManager;
    DomainCache::set('domain-providers', $source->discoverProviders());
    DomainCache::set('domain-commands', $source->discoverCommands());
    DomainCache::set('domain-listeners', $source->discoverListeners());

    $manager = new class extends AutoloadManager
    {
        public function discoverProviders(): array
        {
            throw new RuntimeException('Unexpected provider scan');
        }

        public function discoverCommands(): array
        {
            throw new RuntimeException('Unexpected command scan');
        }

        public function discoverListeners(): array
        {
            throw new RuntimeException('Unexpected listener scan');
        }
    };
    $manager->run();
    Event::dispatch($event = new InvoiceTracked);
    expect($event->calls)->toContain('domain-listener');
});

it('does not suppress errors from an existing subscriber', function () {
    DomainCache::set('domain-listeners', [
        'listeners' => [],
        'subscribers' => [BrokenCachedSubscriber::class],
    ]);
    expect(fn () => (new AutoloadManager)->run())->toThrow(RuntimeException::class, 'Subscriber failed');
});

class BrokenCachedSubscriber
{
    public function subscribe($events): void
    {
        throw new RuntimeException('Subscriber failed');
    }
}
