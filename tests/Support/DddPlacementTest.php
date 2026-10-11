<?php

use Tey\LaravelDDD\Support\Domain;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\LaravelDDD\Support\GeneratorBlueprint;
use Tey\LaravelDDD\Support\Layer;
use Tey\LaravelDDD\Support\Layout\DddLayout;
use Tey\LaravelDDD\Support\Layout\DddPlacement;
use Tey\LaravelDDD\Support\Layout\DddReverse;

/*
 * Conventional placement of the v3 façade (Domain::object, Layer, GeneratorBlueprint) through mod.
 */

beforeEach(function () {
    config()->set([
        'ddd.domain_path' => 'src/Domain', 'ddd.domain_namespace' => 'Domain',
        'ddd.application_path' => 'src/Application', 'ddd.application_namespace' => 'Application',
        'ddd.application_objects' => ['controller', 'request'],
        'ddd.layers' => ['Infrastructure' => 'src/Infrastructure'],
    ]);
    app()->forgetInstance(DddLayout::class);
    app()->forgetInstance(DddReverse::class);
    app()->forgetInstance(DddPlacement::class);
});

it('places names the way v3 did, with mod deciding the folders', function (string $type, string $name, bool $absolute, string $fqcn, string $namespace, string $objectName) {
    $object = (new Domain('Billing'))->object($type, $name, $absolute);

    expect($object->fullyQualifiedName)->toBe($fqcn)
        ->and($object->namespace)->toBe($namespace)
        ->and($object->name)->toBe($objectName)
        ->and($object->domain)->toBe('Billing');
})->with([
    'plain' => ['model', 'Invoice', false, 'Domain\Billing\Models\Invoice', 'Domain\Billing\Models', 'Invoice'],
    'nested' => ['model', 'Nested/Invoice', false, 'Domain\Billing\Models\Nested\Invoice', 'Domain\Billing\Models', 'Nested\Invoice'],
    'factory suffix added once' => ['factory', 'Invoice', false, 'Domain\Billing\Database\Factories\InvoiceFactory', 'Domain\Billing\Database\Factories', 'InvoiceFactory'],
    'factory suffix kept' => ['factory', 'InvoiceFactory', false, 'Domain\Billing\Database\Factories\InvoiceFactory', 'Domain\Billing\Database\Factories', 'InvoiceFactory'],
    'absolute at the layer root' => ['class', 'Nested/Probe', true, 'Domain\Billing\Nested\Probe', 'Domain\Billing', 'Nested\Probe'],
    'namespace-relative' => ['model', '\Custom\Invoice', false, 'Domain\Billing\Custom\Invoice', 'Domain\Billing\Custom', 'Invoice'],
    'already qualified (as --model arrives)' => ['model', 'Domain\Billing\Models\Invoice', false, 'Domain\Billing\Models\Invoice', 'Domain\Billing\Models', 'Invoice'],
    'application object' => ['request', 'StoreInvoiceRequest', false, 'Application\Billing\Requests\StoreInvoiceRequest', 'Application\Billing\Requests', 'StoreInvoiceRequest'],
    'unknown type gets a plural studly folder' => ['unknown-kind', 'Probe', false, 'Domain\Billing\UnknownKinds\Probe', 'Domain\Billing\UnknownKinds', 'Probe'],
]);

it('answers Layer and DomainResolver namespace questions from the layout', function () {
    $layer = new Layer('Domain\Billing', 'src/Domain/Billing');

    expect($layer->namespaceFor('model'))->toBe('Domain\Billing\Models')
        ->and($layer->namespaceFor('model', 'Nested\Probe'))->toBe('Domain\Billing\Models\Nested\Probe')
        ->and($layer->path('Domain\Billing\Models\Probe'))->toBe('src/Domain/Billing/Models/Probe.php')
        ->and($layer->path('Models/Probe'))->toBe('src/Domain/Billing/Models/Probe.php')
        ->and(DomainResolver::getDomainObjectNamespace('Billing', 'policy', 'InvoicePolicy'))->toBe('Domain\Billing\Policies\InvoicePolicy')
        ->and(DomainResolver::getDomainObjectNamespace('Infrastructure', 'model'))->toBe('Infrastructure\Models')
        ->and((new Layer('Domain\Billing\Accounts', 'src/Domain/Billing/Accounts'))->namespaceFor('model'))->toBe('Domain\Billing\Accounts\Models');

    // A bare namespace the layout has no root for still gets the conventional folder.
    expect((new Layer('Elsewhere\Billing'))->namespaceFor('model'))->toBe('Elsewhere\Billing\Models');
});

