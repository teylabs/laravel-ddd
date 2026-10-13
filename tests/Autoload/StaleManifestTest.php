<?php

use Composer\Autoload\ClassLoader;
use Domain\Invoicing\Events\InvoiceTracked;
use Illuminate\Support\Facades\Event;
use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Tests\BootsTestApplication;
use Tey\LaravelDDD\Tests\Support\DiscoveryCacheFixture;

uses(BootsTestApplication::class);

beforeEach(function () {
    $this->setupTestApplication();
    config()->set('ddd.autoload.listeners', true);
    DomainCache::clear();
});

afterEach(fn () => DomainCache::clear());

it('rediscovers stale listeners without rewriting the manifest', function ($entry) {
    $manifest = ['listeners' => [], 'subscribers' => []];
    $manifest['listeners'][InvoiceTracked::class][] = $entry;
    $written = DiscoveryCacheFixture::write(['listeners' => $manifest]);

    (new AutoloadManager)->run();
    Event::dispatch($event = new InvoiceTracked);

    expect($event->calls)->toContain('domain-listener')
        ->and(file_get_contents(DomainCache::path('discovery')))->toBe($written);
})->with([
    'class' => ['Domain\\RemovedListener'],
    'method string' => ['Domain\\RemovedListener@handle'],
    'method array' => [['Domain\\RemovedListener', 'handle']],
    'subscriber' => [['Domain\\RemovedSubscriber', 'handle']],
]);

it('rediscovers stale provider and command inventories', function ($category) {
    DiscoveryCacheFixture::write([$category => ['Domain\\RemovedAsset']]);
    $manager = new AutoloadManager;
    $expected = $category === 'providers' ? $manager->discoverProviders() : $manager->discoverCommands();
    expect($expected)->not->toBeEmpty();

    $manager->boot();

    $actual = $category === 'providers' ? $manager->getRegisteredProviders() : $manager->getRegisteredCommands();
    expect(array_values($actual))->toBe(array_values($expected));
})->with(['providers', 'commands']);

it('keeps a valid empty manifest authoritative', function () {
    DiscoveryCacheFixture::write(['listeners' => ['listeners' => [], 'subscribers' => []]]);
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
        DiscoveryCacheFixture::write(['listeners' => [
            'listeners' => [InvoiceTracked::class => ['Domain\\BrokenListener']],
            'subscribers' => [],
        ]]);
        expect(fn () => (new AutoloadManager)->boot())->toThrow(RuntimeException::class, 'Broken dependency');
    } finally {
        spl_autoload_unregister($loader);
    }
});

it('uses healthy cached inventories without rescanning', function () {
    $source = new AutoloadManager;
    DiscoveryCacheFixture::write(['providers' => $source->discoverProviders(), 'commands' => $source->discoverCommands(), 'listeners' => $source->discoverListeners()]);

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
    DiscoveryCacheFixture::write(['listeners' => [
        'listeners' => [InvoiceTracked::class => [BrokenCachedSubscriber::class]],
        'subscribers' => [BrokenCachedSubscriber::class],
    ]]);
    expect(fn () => (new AutoloadManager)->run())->toThrow(RuntimeException::class, 'Subscriber failed');
});

class BrokenCachedSubscriber
{
    public function handle(InvoiceTracked $event): void {}

    public function subscribe($events): void
    {
        throw new RuntimeException('Subscriber failed');
    }
}

it('recovers a deleted Composer classmap file without rebuilding autoload', function () {
    $directory = sys_get_temp_dir().'/ddd-stale-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $file = $directory.'/RemovedListener.php';
    file_put_contents($file, '<?php namespace Domain; class RemovedMappedListener {}');
    $loader = new ClassLoader($directory);
    $loader->addClassMap(['Domain\\RemovedMappedListener' => $file]);
    $loader->register(true);
    try {
        DiscoveryCacheFixture::write(['listeners' => [
            'listeners' => [InvoiceTracked::class => ['Domain\\RemovedMappedListener']],
            'subscribers' => [],
        ]]);
        unlink($file);

        (new AutoloadManager)->run();
        Event::dispatch($event = new InvoiceTracked);

        expect($event->calls)->toContain('domain-listener')
            ->and($loader->getClassMap()['Domain\\RemovedMappedListener'])->toBe($file);
    } finally {
        $loader->unregister();
        if (is_file($file)) {
            unlink($file);
        }
        rmdir($directory);
    }
});

it('propagates errors inside an existing Composer mapped file', function () {
    $directory = sys_get_temp_dir().'/ddd-broken-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $file = $directory.'/BrokenListener.php';
    file_put_contents($file, '<?php throw new RuntimeException("Mapped file is broken");');
    $loader = new ClassLoader($directory);
    $loader->addClassMap(['Domain\\BrokenMappedListener' => $file]);
    $loader->register(true);
    try {
        DiscoveryCacheFixture::write(['listeners' => [
            'listeners' => [InvoiceTracked::class => ['Domain\\BrokenMappedListener']],
            'subscribers' => [],
        ]]);
        expect(fn () => (new AutoloadManager)->boot())
            ->toThrow(RuntimeException::class, 'Mapped file is broken');
    } finally {
        $loader->unregister();
        unlink($file);
        rmdir($directory);
    }
});

it('rediscovers a cached listener whose handler was removed', function ($listener) {
    $manifest = ['listeners' => [InvoiceTracked::class => [$listener]], 'subscribers' => []];
    $written = DiscoveryCacheFixture::write(['listeners' => $manifest]);
    (new AutoloadManager)->run();
    Event::dispatch($event = new InvoiceTracked);
    expect($event->calls)->toContain('domain-listener')
        ->and(file_get_contents(DomainCache::path('discovery')))->toBe($written);
})->with([
    'method string' => ['Domain\\Invoicing\\Listeners\\TrackedInvoiceListener@handleRemoved'],
    'method array' => [['Domain\\Invoicing\\Listeners\\TrackedInvoiceListener', 'handleRemoved']],
]);

it('rediscovers a subscriber whose subscribe method was removed', function () {
    DiscoveryCacheFixture::write(['listeners' => [
        'listeners' => [InvoiceTracked::class => [['Domain\\Invoicing\\Listeners\\TrackedInvoiceListener', 'subscribe']]],
        'subscribers' => ['Domain\\Invoicing\\Listeners\\TrackedInvoiceListener'],
    ]]);
    (new AutoloadManager)->run();
    Event::dispatch($event = new InvoiceTracked);
    expect($event->calls)->toContain('domain-listener');
});

it('preserves the framework invokable fallback for cached handlers', function () {
    DiscoveryCacheFixture::write(['listeners' => [
        'listeners' => [InvoiceTracked::class => [CachedInvokableListener::class.'@removed']],
        'subscribers' => [],
    ]]);
    (new AutoloadManager)->run();
    Event::dispatch($event = new InvoiceTracked);
    expect($event->calls)->toBe(['cached-invokable']);
});

class CachedInvokableListener
{
    public function __invoke(InvoiceTracked $event): void
    {
        $event->calls[] = 'cached-invokable';
    }
}
