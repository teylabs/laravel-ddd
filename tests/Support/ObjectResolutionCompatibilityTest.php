<?php

use Tey\LaravelDDD\Support\Domain;
use Tey\LaravelDDD\Support\GeneratorBlueprint;

it('keeps generation and object descriptions on the same layer and namespace rules', function ($domain, $type, $name, $absolute, $namespace) {
    config([
        'ddd.layers.Support' => 'src/Support',
        'ddd.application_namespace' => 'Application',
        'ddd.application_path' => 'src/Application',
    ]);

    $object = (new Domain($domain))->object($type, $name, $absolute);
    $blueprint = new GeneratorBlueprint("ddd:{$type}", ($absolute ? '/' : '').$name, $domain);

    expect($object->namespace)->toBe($namespace)
        ->and($blueprint->schema->namespace)->toBe($namespace)
        ->and($blueprint->schema->fullyQualifiedName)->toBe($object->fullyQualifiedName)
        ->and($blueprint->schema->path)->toBe($object->path);
})->with([
    'default folder' => ['Billing', 'model', 'Invoice', false, 'Domain\\Billing\\Models'],
    'application layer' => ['Billing', 'controller', 'InvoiceController', false, 'Application\\Billing\\Controllers'],
    'custom layer' => ['Support', 'model', 'Invoice', false, 'Support\\Models'],
    'absolute name' => ['Billing', 'model', 'Invoice', true, 'Domain\\Billing'],
    'explicit namespace' => ['Billing', 'model', '\\Custom\\Invoice', false, 'Domain\\Billing\\Custom'],
]);

it('preserves the distinct name normalization of generators and object descriptions', function () {
    $object = (new Domain('Billing'))->model('invoice_item');
    $blueprint = new GeneratorBlueprint('ddd:model', 'invoice_item', 'Billing');

    expect($object->fullyQualifiedName)->toBe('Domain\\Billing\\Models\\invoice_item')
        ->and($blueprint->schema->fullyQualifiedName)->toBe('Domain\\Billing\\Models\\InvoiceItem');
});
