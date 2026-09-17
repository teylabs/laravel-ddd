<?php

use Domain\Invoicing\Events\InvoiceTracked;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tey\LaravelDDD\Facades\DDD;
use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Tests\BootsTestApplication;

// Audit F03: provider and command discovery run through AutoloadManager::finder(),
// which applies ddd.autoload_ignore and any custom autoload filter. Listener
// discovery did not — it handed raw paths to the framework, so a listener in an
// ignored folder was still registered and still ran.
//
// These assert on execution rather than on the manifest. A class excluded from
// discovery that nevertheless fires is the failure that matters, and only
// dispatching a real event can tell the two apart.

uses(BootsTestApplication::class);

beforeEach(function () {
    $this->setupTestApplication();

    config()->set('ddd.autoload.listeners', true);

    DomainCache::clear();
    Artisan::call('ddd:clear');
});

afterEach(function () {
    DomainCache::clear();
    Artisan::call('ddd:clear');
});

/**
 * Boot a fresh autoloader and dispatch the tracked domain event, returning the
 * event so the handlers it actually reached can be inspected.
 */
function dispatchTrackedInvoice(?AutoloadManager $manager = null): object
{
    ($manager ?? new AutoloadManager)->run();

    Event::dispatch($event = new InvoiceTracked);

    return $event;
}

it('does not register listeners or subscribers from ignored folders', function () {
    config()->set('ddd.autoload_ignore', ['Ignored']);

    $event = dispatchTrackedInvoice();

    expect($event->calls)->toContain('domain-listener')
        ->and($event->calls)->not->toContain('ignored-listener')
        ->and($event->calls)->not->toContain('ignored-subscriber');
});

it('does not register listeners or subscribers excluded by a custom autoload filter', function () {
    $invoked = false;

    DDD::filterAutoloadPathsUsing(function (SplFileInfo $file) use (&$invoked) {
        $invoked = true;

        // A custom filter replaces the default ignore behaviour outright, so it
        // has to exclude these by name rather than lean on the folder rule.
        return ! in_array($file->getFilename(), [
            'IgnoredInvoiceListener.php',
            'IgnoredInvoiceSubscriber.php',
        ], true);
    });

    $event = dispatchTrackedInvoice();

    expect($invoked)->toBeTrue('Expecting the custom autoload filter to be consulted during listener discovery')
        ->and($event->calls)->toContain('domain-listener')
        ->and($event->calls)->not->toContain('ignored-listener')
        ->and($event->calls)->not->toContain('ignored-subscriber');
});

it('still registers listeners and subscribers that nothing filters out', function () {
    config()->set('ddd.autoload_ignore', []);

    $event = dispatchTrackedInvoice();

    // Guards the other direction: the fix must not quietly drop handlers that
    // were never excluded.
    expect($event->calls)->toContain('domain-listener')
        ->and($event->calls)->toContain('ignored-listener')
        ->and($event->calls)->toContain('ignored-subscriber');
});

it('honours a finder overridden by a subclass', function () {
    config()->set('ddd.autoload_ignore', []);

    $manager = new class extends AutoloadManager
    {
        protected function finder($paths)
        {
            return Finder::create()
                ->files()
                ->in($paths)
                ->notName('IgnoredInvoiceSubscriber.php');
        }
    };

    $event = dispatchTrackedInvoice($manager);

    expect($event->calls)->toContain('domain-listener')
        ->and($event->calls)->toContain('ignored-listener')
        ->and($event->calls)->not->toContain('ignored-subscriber');
});

it('excludes filtered classes from the discovered manifest', function () {
    config()->set('ddd.autoload_ignore', ['Ignored']);

    $discovered = (new AutoloadManager)->discoverListeners();

    $listenerClasses = collect($discovered['listeners'])
        ->flatten(1)
        ->map(fn ($listener) => is_array($listener) ? $listener[0] : $listener)
        ->all();

    expect($listenerClasses)->toContain('Domain\Invoicing\Listeners\TrackedInvoiceListener')
        ->and($listenerClasses)->not->toContain('Domain\Invoicing\Ignored\IgnoredInvoiceListener')
        ->and($discovered['subscribers'])->toContain('Domain\Invoicing\Listeners\InvoiceEventSubscriber')
        ->and($discovered['subscribers'])->not->toContain('Domain\Invoicing\Ignored\IgnoredInvoiceSubscriber');
});

it('writes a filtered manifest when the cache is built', function () {
    config()->set('ddd.autoload_ignore', ['Ignored']);

    // Cache parity: a manifest built now is already filtered. A manifest cached
    // before this fix still holds the unfiltered set and has to be regenerated
    // — the cached path is trusted as written, by design.
    DomainCache::set('domain-listeners', (new AutoloadManager)->discoverListeners());

    $event = dispatchTrackedInvoice();

    expect(DomainCache::has('domain-listeners'))->toBeTrue()
        ->and($event->calls)->toContain('domain-listener')
        ->and($event->calls)->not->toContain('ignored-listener')
        ->and($event->calls)->not->toContain('ignored-subscriber');
});
