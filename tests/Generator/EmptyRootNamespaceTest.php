<?php

use Illuminate\Support\Facades\Config;
use Tey\LaravelDDD\Support\Path;

it('refuses to generate into an empty root namespace', function (string $configKey, string $command, string $path) {
    Config::set($configKey, '');

    $this->artisan($command)
        ->expectsOutputToContain("The {$configKey} configuration is empty")
        ->assertFailed()
        ->execute();

    expect(file_exists(base_path($path)))->toBeFalse();
})->with([
    'domain' => ['ddd.domain_namespace', 'ddd:class Billing:ProbeClass', 'src/Domain/Billing/ProbeClass.php'],
    'application' => ['ddd.application_namespace', 'ddd:controller Billing:ProbeController', 'app/Modules/Billing/Controllers/ProbeController.php'],
]);

it('keeps generating into the other roots when one namespace is empty', function () {
    Config::set('ddd.application_namespace', '');

    $this->artisan('ddd:class Billing:ProbeClass')
        ->assertSuccessful()
        ->execute();

    expect(file_get_contents(base_path('src/Domain/Billing/ProbeClass.php')))
        ->toContain('namespace Domain\Billing;')
        ->toContain('class ProbeClass');
});

it('still generates domain migrations when the domain namespace is empty', function () {
    Config::set('ddd.domain_namespace', '');

    $this->artisan('ddd:migration Billing:create_probes_table')
        ->assertSuccessful()
        ->execute();

    expect(glob(base_path(Path::normalize('src/Domain/Billing/'.config('ddd.namespaces.migration')).'/*_create_probes_table.php')))->toHaveCount(1);
});
