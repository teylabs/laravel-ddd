<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tey\LaravelDDD\Support\Domain;

// Where a generated object lands is one rule: ddd.domain_path and
// ddd.domain_namespace substitute into <path>/<Domain>/<type namespace> and
// <namespace>\<Domain>\<type namespace>, except for objects configured as
// application objects, which follow the application layer instead and are
// unaffected by the domain settings entirely.
//
// That rule used to be re-proved by every generator test in turn, each running
// the same four path/namespace combinations. This exercises it centrally, and
// does so more strictly than those tests could: they derived their expected path
// from Domain::object(), the very thing under test, so they could only detect a
// command disagreeing with the schema — never the schema itself being wrong.
// Here the expectations are written out literally.

dataset('domainPathCombinations', [
    // path and namespace agree — the ordinary case
    'matching path and namespace' => ['src/Domain', 'Domain'],
    // both differ from the default
    'renamed path and namespace' => ['src/Domains', 'Domains'],
    // path and namespace deliberately disagree, which catches anything that
    // assumes the namespace can be derived from the directory name
    'path and namespace disagree' => ['src/Domains', 'Domain'],
    // a nested path, which catches anything joining only a single segment
    'nested path' => ['Custom/PathTo/Domain', 'Domain'],
]);

it('resolves a domain object from the configured path and namespace', function (string $domainPath, string $domainRoot) {
    Config::set('ddd.domain_path', $domainPath);
    Config::set('ddd.domain_namespace', $domainRoot);

    $object = (new Domain('Other'))->object('event', 'SomeEvent');

    expect($object->path)->toBe("{$domainPath}/Other/Events/SomeEvent.php")
        ->and($object->namespace)->toBe("{$domainRoot}\\Other\\Events")
        ->and($object->fullyQualifiedName)->toBe("{$domainRoot}\\Other\\Events\\SomeEvent");
})->with('domainPathCombinations');

it('resolves a domain object whose type has no namespace segment', function (string $domainPath, string $domainRoot) {
    Config::set('ddd.domain_path', $domainPath);
    Config::set('ddd.domain_namespace', $domainRoot);

    // ddd.namespaces.class is an empty string, so the object sits directly in
    // the domain root. Joining an empty segment is where a stray separator shows.
    $object = (new Domain('Other'))->object('class', 'SomeClass');

    expect($object->path)->toBe("{$domainPath}/Other/SomeClass.php")
        ->and($object->namespace)->toBe("{$domainRoot}\\Other")
        ->and($object->fullyQualifiedName)->toBe("{$domainRoot}\\Other\\SomeClass");
})->with('domainPathCombinations');

it('keeps application objects out of the domain path entirely', function (string $domainPath, string $domainRoot) {
    Config::set('ddd.domain_path', $domainPath);
    Config::set('ddd.domain_namespace', $domainRoot);

    // controller, request and middleware are application objects by default.
    // Changing the domain settings must not move them, which is why running the
    // whole path dataset against those generators proved nothing.
    $object = (new Domain('Other'))->object('controller', 'SomeController');

    expect($object->path)->toBe(config('ddd.application_path').'/Other/Controllers/SomeController.php')
        ->and($object->namespace)->toBe(config('ddd.application_namespace').'\\Other\\Controllers')
        ->and($object->path)->not->toContain($domainPath);
})->with('domainPathCombinations');

it('generates a domain object into the configured path', function (string $domainPath, string $domainRoot) {
    Config::set('ddd.domain_path', $domainPath);
    Config::set('ddd.domain_namespace', $domainRoot);

    // The schema assertions above pin the rule; this pins that generation
    // actually honours it, so the two cannot drift apart unnoticed.
    $relativePath = "{$domainPath}/Other/Events/PathResolutionEvent.php";
    $expectedPath = base_path($relativePath);

    if (file_exists($expectedPath)) {
        unlink($expectedPath);
    }

    Artisan::call('ddd:event Other:PathResolutionEvent');

    expect(file_exists($expectedPath))->toBeTrue("Expecting the event at {$relativePath}")
        ->and(file_get_contents($expectedPath))->toContain("namespace {$domainRoot}\\Other\\Events;");
})->with('domainPathCombinations');

it('generates an application object into the application path', function (string $domainPath, string $domainRoot) {
    Config::set('ddd.domain_path', $domainPath);
    Config::set('ddd.domain_namespace', $domainRoot);

    $relativePath = config('ddd.application_path').'/Other/Controllers/PathResolutionController.php';
    $expectedPath = base_path($relativePath);

    if (file_exists($expectedPath)) {
        unlink($expectedPath);
    }

    Artisan::call('ddd:controller Other:PathResolutionController');

    expect(file_exists($expectedPath))->toBeTrue("Expecting the controller at {$relativePath}")
        ->and(file_get_contents($expectedPath))->toContain('namespace '.config('ddd.application_namespace').'\\Other\\Controllers;');
})->with('domainPathCombinations');