it('builds the blueprint schema from mod and exposes the resolved artifact', function () {
    $blueprint = new GeneratorBlueprint('ddd:model', 'invoice_item', 'Billing');

    expect($blueprint->schema->fullyQualifiedName)->toBe('Domain\Billing\Models\InvoiceItem')
        ->and($blueprint->schema->path)->toBe('src/Domain/Billing/Models/InvoiceItem.php')
        ->and($blueprint->artifact()->fileType->id)->toBe('model')
        ->and($blueprint->artifact()->path())->toBe('src/Domain/Billing/Models/InvoiceItem.php');

    $migration = new GeneratorBlueprint('ddd:migration', 'create_invoices_table', 'Billing');

    expect($migration->schema->namespace)->toBe('Domain\Billing\Database\Migrations')
        ->and($migration->schema->path)->toBe('src/Domain/Billing/Database/Migrations/CreateInvoicesTable.php')
        ->and($migration->getMigrationPath())->toBe('src/Domain/Billing/Database/Migrations')
        ->and($migration->artifact()->class())->toBeNull();
});

it('reverse-maps through mod for the resolver helpers', function () {
    expect(DomainResolver::guessDomainFromClass('Domain\Billing\Accounts\Models\Ledger'))->toBe('Billing')
        ->and(DomainResolver::guessPathFromClass('Domain\Billing\Models\Ledger'))->toBe('src/Domain/Billing/Models/Ledger.php')
        ->and(DomainResolver::guessPathFromClass('Domain\Billing\Support\Helper'))->toBe('src/Domain/Billing/Support/Helper.php')
        ->and(DomainResolver::guessFolderFromClass('Domain\Billing\Models\Ledger'))->toBe('src/Domain/Billing/Models/')
        ->and(DomainResolver::isDomainClass('Domain\Billing\Models\Ledger'))->toBeTrue()
        ->and(DomainResolver::isDomainClass('App\Models\User'))->toBeFalse()
        ->and(DomainResolver::guessDomainFromClass('App\Models\User'))->toBeNull();
});

it('maps classes back the way v3 did when configured folders overlap or a type is requested', function () {
    config()->set('ddd.namespaces', ['first' => 'Models', 'second' => 'Models\Nested', 'model' => 'Models', 'blank' => '']);
    app()->forgetInstance(DddLayout::class);
    app()->forgetInstance(DddReverse::class);
    $reverse = DddReverse::current();

    // The first configured match wins for an untyped lookup (v3 scanned config order), even over a deeper folder.
    expect($reverse->objectFromClass('Domain\Billing\Models\Probe')?->type)->toBe('first')
        ->and($reverse->objectFromClass('Domain\Billing\Models\Nested\Probe')?->type)->toBe('first')
        ->and($reverse->objectFromClass('Domain\Billing\Models\Nested\Probe')?->name)->toBe('Nested\Probe')
        ->and($reverse->objectFromClass('Domain\Billing\Models\Nested\Probe')?->namespace)->toBe('Models')
        // A requested type matches its folder and types the object as requested; a type with another folder does not match.
        ->and($reverse->objectFromClass('Domain\Billing\Models\Probe', 'model')?->type)->toBe('model')
        // A requested type without a configured namespace scans every namespace, first configured match wins (v3).
        ->and($reverse->objectFromClass('Domain\Billing\Models\Probe', 'unknown')?->type)->toBe('first')
        ->and($reverse->objectFromClass('Domain\Billing\Models\Probe', 'unknown')?->namespace)->toBe('Models')
        ->and($reverse->objectFromClass('Domain\Billing\Probe', 'unknown')?->type)->toBe('unknown')
        // A requested type with a configured namespace that the class is not in: v3's root-level description.
        ->and($reverse->objectFromClass('Domain\Billing\Models\Probe', 'second')?->namespace)->toBe('')
        ->and($reverse->objectFromClass('Domain\Billing\Models\Probe', 'second')?->name)->toBe('Models\Probe')
        ->and($reverse->objectFromClass('Domain\Billing\Models\Probe', 'second')?->type)->toBe('second')
        ->and($reverse->objectFromClass('Domain\Billing\Models\Probe', 'second')?->path)->toBe('src/Domain/Billing/Models/Probe.php')
        ->and($reverse->objectFromClass('Domain\Billing\Probe', 'blank')?->type)->toBe('blank')
        ->and($reverse->objectFromClass('Domain\Billing\Probe', 'blank')?->namespace)->toBe('')
        // No type folder, no type.
        ->and($reverse->objectFromClass('Domain\Billing\Probe')?->type)->toBeNull()
        ->and($reverse->objectFromClass('Domain\Billing\AdHoc\Probe')?->type)->toBeNull()
        ->and($reverse->objectFromClass('Domain\Billing\AdHoc\Probe')?->path)->toBe('src/Domain/Billing/AdHoc/Probe.php');
});
