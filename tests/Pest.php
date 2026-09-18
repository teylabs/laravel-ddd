<?php

use Symfony\Component\Process\Process;
use Tey\LaravelDDD\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function skipOnLaravelVersionsBelow($minimumVersion)
{
    $version = app()->version();

    if (version_compare($version, $minimumVersion, '<')) {
        test()->markTestSkipped("Only available on Laravel {$minimumVersion}+ (Current version: {$version}).");
    }
}

function onlyOnLaravelVersionsBelow($minimumVersion)
{
    $version = app()->version();

    if (! version_compare($version, $minimumVersion, '<')) {
        test()->markTestSkipped("Does not apply to Laravel {$minimumVersion}+ (Current version: {$version}).");
    }
}

function setConfigValues(array $values)
{
    TestCase::configValues($values);
}

/**
 * Assert that a generated file is valid PHP.
 *
 * Shared because more than one suite needs it: a generator can write a file that
 * exists and contains the right words yet still does not parse — a malformed
 * import is exactly that failure, and assertions on file contents do not notice
 * it.
 */
function assertParses(string $relativePath): void
{
    $process = new Process([PHP_BINARY, '-l', base_path($relativePath)]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue(
        "[{$relativePath}] is not valid PHP: ".trim($process->getOutput().$process->getErrorOutput())
    );
}
