<?php

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Events\DiscoverEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Tests\BootsTestApplication;

// Framework compatibility contract for runtime discovery (audit F19 #4).
//
// The package hooks discovery through three process-global framework
// extension points — Gate::guessPolicyNamesUsing, Factory::guessFactoryNamesUsing
// and DiscoverEvents::guessClassNamesUsing. The autoload tests already cover
// what those resolve to, including the non-domain fallbacks. What they do not
// cover is the borrowing itself: global state that the package takes over has
// to be handed back, or the package quietly breaks whatever the consumer (or
// another package) had registered.

uses(BootsTestApplication::class);

beforeEach(function () {
    $this->setupTestApplication();

    // These tests install sentinel callbacks into process-global framework
    // state. Capture whatever was there first so the sentinels cannot leak into
    // any test that runs afterwards — including the rest of this file, which
    // runs in a random order.
    $this->originalGuessClassNamesCallback = DiscoverEvents::$guessClassNamesUsingCallback;

    DomainCache::clear();
    Artisan::call('ddd:clear');
});

afterEach(function () {
    DiscoverEvents::$guessClassNamesUsingCallback = $this->originalGuessClassNamesCallback;

    DomainCache::clear();
    Artisan::call('ddd:clear');
});

it('never borrows the event discovery callback', function () {
    $calls = 0;
    $sentinel = function (SplFileInfo $file, string $basePath) use (&$calls) {
        $calls++;

        return 'Sentinel\\Placeholder';
    };

    DiscoverEvents::guessClassNamesUsing($sentinel);
    config()->set('ddd.autoload.listeners', true);

    $discovered = (new AutoloadManager)->discoverListeners();

    // v4 resolves listener classes through mod (the compiled ddd layout), so the
    // framework's process-global class-name callback is neither replaced nor used.
    expect(DiscoverEvents::$guessClassNamesUsingCallback)->toBe($sentinel)
        ->and($calls)->toBe(0)
        ->and($discovered)->toHaveKeys(['listeners', 'subscribers']);
});

it('leaves no event discovery callback behind when none was set', function () {
    DiscoverEvents::$guessClassNamesUsingCallback = null;
    config()->set('ddd.autoload.listeners', true);

    (new AutoloadManager)->discoverListeners();

    expect(DiscoverEvents::$guessClassNamesUsingCallback)->toBeNull();
});

it('resolves domain policies and factories through the framework, not just the manifest', function () {
    config()->set('ddd.autoload.policies', true);
    config()->set('ddd.autoload.factories', true);

    (new AutoloadManager)->run();

    // Gate::getPolicyFor and Model::factory() are the calls a consumer actually
    // makes. Asserting the package's own resolved-map would pass even if the
    // framework stopped consulting the registered callback.
    expect(Gate::getPolicyFor('Domain\Invoicing\Models\Invoice'))
        ->toBeInstanceOf('Domain\Invoicing\Policies\InvoicePolicy');

    expect(Factory::resolveFactoryName('Domain\Invoicing\Models\Invoice'))
        ->toBe('Domain\Invoicing\Database\Factories\InvoiceFactory');

    expect('Domain\Invoicing\Models\Invoice'::factory())
        ->toBeInstanceOf('Domain\Invoicing\Database\Factories\InvoiceFactory');
});

it('keeps the native resolution conventions for non-domain classes', function () {
    config()->set('ddd.autoload.policies', true);
    config()->set('ddd.autoload.factories', true);

    (new AutoloadManager)->run();

    // The package copies the framework's own fallback conventions rather than
    // delegating to them, so a change upstream shows up here first.
    expect(Gate::getPolicyFor('App\Models\Post'))->toBeInstanceOf('App\Policies\PostPolicy');

    expect(Factory::resolveFactoryName('App\Models\Post'))
        ->toBe('Database\Factories\PostFactory');
});
