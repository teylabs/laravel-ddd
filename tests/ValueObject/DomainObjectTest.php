<?php

use Tey\LaravelDDD\Support\DomainResolver;
use Tey\LaravelDDD\Support\Path;
use Tey\LaravelDDD\ValueObjects\DomainObject;

it('can create a domain object from resolvable class names', function (string $class, $domain, $relativeNamespace, $objectName) {
    $domainObject = DomainObject::fromClass($class);

    $expectedPath = Path::join(...array_filter([DomainResolver::domainPath(), $domain, $relativeNamespace, $objectName.'.php'], 'strlen'));

    expect($domainObject)
        ->name->toEqual($objectName)
        ->domain->toEqual($domain)
        ->namespace->toEqual($relativeNamespace)
        ->path->toEqual($expectedPath);
})->with([
    [
        // Full Class Name
        'Domain\Invoicing\Models\Invoice',

        // Domain
        'Invoicing',

        // Object Namespace
        'Models',

        // Object Name
        'Invoice',
    ],

    [
        'Domain\Invoicing\Models\Payment\InvoicePayment',
        'Invoicing',
        'Models',
        'Payment\InvoicePayment',
    ],

    [
        'Domain\Internal\Invoicing\Models\Invoice',
        'Internal\Invoicing',
        'Models',
        'Invoice',
    ],

    [
        'Domain\Internal\Invoicing\Models\Payment\InvoicePayment',
        'Internal\Invoicing',
        'Models',
        'Payment\InvoicePayment',
    ],

    [
        'Domain\Invoicing\AdHoc\Thing',
        'Invoicing',
        '',
        'AdHoc\Thing',
    ],

    [
        'Domain\Invoicing\AdHoc\Nested\Thing',
        'Invoicing',
        '',
        'AdHoc\Nested\Thing',
    ],

    [
        'Domain\Invoicing\InvoicingServiceProvider',
        'Invoicing',
        '',
        'InvoicingServiceProvider',
    ],

    // Ad-hoc objects inside subdomains are not supported for now
    // ['Domain\Internal\Invoicing\AdHoc\Thing', 'Internal\Invoicing', '', 'Adhoc\Thing'],
    // ['Domain\Internal\Invoicing\Deeply\Nested\Adhoc\Thing', 'Internal\Invoicing', '', 'Deeply\Nested\Adhoc\Thing'],
]);

it('cannot create a domain object from unresolvable classes', function (string $class) {
    expect(DomainObject::fromClass($class))->toBeNull();
})->with([
    ['Illuminate\Support\Str'],
    ['NotDomain\Invoicing\Models\InvoicePayment'],
    ['Invoice'],
]);

it('builds root-level object paths without an empty segment', function (string $class, ?string $type, string $path) {
    expect(DomainObject::fromClass($class, $type)->path)->toBe(Path::normalize($path));
})->with([
    'root-level' => ['Domain\Billing\Probe', null, 'src/Domain/Billing/Probe.php'],
    'ad hoc folder' => ['Domain\Billing\AdHoc\Probe', null, 'src/Domain/Billing/AdHoc/Probe.php'],
    'explicit type without a folder' => ['Domain\Billing\Probe', 'blank', 'src/Domain/Billing/Probe.php'],
]);
