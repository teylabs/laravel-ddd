<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->setupTestApplication();
});

it('keeps empty-namespace errors specific to the DDD configuration', function (string $key, string $command) {
    config()->set($key, '');

    expect(Artisan::call($command, ['name' => 'Probe', '--domain' => 'Billing']))->toBe(1)
        ->and(Artisan::output())->toContain("The {$key} configuration is empty; set it to a namespace such as")
        ->and(File::exists(base_path('src/Domain/Billing/Probe.php')))->toBeFalse();
})->with([
    'domain namespace' => ['ddd.domain_namespace', 'ddd:class'],
    'application namespace' => ['ddd.application_namespace', 'ddd:controller'],
]);
