<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tey\LaravelDDD\Tests\BootsTestApplication;

// ddd:controller --requests writes `use` statements for the form requests it
// generates. Those imports and the files themselves come from two different code
// paths: the controller builds the reference from its replacement map, while
// ForwardsToDomainCommands rewrites the nested make:request call that decides
// where the file actually lands.
//
// The existing controller tests assert the request files exist and that the
// controller's method signatures mention them, both of which hold whether or not
// the import resolves. These check each import against the class declared in the
// generated file, and that the controller parses.

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
 * The fully qualified class names a file imports.
 *
 * The optional carriage return matters: a file written with CRLF ends each line
 * "…;\r", and `;$` alone would match none of them.
 */
function importsOf(string $relativePath): array
{
    preg_match_all('/^use\s+([^\s;]+);\r?$/m', file_get_contents(base_path($relativePath)), $matches);

    return $matches[1];
}

/**
 * The fully qualified name a file declares, read from the file rather than
 * computed, so an import can be checked against something that really exists.
 */
function declaredClass(string $relativePath): string
{
    $contents = file_get_contents(base_path($relativePath));

    preg_match('/^namespace\s+([^;]+);\r?$/m', $contents, $namespace);
    preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $contents, $class);

    return trim($namespace[1]).'\\'.$class[1];
}

/**
 * Assert a controller imports exactly the request classes that were generated.
 *
 * Both directions: nothing generated is left unimported, and nothing imported is
 * missing from disk.
 */
function expectRequestImportsToMatch(string $controllerPath, array $requestPaths): void
{
    assertParses($controllerPath);

    foreach ($requestPaths as $requestPath) {
        expect(file_exists(base_path($requestPath)))->toBeTrue("Expecting {$requestPath} to exist");
        assertParses($requestPath);
    }

    $imports = importsOf($controllerPath);
    $declared = array_map('declaredClass', $requestPaths);

    foreach ($declared as $requestClass) {
        expect(in_array($requestClass, $imports, true))->toBeTrue(
            "The controller does not import {$requestClass}, which it generated"
        );
    }

    foreach ($imports as $import) {
        if (str_contains($import, 'Request') && $import !== 'Illuminate\Http\Request') {
            expect(in_array($import, $declared, true))->toBeTrue(
                "The controller imports {$import}, but no generated request declares that class"
            );
        }
    }
}

it('reads imports and declarations from a file written with CRLF line endings', function () {
    // Guards the helpers rather than the generator. A regex anchored with `;$`
    // matches nothing on CRLF, which would make the assertions below pass by
    // examining an empty list instead of failing.
    $path = 'src/Domain/Invoicing/Requests/CrlfProbeRequest.php';

    File::ensureDirectoryExists(base_path('src/Domain/Invoicing/Requests'));
    file_put_contents(base_path($path), implode("\r\n", [
        '<?php',
        '',
        'namespace Domain\Invoicing\Requests;',
        '',
        'use Illuminate\Foundation\Http\FormRequest;',
        '',
        'class CrlfProbeRequest extends FormRequest',
        '{',
        '}',
        '',
    ]));

    expect(importsOf($path))->toBe(['Illuminate\Foundation\Http\FormRequest'])
        ->and(declaredClass($path))->toBe('Domain\Invoicing\Requests\CrlfProbeRequest');
});

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

    expectRequestImportsToMatch($controllerPath, $requestPaths);
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

it('imports request classes from a configured request namespace in another layer', function () {
    // Two things move at once, deliberately: requests leave the application
    // layer for the domain layer, and the type's namespace segment is renamed
    // away from the default. A reference built from the controller's own
    // location cannot coincidentally match either.
    Config::set('ddd.application_objects', ['controller']);
    Config::set('ddd.namespaces.request', 'Http/FormRequests');

    $controllerPath = 'app/Modules/Invoicing/Controllers/PaymentController.php';

    $this->artisan('ddd:controller', [
        'name' => 'PaymentController',
        '--domain' => 'Invoicing',
        '--model' => 'Payment',
        '--requests' => true,
    ])->assertSuccessful()->execute();

    // Exact paths, as in the direct cases. Looping over imports alone would pass
    // if the controller imported no request at all.
    expectRequestImportsToMatch($controllerPath, [
        'src/Domain/Invoicing/Http/FormRequests/StorePaymentRequest.php',
        'src/Domain/Invoicing/Http/FormRequests/UpdatePaymentRequest.php',
    ]);

    expect(importsOf($controllerPath))
        ->toContain('Domain\Invoicing\Http\FormRequests\StorePaymentRequest')
        ->toContain('Domain\Invoicing\Http\FormRequests\UpdatePaymentRequest');
});

it('resolves request references through a published controller stub', function () {
    // A published stub makes buildClass() return early, but the request
    // replacements are applied before that, so the placeholders still have to be
    // filled with the corrected references.
    $stub = <<<'STUB'
<?php

namespace {{ namespace }};

use {{ namespacedRequests }}

class {{ class }}
{
    public function store({{ storeRequest }} $request) {}

    public function update({{ updateRequest }} $request) {}
}
STUB;

    File::ensureDirectoryExists(base_path('stubs/ddd'));
    file_put_contents(base_path('stubs/ddd/controller.model.stub'), $stub);

    $controllerPath = 'app/Modules/Invoicing/Controllers/Billing/StubbedController.php';

    $this->artisan('ddd:controller', [
        'name' => 'Billing/StubbedController',
        '--domain' => 'Invoicing',
        '--model' => 'Invoice',
        '--requests' => true,
    ])->assertSuccessful()->execute();

    expectRequestImportsToMatch($controllerPath, [
        'app/Modules/Invoicing/Requests/Billing/StoreInvoiceRequest.php',
        'app/Modules/Invoicing/Requests/Billing/UpdateInvoiceRequest.php',
    ]);

    // The published stub was used rather than the framework's: its signatures
    // carry the short class names from the placeholders.
    expect(file_get_contents(base_path($controllerPath)))
        ->toContain('public function store(StoreInvoiceRequest $request)')
        ->toContain('public function update(UpdateInvoiceRequest $request)');

    $this->cleanStubs();
});

it('leaves a model-forwarded controller referencing only classes that exist', function () {
    // ddd:model --controller does NOT propagate --requests to the controller it
    // forwards to. That is existing behaviour and is not changed here; this
    // records the consequence.
    $this->artisan('ddd:model', [
        'name' => 'Ledger',
        '--domain' => 'Invoicing',
        '--controller' => true,
        '--requests' => true,
    ])->assertSuccessful()->execute();

    $controllerPath = 'app/Modules/Invoicing/Controllers/LedgerController.php';

    expect(file_exists(base_path($controllerPath)))->toBeTrue();

    assertParses($controllerPath);

    // Stated outright rather than left to a loop that would pass by running zero
    // times: no requests are generated, and the framework Request is imported.
    expect(is_dir(base_path('app/Modules/Invoicing/Requests')))->toBeFalse(
        'ddd:model --controller is not expected to forward --requests; if it now does, this characterization is stale'
    );

    expect(importsOf($controllerPath))->toContain('Illuminate\Http\Request');
});
