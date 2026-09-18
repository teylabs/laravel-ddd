<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Tests\BootsTestApplication;

uses(BootsTestApplication::class);

beforeEach(function () {
    $this->policies = [
        'Domain\Invoicing\Models\Invoice' => 'Domain\Invoicing\Policies\InvoicePolicy',
        'Infrastructure\Models\AppSession' => 'Infrastructure\Policies\AppSessionPolicy',
        'Application\Models\Login' => 'Application\Policies\LoginPolicy',
    ];

    $this->setupTestApplication();

    DomainCache::clear();
    Artisan::call('ddd:clear');
});

afterEach(function () {
    DomainCache::clear();
    Artisan::call('ddd:clear');
});

describe('when ddd.autoload.policies = false', function () {
    it('skips handling policies', function () {
        config()->set('ddd.autoload.policies', false);

        $mock = AutoloadManager::partialMock();
        $mock->shouldNotReceive('handlePolicies');
        $mock->run();
    });
});

describe('when ddd.autoload.policies = true', function () {
    it('handles policies', function () {
        config()->set('ddd.autoload.policies', true);

        $mock = AutoloadManager::partialMock();
        $mock->shouldReceive('handlePolicies')->once();
        $mock->run();
    });

    it('resolves policies across domain and conventional layer layouts', function () {
        config()->set('ddd.autoload.policies', true);

        $manager = new AutoloadManager;
        $manager->run();

        foreach ($this->policies as $class => $expectedPolicy) {
            expect(class_exists($class))->toBeTrue();
            expect(Gate::getPolicyFor($class))->toBeInstanceOf($expectedPolicy);
        }

        // Domain resolution is tracked; conventional non-domain naming uses
        // the fallback and must not be mistaken for domain-object resolution.
        expect($manager->getResolvedPolicies())->toBe([
            'Domain\\Invoicing\\Models\\Invoice' => 'Domain\\Invoicing\\Policies\\InvoicePolicy',
        ]);
    });

    it('gracefully falls back for non-ddd policies', function ($class, $expectedPolicy) {
        config()->set('ddd.autoload.policies', true);

        $mock = AutoloadManager::partialMock();
        $mock->run();

        expect(class_exists($class))->toBeTrue();
        expect(Gate::getPolicyFor($class))->toBeInstanceOf($expectedPolicy);
        expect($mock->getResolvedPolicies())->not->toHaveKey($class);
    })->with([
        ['App\Models\Post', 'App\Policies\PostPolicy'],
    ]);
});
