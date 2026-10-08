<?php

use Illuminate\Support\Facades\Artisan;

it('places a bare --event in the listener\'s domain', function (string $event, string $import, string $type) {
    expect(Artisan::call('ddd:listener', ['name' => 'Billing:SendReceipt', '--event' => $event]))->toBe(0);

    expect(file_get_contents(base_path('src/Domain/Billing/Listeners/SendReceipt.php')))
        ->toContain("use {$import};")
        ->toContain("public function handle({$type} \$event)");
})->with([
    'bare' => ['InvoicePaid', 'Domain\\Billing\\Events\\InvoicePaid', 'InvoicePaid'],
    'nested' => ['Payments/InvoicePaid', 'Domain\\Billing\\Events\\Payments\\InvoicePaid', 'InvoicePaid'],
]);

it('places a bare --event in the listener\'s subdomain', function () {
    expect(Artisan::call('ddd:listener', ['name' => 'Billing.Internal:SendReceipt', '--event' => 'InvoicePaid']))->toBe(0);

    expect(file_get_contents(base_path('src/Domain/Billing/Internal/Listeners/SendReceipt.php')))
        ->toContain('use Domain\\Billing\\Internal\\Events\\InvoicePaid;');
});

it('keeps a fully qualified --event as given', function (string $event, string $import) {
    expect(Artisan::call('ddd:listener', ['name' => 'Billing:SendReceipt', '--event' => $event]))->toBe(0);

    expect(file_get_contents(base_path('src/Domain/Billing/Listeners/SendReceipt.php')))
        ->toContain("use {$import};");
})->with([
    'app event' => ['App\\Events\\InvoicePaid', 'App\\Events\\InvoicePaid'],
    'framework event' => ['Illuminate\\Auth\\Events\\Login', 'Illuminate\\Auth\\Events\\Login'],
    'another domain' => ['Domain\\Shipping\\Events\\Shipped', 'Domain\\Shipping\\Events\\Shipped'],
    'leading backslash' => ['\\Domain\\Shipping\\Events\\Shipped', 'Domain\\Shipping\\Events\\Shipped'],
]);
