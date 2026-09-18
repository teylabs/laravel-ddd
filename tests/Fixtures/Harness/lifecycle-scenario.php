<?php

/*
 * Lifecycle scenarios for FixtureApplication, each run in its own PHP process.
 *
 * FixtureApplication is process-global state that the running suite depends on:
 * calling restore() inside a live test would pull the application root out from
 * under Testbench mid-run. So these exercise it in a fresh process where there
 * is no application to disturb, and the test that drives them only checks the
 * exit status and output.
 *
 * Usage: php lifecycle-scenario.php <scenario>
 * Exits 0 with "OK" on success, 1 with the failed expectation on failure.
 */

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use Tey\LaravelDDD\Tests\FixtureApplication;

require dirname(__DIR__, 3).'/vendor/autoload.php';

const ENV_KEY = 'APP_BASE_PATH';

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

function check(bool $condition, string $message): void
{
    if (! $condition) {
        fail($message);
    }
}

/** Every temp root currently on disk, so leaks can be spotted. */
function existingRoots(): array
{
    $parent = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-ddd-harness';

    return is_dir($parent) ? array_values(array_diff(scandir($parent) ?: [], ['.', '..'])) : [];
}

$scenario = $argv[1] ?? '';

switch ($scenario) {
    case 'restores-preexisting-environment':
        // A consumer may already be pointing Testbench somewhere. That value has
        // to survive, not be deleted on the way out.
        $_ENV[ENV_KEY] = '/somewhere/chosen/by/the/consumer';
        $_SERVER[ENV_KEY] = '/a/different/value/entirely';

        $root = FixtureApplication::basePath();
        check($_ENV[ENV_KEY] === $root, 'Expecting $_ENV to point at the fixture root while open');

        FixtureApplication::restore();

        check(
            array_key_exists(ENV_KEY, $_ENV) && $_ENV[ENV_KEY] === '/somewhere/chosen/by/the/consumer',
            'Expecting the pre-existing $_ENV value to be restored exactly, got '.var_export($_ENV[ENV_KEY] ?? null, true)
        );
        check(
            array_key_exists(ENV_KEY, $_SERVER) && $_SERVER[ENV_KEY] === '/a/different/value/entirely',
            'Expecting the pre-existing $_SERVER value to be restored exactly, got '.var_export($_SERVER[ENV_KEY] ?? null, true)
        );
        break;

    case 'restores-absent-environment':
        // Absent has to mean absent afterwards — not present-and-null.
        unset($_ENV[ENV_KEY], $_SERVER[ENV_KEY]);

        FixtureApplication::basePath();
        FixtureApplication::restore();

        check(! array_key_exists(ENV_KEY, $_ENV), 'Expecting $_ENV to have no APP_BASE_PATH key again');
        check(! array_key_exists(ENV_KEY, $_SERVER), 'Expecting $_SERVER to have no APP_BASE_PATH key again');
        break;

    case 'ignores-decoy-loader':
        // Another loader registered ahead of the package's own must neither
        // receive the fixture mappings nor be written to at teardown.
        $decoy = new ClassLoader;
        $decoy->setPsr4('Domain\\', ['/decoy/domain']);
        $decoy->register(true);

        $packageLoader = null;
        foreach (spl_autoload_functions() as $autoloader) {
            if (is_array($autoloader) && ($autoloader[0] ?? null) instanceof ClassLoader
                && array_key_exists('Tey\\LaravelDDD\\', $autoloader[0]->getPrefixesPsr4())) {
                $packageLoader = $autoloader[0];
                break;
            }
        }

        check($packageLoader !== null, 'Expecting to find the package loader');
        check(spl_autoload_functions()[0][0] === $decoy, 'Expecting the decoy to be registered first');

        $originalPackagePaths = $packageLoader->getPrefixesPsr4()['Domain\\'];

        $root = FixtureApplication::basePath();

        check(
            $decoy->getPrefixesPsr4()['Domain\\'] === ['/decoy/domain'],
            'The decoy loader was modified; the helper picked the wrong loader'
        );
        check(
            str_starts_with($packageLoader->getPrefixesPsr4()['Domain\\'][0], $root),
            'Expecting the package loader to point at the fixture root'
        );

        FixtureApplication::restore();

        check(
            $packageLoader->getPrefixesPsr4()['Domain\\'] === $originalPackagePaths,
            'Expecting the package loader mappings to be restored exactly'
        );
        check(
            $decoy->getPrefixesPsr4()['Domain\\'] === ['/decoy/domain'],
            'The decoy loader was written to during restore'
        );
        break;

    case 'reopens-after-restore':
        // restore() must not wedge the helper shut: a later basePath() has to
        // produce a fresh, tracked root that is cleaned up in turn.
        $first = FixtureApplication::basePath();
        check(is_dir($first), 'Expecting the first root to exist');

        FixtureApplication::restore();
        check(! is_dir($first), 'Expecting the first root to be deleted');

        $second = FixtureApplication::basePath();
        check(is_dir($second), 'Expecting a fresh root after reopening');
        check($second !== $first, 'Expecting the reopened root to be a new directory');

        FixtureApplication::restore();
        check(! is_dir($second), 'Expecting the reopened root to be deleted too, not leaked');

        check(existingRoots() === [], 'Expecting no temp roots left behind, found: '.implode(', ', existingRoots()));
        break;

    case 'cleans-up-failed-setup':
        // Force creation to fail by occupying the parent directory with a file,
        // then check nothing was left redirected or lying around.
        unset($_ENV[ENV_KEY], $_SERVER[ENV_KEY]);

        $parent = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-ddd-harness';

        if (is_dir($parent)) {
            (new Filesystem)->deleteDirectory($parent);
        }

        file_put_contents($parent, 'not a directory');

        $threw = false;

        try {
            FixtureApplication::basePath();
        } catch (Throwable) {
            $threw = true;
        }

        unlink($parent);

        check($threw, 'Expecting a failed setup to throw rather than return a broken root');
        check(! array_key_exists(ENV_KEY, $_ENV), 'A failed setup left $_ENV redirected');
        check(! array_key_exists(ENV_KEY, $_SERVER), 'A failed setup left $_SERVER redirected');

        // And the helper has to still be usable afterwards.
        $root = FixtureApplication::basePath();
        check(is_dir($root), 'Expecting the helper to still work after a failed setup');
        FixtureApplication::restore();
        break;

    default:
        fail("Unknown scenario: {$scenario}");
}

echo "OK\n";
