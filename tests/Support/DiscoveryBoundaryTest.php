<?php

use Illuminate\Support\Facades\Event;
use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Tests\BootsTestApplication;

uses(BootsTestApplication::class);

it('can inspect discovery results without registering providers or events', function () {
    $this->setupTestApplication();
    config(['ddd.autoload.providers' => true, 'ddd.autoload.commands' => true, 'ddd.autoload.listeners' => true]);
    $manager = new AutoloadManager;

    Event::partialMock()->shouldNotReceive('listen');
    Event::partialMock()->shouldNotReceive('subscribe');

    expect(app()->bound('invoicing-singleton'))->toBeFalse();

    $providers = $manager->discoverProviders();
    $commands = $manager->discoverCommands();
    $events = $manager->discoverListeners();

    expect($providers)->toContain('Domain\\Invoicing\\Providers\\InvoiceServiceProvider')
        ->and($commands)->toContain('Domain\\Invoicing\\Commands\\InvoiceDeliver')
        ->and($events['listeners']['Domain\\Invoicing\\Events\\InvoiceCreated'])
        ->toContain('Domain\\Invoicing\\Listeners\\SendInvoiceNotification')
        ->and($events['subscribers'])->toContain('Domain\\Invoicing\\Listeners\\InvoiceEventSubscriber')
        ->and(app()->bound('invoicing-singleton'))->toBeFalse()
        ->and($manager->isBooted())->toBeFalse()
        ->and($manager->hasRun())->toBeFalse();
});

it('retains custom finder overrides through the discovery boundary', function () {
    $this->setupTestApplication();
    config(['ddd.autoload.providers' => true, 'ddd.autoload.commands' => true]);

    $manager = new class extends AutoloadManager
    {
        protected function finder($paths)
        {
            return parent::finder($paths)->filter(fn () => false);
        }
    };

    expect($manager->discoverProviders())->toBeEmpty()
        ->and($manager->discoverCommands())->toBeEmpty();
});
