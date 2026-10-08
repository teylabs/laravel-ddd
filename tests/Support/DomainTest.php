<?php

use Illuminate\Support\Facades\Config;
use Tey\LaravelDDD\Support\Domain;
use Tey\LaravelDDD\Support\Path;

it('can initialize a domain', function ($domainName, $subdomainName) {
    $domain = new Domain($domainName, $subdomainName);

    expect($domain)
        ->domain->toBe($domainName)
        ->subdomain->toBe($subdomainName);
})->with('domainSubdomain');

it('can extract domain and subdomain from dot or slash separated string', function ($domainName, $subdomainName, $separator) {
    $dotPath = $subdomainName ? "{$domainName}{$separator}{$subdomainName}" : $domainName;
    $domain = new Domain($dotPath);

    expect($domain)
        ->domain->toBe($domainName)
        ->subdomain->toBe($subdomainName);
})->with('domainSubdomain')->with(['.', '\\', '/']);

it('can describe a domain model', function ($domainName, $name, $expectedFQN, $expectedPath) {
    expect((new Domain($domainName))->model($name))
        ->name->toBe($name)
        ->fullyQualifiedName->toBe($expectedFQN)
        ->path->toBe(Path::normalize($expectedPath));
})->with([
    ['Reporting', 'InvoiceReport', 'Domain\\Reporting\\Models\\InvoiceReport', 'src/Domain/Reporting/Models/InvoiceReport.php'],
    ['Reporting', 'ReportingLog', 'Domain\\Reporting\\Models\\ReportingLog', 'src/Domain/Reporting/Models/ReportingLog.php'],
    ['Reporting', 'Reporting\Log', 'Domain\\Reporting\\Models\\Reporting\\Log', 'src/Domain/Reporting/Models/Reporting/Log.php'],
    ['Reporting.Internal', 'InvoiceReport', 'Domain\\Reporting\\Internal\\Models\\InvoiceReport', 'src/Domain/Reporting/Internal/Models/InvoiceReport.php'],
]);

it('can describe a domain factory', function ($domainName, $name, $expectedFQN, $expectedPath) {
    expect((new Domain($domainName))->factory($name))
        ->name->toBe($name)
        ->fullyQualifiedName->toBe($expectedFQN)
        ->path->toBe(Path::normalize($expectedPath));
})->with([
    ['Reporting', 'InvoiceReportFactory', 'Domain\\Reporting\\Database\\Factories\\InvoiceReportFactory', 'src/Domain/Reporting/Database/Factories/InvoiceReportFactory.php'],
    ['Reporting.Internal', 'InvoiceReportFactory', 'Domain\\Reporting\\Internal\\Database\\Factories\\InvoiceReportFactory', 'src/Domain/Reporting/Internal/Database/Factories/InvoiceReportFactory.php'],
]);

it('can describe a data transfer object', function ($domainName, $name, $expectedFQN, $expectedPath) {
    expect((new Domain($domainName))->dataTransferObject($name))
        ->name->toBe($name)
        ->fullyQualifiedName->toBe($expectedFQN)
        ->path->toBe(Path::normalize($expectedPath));
})->with([
    ['Reporting', 'InvoiceData', 'Domain\\Reporting\\Data\\InvoiceData', 'src/Domain/Reporting/Data/InvoiceData.php'],
    ['Reporting.Internal', 'InvoiceData', 'Domain\\Reporting\\Internal\\Data\\InvoiceData', 'src/Domain/Reporting/Internal/Data/InvoiceData.php'],
]);

it('can describe a view model', function ($domainName, $name, $expectedFQN, $expectedPath) {
    expect((new Domain($domainName))->viewModel($name))
        ->name->toBe($name)
        ->fullyQualifiedName->toBe($expectedFQN)
        ->path->toBe(Path::normalize($expectedPath));
})->with([
    ['Reporting', 'InvoiceReportViewModel', 'Domain\\Reporting\\ViewModels\\InvoiceReportViewModel', 'src/Domain/Reporting/ViewModels/InvoiceReportViewModel.php'],
    ['Reporting.Internal', 'InvoiceReportViewModel', 'Domain\\Reporting\\Internal\\ViewModels\\InvoiceReportViewModel', 'src/Domain/Reporting/Internal/ViewModels/InvoiceReportViewModel.php'],
]);

it('can describe a value object', function ($domainName, $name, $expectedFQN, $expectedPath) {
    expect((new Domain($domainName))->valueObject($name))
        ->name->toBe($name)
        ->fullyQualifiedName->toBe($expectedFQN)
        ->path->toBe(Path::normalize($expectedPath));
})->with([
    ['Reporting', 'InvoiceTotal', 'Domain\\Reporting\\ValueObjects\\InvoiceTotal', 'src/Domain/Reporting/ValueObjects/InvoiceTotal.php'],
    ['Reporting.Internal', 'InvoiceTotal', 'Domain\\Reporting\\Internal\\ValueObjects\\InvoiceTotal', 'src/Domain/Reporting/Internal/ValueObjects/InvoiceTotal.php'],
]);

