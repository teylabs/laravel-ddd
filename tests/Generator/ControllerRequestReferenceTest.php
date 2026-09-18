<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tey\LaravelDDD\Tests\BootsTestApplication;

// ddd:controller --requests writes `use` statements for the form requests it
// generates. Those imports and the files themselves come from two different code
// paths: the controller builds the namespace from its replacement map, while
// ForwardsToDomainCommands rewrites the nested make:request call that decides
// where the file actually lands.
//
// The existing controller tests assert the request files exist and that the
// controller's method signatures mention them. Neither notices when the import
// points somewhere the file is not, so a controller that cannot resolve its own
// request — or cannot even be parsed — passes.

uses(BootsTestApplication::class);

beforeEach(function () {
    $this->cleanSlate();
    $this->setupTestApplication();

    Config::set([
        'ddd.domain_path' => 'src/Domain',
        'ddd.domain_namespace' => 'Domain',
        'ddd.application_path' => 'app/Modules',
        'ddd.application_namespace' => 'App\Modules',
        'ddd.application_objects' => ['controller', 'request'],
    ]);
});

/**
 * The fully qualified class names a generated file imports.
 */
function importsOf(string $relativePath): array
{
    preg_match_all('/^use\s+([^\s;]+);$/m', file_get_contents(base_path($relativePath)), $matches);

    return $matches[1];
}

/**
 * The fully qualified name a generated file declares, read from the file itself
 * rather than computed, so an import can be checked against what really exists.
 */
function declaredClass(string $relativePath): string
{
    $contents = file_get_contents(base_path($relativePath));

    preg_match('/^namespace\s+([^;]+);$/m', $contents, $namespace);
    preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $contents, $class);

    return trim($namespace[1]).'\\'.$class[1];
}

it('imports request classes that exist where the controller says they are', function (
    string $controllerName,
    string $controllerPath,
    array $requestPaths,
) {
    $this->artisan('ddd:controller', [
        'name' => $controllerName,
        '--domain' => 'Invoicing',
        '--model' => 'Invoice',
        '--requests' => true,
    ])->assertSuccessful()->execute();

    // A nested controller name put a raw '/' inside a use statement, so the file
    // did not parse at all. Checking this first gives the real diagnosis rather
    // than a confusing assertion failure further down.
    assertParses($controllerPath);

    foreach ($requestPaths as $requestPath) {
        expect(file_exists(base_path($requestPath)))->toBeTrue("Expecting {$requestPath} to exist");
        assertParses($requestPath);
    }

    $imports = importsOf($controllerPath);
    $declared = array_map('declaredClass', $requestPaths);

    // The point of the test: every request the controller imports must be a
    // class that was actually generated, matched against the declaration in the
    // file rather than against a recomputed expectation.
    foreach ($declared as $requestClass) {
        expect(in_array($requestClass, $imports, true))->toBeTrue(
            "The controller does not import {$requestClass}, which it generated"
        );
    }

    foreach ($imports as $import) {
        if (str_contains($import, 'Request') && ! str_starts_with($import, 'Illuminate\\')) {
            expect(in_array($import, $declared, true))->toBeTrue(
                "The controller imports {$import}, but no generated request declares that class"
            );
        }
    }
})->with([
    'flat controller name' => [
        'InvoiceController',
        'app/Modules/Invoicing/Controllers/InvoiceController.php',
        [
            'app/Modules/Invoicing/Requests/StoreInvoiceRequest.php',
            'app/Modules/Invoicing/Requests/UpdateInvoiceRequest.php',
        ],
    ],
    'nested controller name' => [
        'Billing/InvoiceController',
        'app/Modules/Invoicing/Controllers/Billing/InvoiceController.php',
        [
            'app/Modules/Invoicing/Requests/Billing/StoreInvoiceRequest.php',
            'app/Modules/Invoicing/Requests/Billing/UpdateInvoiceRequest.php',
        ],
    ],
]);

it('leaves a model-forwarded controller referencing only classes that exist', function () {
    // ddd:model --controller does NOT propagate --requests to the controller it
    // forwards to. That is existing behaviour and is not changed here; what
    // matters for this fix is that the controller never references a request
    // class that was not generated. With no requests it must fall back to the
    // framework's Request, which is exactly what a controller without --requests
    // should import.
    $this->artisan('ddd:model', [
        'name' => 'Ledger',
        '--domain' => 'Invoicing',
        '--controller' => true,
        '--requests' => true,
    ])->assertSuccessful()->execute();

    $controllerPath = 'app/Modules/Invoicing/Controllers/LedgerController.php';

    expect(file_exists(base_path($controllerPath)))->toBeTrue();

    assertParses($controllerPath);

    $requestsDirectory = base_path('app/Modules/Invoicing/Requests');

    $declared = is_dir($requestsDirectory)
        ? array_map(
            fn ($file) => declaredClass('app/Modules/Invoicing/Requests/'.$file->getFilename()),
            File::allFiles($requestsDirectory)
        )
        : [];

    foreach (importsOf($controllerPath) as $import) {
        if (! str_contains($import, 'Request')) {
            continue;
        }

        if ($import === 'Illuminate\\Http\\Request') {
            continue;
        }

        expect(in_array($import, $declared, true))->toBeTrue(
            "The controller imports {$import}, but no generated request declares that class"
        );
    }
});

it('imports request classes from a configured request namespace', function () {
    // The request layer is deliberately moved away from the controller's, so an
    // import built from the controller's own location cannot accidentally match.
    Config::set('ddd.application_objects', ['controller']);

    $this->artisan('ddd:controller', [
        'name' => 'PaymentController',
        '--domain' => 'Invoicing',
        '--model' => 'Payment',
        '--requests' => true,
    ])->assertSuccessful()->execute();

    $controllerPath = 'app/Modules/Invoicing/Controllers/PaymentController.php';

    assertParses($controllerPath);

    $requestPaths = collect(File::allFiles(base_path('src/Domain/Invoicing/Requests')))
        ->map(fn ($file) => 'src/Domain/Invoicing/Requests/'.$file->getFilename())
        ->all();

    expect($requestPaths)->not->toBeEmpty('Expecting requests in the domain layer once they are no longer application objects');

    $declared = array_map('declaredClass', $requestPaths);

    foreach (importsOf($controllerPath) as $import) {
        if (str_contains($import, 'Request') && ! str_starts_with($import, 'Illuminate\\')) {
            expect(in_array($import, $declared, true))->toBeTrue(
                "The controller imports {$import}, but no generated request declares that class"
            );
        }
    }
});
