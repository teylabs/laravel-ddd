<?php

use Illuminate\Support\Facades\Artisan;
use Tey\LaravelDDD\Commands;

// Regression test for https://github.com/teylabs/laravel-ddd/issues/115
// Laravel 13.24 consolidated the framework generator command definitions
// into $signature (laravel/framework#60926), which caused the extending
// ddd:* commands to register under their parent's make:* name instead.

dataset('domainGenerators', [
    'ddd:model' => ['ddd:model', Commands\DomainModelMakeCommand::class],
    'ddd:factory' => ['ddd:factory', Commands\DomainFactoryMakeCommand::class],
    'ddd:cast' => ['ddd:cast', Commands\DomainCastMakeCommand::class],
    'ddd:channel' => ['ddd:channel', Commands\DomainChannelMakeCommand::class],
    'ddd:class' => ['ddd:class', Commands\DomainClassMakeCommand::class],
    'ddd:command' => ['ddd:command', Commands\DomainConsoleMakeCommand::class],
    'ddd:controller' => ['ddd:controller', Commands\DomainControllerMakeCommand::class],
    'ddd:enum' => ['ddd:enum', Commands\DomainEnumMakeCommand::class],
    'ddd:event' => ['ddd:event', Commands\DomainEventMakeCommand::class],
    'ddd:exception' => ['ddd:exception', Commands\DomainExceptionMakeCommand::class],
    'ddd:interface' => ['ddd:interface', Commands\DomainInterfaceMakeCommand::class],
    'ddd:job' => ['ddd:job', Commands\DomainJobMakeCommand::class],
    'ddd:listener' => ['ddd:listener', Commands\DomainListenerMakeCommand::class],
    'ddd:mail' => ['ddd:mail', Commands\DomainMailMakeCommand::class],
    'ddd:middleware' => ['ddd:middleware', Commands\DomainMiddlewareMakeCommand::class],
    'ddd:notification' => ['ddd:notification', Commands\DomainNotificationMakeCommand::class],
    'ddd:observer' => ['ddd:observer', Commands\DomainObserverMakeCommand::class],
    'ddd:policy' => ['ddd:policy', Commands\DomainPolicyMakeCommand::class],
    'ddd:provider' => ['ddd:provider', Commands\DomainProviderMakeCommand::class],
    'ddd:request' => ['ddd:request', Commands\DomainRequestMakeCommand::class],
    'ddd:resource' => ['ddd:resource', Commands\DomainResourceMakeCommand::class],
    'ddd:rule' => ['ddd:rule', Commands\DomainRuleMakeCommand::class],
    'ddd:scope' => ['ddd:scope', Commands\DomainScopeMakeCommand::class],
    'ddd:seeder' => ['ddd:seeder', Commands\DomainSeederMakeCommand::class],
    'ddd:trait' => ['ddd:trait', Commands\DomainTraitMakeCommand::class],
    'ddd:migration' => ['ddd:migration', Commands\Migration\DomainMigrateMakeCommand::class],
]);

it('registers the generator under its ddd name with a --domain option', function (string $name, string $class) {
    $commands = Artisan::all();

    expect($commands)->toHaveKey($name);
    expect($commands[$name])->toBeInstanceOf($class);
    expect($commands[$name]->getName())->toBe($name);
    expect($commands[$name]->getDefinition()->hasOption('domain'))->toBeTrue();
})->with('domainGenerators');

it('does not override the native framework generator command', function () {
    $commands = Artisan::all();

    $native = [
        'make:model',
        'make:factory',
        'make:cast',
        'make:channel',
        'make:class',
        'make:command',
        'make:controller',
        'make:enum',
        'make:event',
        'make:exception',
        'make:interface',
        'make:job',
        'make:listener',
        'make:mail',
        'make:middleware',
        'make:notification',
        'make:observer',
        'make:policy',
        'make:provider',
        'make:request',
        'make:resource',
        'make:rule',
        'make:scope',
        'make:seeder',
        'make:trait',
        'make:migration',
    ];

    foreach ($native as $name) {
        expect($commands)->toHaveKey($name);

        expect(get_class($commands[$name]))
            ->not->toStartWith('Tey\\LaravelDDD\\', "[{$name}] should not resolve to a package command");
    }
});
