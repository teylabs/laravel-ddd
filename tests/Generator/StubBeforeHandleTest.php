<?php

use Illuminate\Support\Facades\Artisan;

/*
 * As in 3.x, a ddd:* generator answers getStub() outside a run (tooling
 * inspects commands this way): no domain is resolved then, so the type's home
 * kind decides, and the stub is the generator's own.
 */

it('returns the native stub from a ddd:* generator outside a run', function (?string $classFolder) {
    if ($classFolder !== null) {
        config()->set('ddd.namespaces.class', $classFolder);
    }

    Artisan::call('ddd:class', ['name' => 'Probe', '--domain' => 'Comparison']);
    $command = Artisan::all()['ddd:class'];
    $stub = (new ReflectionMethod($command, 'getStub'))->invoke($command);

    expect(realpath($stub))->toStartWith(realpath(base_path('vendor/laravel/framework')) ?: realpath(dirname(__DIR__, 2).'/vendor/laravel/framework'));
})->with([
    'default configuration' => [null],
    'configured class folder' => ['UnexpectedClasses'],
]);
