<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tey\LaravelDDD\Support\Domain;

// One case per generator, not one per generator per path combination.
//
// What is distinct here is the type: each ddd:<type> has its own command, stub
// and configured namespace segment, so all of them are kept. What was repeated
// is the path/namespace dimension — the same substitution rule re-run four times
// for every type, 92 cases to prove 23 things.
//
// That rule now lives in DomainPathResolutionTest, which covers all four
// combinations against the schema with literal expectations and generates
// through both a domain and an application object. It is a stricter check than
// this file could make: the expectations below are derived from
// Domain::object(), so they can only catch a command disagreeing with the
// schema, never the schema being wrong.
//
// The combination kept here is the one where path and namespace deliberately
// disagree ('src/Domains' with namespace 'Domain'). Of the four it is the least
// forgiving: anything that assumes the namespace can be derived from the
// directory name passes under the other three and fails under this one.
it('can generate other objects', function ($type, $objectName) {
    if (in_array($type, ['class', 'enum', 'interface', 'trait'])) {
        skipOnLaravelVersionsBelow('11');
    }

    Config::set('ddd.domain_path', 'src/Domains');
    Config::set('ddd.domain_namespace', 'Domain');

    $domain = new Domain('Other');
    $domainObject = $domain->object($type, $objectName);

    $relativePath = $domainObject->path;
    $expectedNamespace = $domainObject->namespace;
    $expectedPath = base_path($relativePath);

    if (file_exists($expectedPath)) {
        unlink($expectedPath);
    }

    expect(file_exists($expectedPath))->toBeFalse();

    $command = "ddd:{$type} {$domain->domain}:{$objectName}";

    Artisan::call($command);

    expect(Artisan::output())->toContainFilepath($relativePath);

    expect(file_exists($expectedPath))->toBeTrue();

    expect(file_get_contents($expectedPath))->toContain("namespace {$expectedNamespace};");
})->with([
    'cast' => ['cast', 'SomeCast'],
    'channel' => ['channel', 'SomeChannel'],
    'command' => ['command', 'SomeCommand'],
    'controller' => ['controller', 'SomeController'],
    'event' => ['event', 'SomeEvent'],
    'exception' => ['exception', 'SomeException'],
    'job' => ['job', 'SomeJob'],
    'listener' => ['listener', 'SomeListener'],
    'mail' => ['mail', 'SomeMail'],
    'middleware' => ['middleware', 'SomeMiddleware'],
    'notification' => ['notification', 'SomeNotification'],
    'observer' => ['observer', 'SomeObserver'],
    'policy' => ['policy', 'SomePolicy'],
    'provider' => ['provider', 'SomeProvider'],
    'resource' => ['resource', 'SomeResource'],
    'request' => ['request', 'SomeRequest'],
    'rule' => ['rule', 'SomeRule'],
    'scope' => ['scope', 'SomeScope'],
    'seeder' => ['seeder', 'SomeSeeder'],
    'class' => ['class', 'SomeClass'],
    'enum' => ['enum', 'SomeEnum'],
    'interface' => ['interface', 'SomeInterface'],
    'trait' => ['trait', 'SomeTrait'],
]);
