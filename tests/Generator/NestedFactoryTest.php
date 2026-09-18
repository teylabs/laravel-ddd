<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('declares the namespace of its nested factory file', function ($name, $options) {
    config()->set('ddd.domain_path', 'src/Domain');
    config()->set('ddd.domain_namespace', 'Domain');
    config()->set('ddd.namespaces.factory', 'Database\\Factories');

    expect(Artisan::call('ddd:factory', ['name' => 'Invoicing:'.$name, ...$options]))->toBe(0);

    $path = 'src/Domain/Invoicing/Database/Factories/Billing/LedgerFactory.php';
    expect(file_exists(base_path($path)))->toBeTrue();
    expect(file_get_contents(base_path($path)))
        ->toContain('namespace Domain\\Invoicing\\Database\\Factories\\Billing;')
        ->toContain('class LedgerFactory extends Factory')
        ->toContain('use Domain\\Invoicing\\Models\\Ledger;')
        ->toContain('protected $model = Ledger::class;');
    assertParses($path);
})->with([
    'implicit suffix and model' => ['Billing/Ledger', []],
    'explicit suffix and model' => ['Billing/LedgerFactory', ['--model' => 'Ledger']],
]);

it('uses the qualified factory namespace with custom configuration and a published stub', function () {
    config()->set('ddd.domain_path', 'src/Domain');
    config()->set('ddd.domain_namespace', 'Domain');
    config()->set('ddd.namespaces.factory', 'Testing\\Factories');
    File::ensureDirectoryExists(base_path('stubs/ddd'));
    file_put_contents(base_path('stubs/ddd/factory.stub'), file_get_contents(__DIR__.'/../../stubs/factory.stub')."\n// Published factory stub\n");

    expect(Artisan::call('ddd:factory', ['name' => 'Invoicing:Billing/Receipt']))->toBe(0);
    $path = 'src/Domain/Invoicing/Testing/Factories/Billing/ReceiptFactory.php';
    expect(file_get_contents(base_path($path)))
        ->toContain('namespace Domain\\Invoicing\\Testing\\Factories\\Billing;')
        ->toContain('class ReceiptFactory extends Factory')
        ->toContain('use Domain\\Invoicing\\Models\\Receipt;')
        ->toContain('// Published factory stub');
    assertParses($path);
});