it('can describe an action', function ($domainName, $name, $expectedFQN, $expectedPath) {
    expect((new Domain($domainName))->action($name))
        ->name->toBe($name)
        ->fullyQualifiedName->toBe($expectedFQN)
        ->path->toBe(Path::normalize($expectedPath));
})->with([
    ['Reporting', 'SendInvoiceReport', 'Domain\\Reporting\\Actions\\SendInvoiceReport', 'src/Domain/Reporting/Actions/SendInvoiceReport.php'],
    ['Reporting.Internal', 'SendInvoiceReport', 'Domain\\Reporting\\Internal\\Actions\\SendInvoiceReport', 'src/Domain/Reporting/Internal/Actions/SendInvoiceReport.php'],
]);

it('can describe an anonymous domain object', function ($domainName, $objectType, $objectName, $expectedFQN, $expectedPath) {
    expect((new Domain($domainName))->object($objectType, $objectName))
        ->name->toBe($objectName)
        ->fullyQualifiedName->toBe($expectedFQN)
        ->path->toBe(Path::normalize($expectedPath));
})->with([
    ['Invoicing', 'rule', 'SomeRule', 'Domain\\Invoicing\\Rules\\SomeRule', 'src/Domain/Invoicing/Rules/SomeRule.php'],
    ['Other', 'thing', 'Something', 'Domain\\Other\\Things\\Something', 'src/Domain/Other/Things/Something.php'],
]);

describe('application layer', function () {
    beforeEach(function () {
        Config::set([
            'ddd.application_path' => 'app/Modules',
            'ddd.application_namespace' => 'App\Modules',
            'ddd.application_objects' => ['controller', 'request'],
        ]);
    });

    it('can describe objects in the application layer', function ($domainName, $objectType, $objectName, $expectedFQN, $expectedPath) {
        expect((new Domain($domainName))->object($objectType, $objectName))
            ->name->toBe($objectName)
            ->fullyQualifiedName->toBe($expectedFQN)
            ->path->toBe(Path::normalize($expectedPath));
    })->with([
        ['Invoicing', 'controller', 'InvoiceController', 'App\\Modules\\Invoicing\\Controllers\\InvoiceController', 'app/Modules/Invoicing/Controllers/InvoiceController.php'],
        ['Invoicing', 'controller', 'Nested\\InvoiceController', 'App\\Modules\\Invoicing\\Controllers\\Nested\\InvoiceController', 'app/Modules/Invoicing/Controllers/Nested/InvoiceController.php'],
        ['Invoicing', 'request', 'StoreInvoiceRequest', 'App\\Modules\\Invoicing\\Requests\\StoreInvoiceRequest', 'app/Modules/Invoicing/Requests/StoreInvoiceRequest.php'],
        ['Invoicing', 'request', 'Nested\\StoreInvoiceRequest', 'App\\Modules\\Invoicing\\Requests\\Nested\\StoreInvoiceRequest', 'app/Modules/Invoicing/Requests/Nested/StoreInvoiceRequest.php'],
    ]);
});

describe('custom layers', function () {
    beforeEach(function () {
        Config::set('ddd.layers', [
            'Support' => 'src/Support',
        ]);
    });

    it('can map domains to custom layers', function ($domainName, $objectType, $objectName, $expectedFQN, $expectedPath) {
        expect((new Domain($domainName))->object($objectType, $objectName))
            ->name->toBe($objectName)
            ->fullyQualifiedName->toBe($expectedFQN)
            ->path->toBe(Path::normalize($expectedPath));
    })->with([
        ['Support', 'class', 'ExchangeRate', 'Support\\ExchangeRate', 'src/Support/ExchangeRate.php'],
        ['Support', 'trait', 'Concerns\\HasOptions', 'Support\\Concerns\\HasOptions', 'src/Support/Concerns/HasOptions.php'],
        ['Support', 'exception', 'InvalidExchangeRate', 'Support\\Exceptions\\InvalidExchangeRate', 'src/Support/Exceptions/InvalidExchangeRate.php'],
    ]);
});

it('normalizes slashes in nested objects', function ($nameInput, $normalized) {
    expect((new Domain('Invoicing'))->object('class', $nameInput))
        ->name->toBe($normalized);
})->with([
    ['Nested\\Thing', 'Nested\\Thing'],
    ['Nested/Thing', 'Nested\\Thing'],
    ['Nested/Thing/Deeply', 'Nested\\Thing\\Deeply'],
    ['Nested\\Thing/Deeply', 'Nested\\Thing\\Deeply'],
]);

it('places a namespace-relative object name once', function (string $type, string $name, string $fqn, string $path) {
    $object = (new Domain('Billing'))->object($type, $name);

    expect($object)
        ->name->toBe('Probe')
        ->fullyQualifiedName->toBe($fqn)
        ->path->toBe(Path::normalize($path));
})->with([
    'model' => ['model', '\\Nested\\Probe', 'Domain\\Billing\\Nested\\Probe', 'src/Domain/Billing/Nested/Probe.php'],
    'class' => ['class', '\\Nested\\Probe', 'Domain\\Billing\\Nested\\Probe', 'src/Domain/Billing/Nested/Probe.php'],
]);

it('keeps nested object names relative to the object namespace', function () {
    expect((new Domain('Billing'))->object('model', 'Nested/Probe'))
        ->name->toBe('Nested\\Probe')
        ->fullyQualifiedName->toBe('Domain\\Billing\\Models\\Nested\\Probe');
});
