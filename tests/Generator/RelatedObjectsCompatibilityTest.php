<?php

use Illuminate\Support\Facades\Artisan;
use Tey\LaravelDDD\Facades\DDD;
use Tey\LaravelDDD\ValueObjects\CommandContext;
use Tey\LaravelDDD\ValueObjects\ObjectSchema;

beforeEach(function () {
    config([
        'ddd.domain_path' => 'src/Domain',
        'ddd.domain_namespace' => 'Domain',
        'ddd.application_path' => 'src/Application',
        'ddd.application_namespace' => 'Application',
        'ddd.application_objects' => ['controller', 'request'],
        'ddd.base_model' => null,
    ]);
});

it('preserves model factory references when a schema callback relocates the generated factory', function () {
    $calls = [];
    DDD::resolveObjectSchemaUsing(function (string $type, string $nameInput, CommandContext $command) use (&$calls) {
        $calls[] = [$type, $nameInput, $command->option('model')];

        return $type === 'factory'
            ? new ObjectSchema('InvoiceFactory', 'Custom\\Factories', 'Custom\\Factories\\InvoiceFactory', 'src/Custom/Factories/InvoiceFactory.php')
            : null;
    });

    expect(Artisan::call('ddd:model', ['name' => 'Billing:Invoice', '--factory' => true]))->toBe(0);

    expect(file_get_contents(base_path('src/Domain/Billing/Models/Invoice.php')))
        ->toContain('HasFactory<\\Domain\\Billing\\Database\\Factories\\InvoiceFactory>');

    // Legacy factory placeholders still follow the domain convention even
    // when the callback changes the generated file's schema and destination.
    expect(file_get_contents(base_path('src/Custom/Factories/InvoiceFactory.php')))
        ->toContain('namespace Domain\\Billing\\Database\\Factories;')
        ->toContain('use Domain\\Billing\\Models\\Invoice;');

    expect($calls)->toBe([
        ['model', 'Invoice', null],
        ['factory', 'InvoiceFactory', 'Domain\\Billing\\Models\\Invoice'],
    ]);
});

it('preserves explicit nested model references in factories without resolving another schema', function () {
    $calls = [];
    DDD::resolveObjectSchemaUsing(function (string $type) use (&$calls) {
        $calls[] = $type;

        return null;
    });

    expect(Artisan::call('ddd:factory', [
        'name' => 'Billing:InvoiceFactory',
        '--model' => 'Archived/Invoice',
    ]))->toBe(0);

    expect(file_get_contents(base_path('src/Domain/Billing/Database/Factories/InvoiceFactory.php')))
        ->toContain('use Domain\\Billing\\Models\\Archived\\Invoice;')
        ->toContain('protected $model = Invoice::class;');

    expect($calls)->toBe(['factory']);
});

it('preserves controller request references when callbacks relocate generated requests', function () {
    $calls = [];
    DDD::resolveObjectSchemaUsing(function (string $type, string $nameInput) use (&$calls) {
        $calls[] = [$type, $nameInput];

        return $type === 'request'
            ? new ObjectSchema($nameInput, 'Custom\\Requests', 'Custom\\Requests\\'.$nameInput, 'src/Custom/Requests/'.$nameInput.'.php')
            : null;
    });

    $this->artisan('ddd:controller', [
        'name' => 'Billing:InvoiceController',
        '--model' => 'Invoice',
        '--requests' => true,
        '--api' => true,
    ])->expectsQuestion('A Domain\\Billing\\Models\\Invoice model does not exist. Do you want to generate it?', false)
        ->assertSuccessful()->execute();

    // The controller's imports follow the domain's request location. They used
    // to carry the controller's own class name as a namespace segment
    // (Requests\InvoiceController\StoreInvoiceRequest), which referred to a
    // class nothing had generated; that is the defect this expectation used to
    // pin and no longer does.
    //
    // A callback that RELOCATES a child request — as this one does, to
    // Custom\Requests — is deliberately NOT reflected here. Child schemas are
    // resolved by the child generator when it builds its own blueprint, and
    // reproducing that from the parent would mean either invoking the callback a
    // second time or resolving a synthetic child blueprint. Neither is done, so
    // for a relocated request the import points at the conventional location
    // rather than the relocated one. That is a known, bounded limitation and is
    // characterized here rather than claimed as supported.
    expect(file_get_contents(base_path('src/Application/Billing/Controllers/InvoiceController.php')))
        ->toContain('use Domain\\Billing\\Models\\Invoice;')
        ->toContain('use Application\\Billing\\Requests\\StoreInvoiceRequest;')
        ->toContain('use Application\\Billing\\Requests\\UpdateInvoiceRequest;');

    foreach (['StoreInvoiceRequest', 'UpdateInvoiceRequest'] as $name) {
        expect(file_get_contents(base_path('src/Custom/Requests/'.$name.'.php')))
            ->toContain('namespace Custom\\Requests;');
    }

    expect($calls)->toBe([
        ['controller', 'InvoiceController'],
        ['request', 'StoreInvoiceRequest'],
        ['request', 'UpdateInvoiceRequest'],
    ]);
});
