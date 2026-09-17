<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// FixtureApplication owns process-global state that the running suite depends
// on — the application root, two environment variables and the live loader's
// PSR-4 map. Calling restore() inside a live test would pull the root out from
// under Testbench mid-run, so these scenarios each run in their own PHP process
// where there is no application to disturb.
//
// The scenario script does the asserting; this only reports what it found.

/**
 * @return array{0: int, 1: string}
 */
function runLifecycleScenario(string $scenario): array
{
    $files = new Filesystem;

    // Each scenario gets its own temp namespace. They inspect and delete the
    // directory the helper works under, and the suite running them has a live
    // root beneath the real one — sharing it would mean a scenario deleting the
    // application out from under the test that launched it.
    $temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-ddd-lifecycle-'.bin2hex(random_bytes(6));
    $files->ensureDirectoryExists($temp);

    try {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/Harness/lifecycle-scenario.php', $scenario],
            dirname(__DIR__, 2),
            ['TMPDIR' => $temp, 'TMP' => $temp, 'TEMP' => $temp],
        );

        $process->setTimeout(120);
        $process->run();

        return [$process->getExitCode(), trim($process->getOutput().$process->getErrorOutput())];
    } finally {
        $files->deleteDirectory($temp);
    }
}

it('restores lifecycle state in an isolated process', function (string $scenario) {
    [$exitCode, $output] = runLifecycleScenario($scenario);

    expect($exitCode)->toBe(0, "[{$scenario}] {$output}")
        ->and($output)->toContain('OK');
})->with([
    // A consumer's own APP_BASE_PATH must come back exactly, not be deleted.
    'restores-preexisting-environment',
    // Absent must stay absent, rather than becoming present-and-null.
    'restores-absent-environment',
    // The package's loader must be the one changed and the one put back, even
    // when another loader is registered ahead of it.
    'ignores-decoy-loader',
    // restore() must not wedge the helper shut and leak the next root.
    'reopens-after-restore',
    // A failed setup must leave no redirected environment and no stray root.
    'cleans-up-failed-setup',
]);
