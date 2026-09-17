<?php

namespace Tey\LaravelDDD\Tests;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Owns the application root the suite generates into.
 *
 * The suite used to treat vendor/orchestra/testbench-core/laravel as its
 * scratch space: it copied fixtures in, overwrote that package's composer.json,
 * and ran `composer dump-autoload` there, which left a vendor/ directory behind
 * inside an installed dependency. Anything the suite failed to clean up stayed
 * in vendor until the next `composer install`, and two checkouts sharing a
 * vendor directory would fight over it.
 *
 * So the run gets its own root: a copy of the Testbench skeleton in a temp
 * directory, unique per process, deleted when the process ends. Nothing under
 * vendor/ is written to.
 *
 * The root is created once per process rather than per test. Copying 66 files
 * before every test would be wasteful, and the per-test reset already lives in
 * TestCase::cleanSlate(), which empties the generated directories between tests.
 *
 * KNOWN LIMIT — this is not full in-process isolation. PHP cannot unload a
 * class, so a fixture class autoloaded in one test stays loaded for the rest of
 * the process with the definition it was first given. Moving the root changes
 * where classes are loaded FROM; it does not make two tests that declare the
 * same class name independent. Tests that need a genuinely fresh definition
 * still need distinct class names, and the suite must still run serially
 * because ConfigManager writes a fixed path in the system temp directory.
 */
final class FixtureApplication
{
    /**
     * Namespaces the fixture root owns, mapped to their path inside it.
     *
     * These mirror the autoload-dev entries in the package's composer.json,
     * which point at the shared Testbench skeleton. The live loader is
     * re-pointed at the fixture root instead, so composer.json is left alone and
     * no root vendor/composer metadata is rewritten.
     */
    private const OWNED_NAMESPACES = [
        'App\\' => 'app',
        'Database\\Factories\\' => 'database/factories',
        'Database\\Seeders\\' => 'database/seeders',
        'Domain\\' => 'src/Domain',
        'Application\\' => 'src/Application',
        'Infrastructure\\' => 'src/Infrastructure',
    ];

    private const TEMP_PREFIX = 'laravel-ddd-harness';

    private static ?string $basePath = null;

    /** @var array<string, array<int, string>> */
    private static array $originalPsr4 = [];

    private static bool $restored = false;

    /**
     * The isolated application root for this process, created on first use.
     */
    public static function basePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }

        $root = self::temporaryDirectory();

        $files = new Filesystem;
        $files->ensureDirectoryExists($root);
        $files->copyDirectory(self::skeletonPath(), $root);

        self::$basePath = $root;

        // Testbench resolves the base path through a static that reads this
        // variable, and several of its own code paths — config:cache rebuilding
        // the application among them — go through the static rather than the
        // TestCase instance. Overriding only the instance method leaves those
        // pointing back at the installed skeleton.
        $_ENV['APP_BASE_PATH'] = $root;
        $_SERVER['APP_BASE_PATH'] = $root;

        self::pointLoaderAtFixtureRoot($root);

        // The root outlives individual tests, so it is torn down with the
        // process rather than in a test's teardown.
        register_shutdown_function(static fn () => self::restore());

        return $root;
    }

    /**
     * Restore the loader mappings and delete the root this process created.
     */
    public static function restore(): void
    {
        if (self::$restored) {
            return;
        }

        self::$restored = true;

        if ($loader = self::classLoader()) {
            foreach (self::$originalPsr4 as $prefix => $paths) {
                $loader->setPsr4($prefix, $paths);
            }
        }

        self::$originalPsr4 = [];

        unset($_ENV['APP_BASE_PATH'], $_SERVER['APP_BASE_PATH']);

        if (self::$basePath !== null) {
            self::deleteOwnedRoot(self::$basePath);
            self::$basePath = null;
        }
    }

    /**
     * The pristine Testbench skeleton the fixture root is copied from.
     *
     * Read only — the suite never writes here, which is the point.
     */
    public static function skeletonPath(): string
    {
        $path = dirname(__DIR__).'/vendor/orchestra/testbench-core/laravel';

        if (! is_dir($path)) {
            throw new RuntimeException("Cannot find the Testbench skeleton at {$path}.");
        }

        return $path;
    }

    private static function temporaryDirectory(): string
    {
        // Unique per process so concurrent runs, and repeated runs that leave a
        // directory behind after a crash, cannot collide.
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.self::TEMP_PREFIX
            .DIRECTORY_SEPARATOR.getmypid().'-'.bin2hex(random_bytes(6));
    }

    private static function pointLoaderAtFixtureRoot(string $root): void
    {
        $loader = self::classLoader();

        if ($loader === null) {
            throw new RuntimeException('Cannot find the Composer class loader to scope to the fixture root.');
        }

        $existing = $loader->getPrefixesPsr4();

        foreach (self::OWNED_NAMESPACES as $prefix => $relative) {
            self::$originalPsr4[$prefix] = $existing[$prefix] ?? [];

            $loader->setPsr4($prefix, [$root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative)]);
        }
    }

    private static function classLoader(): ?ClassLoader
    {
        foreach (spl_autoload_functions() ?: [] as $autoloader) {
            if (is_array($autoloader) && ($autoloader[0] ?? null) instanceof ClassLoader) {
                return $autoloader[0];
            }
        }

        return null;
    }

    /**
     * Delete the root, but only once it is confirmed to be one we created.
     *
     * A recursive delete driven by a path that turned out to be wrong is not
     * something to find out about afterwards.
     */
    private static function deleteOwnedRoot(string $root): void
    {
        $expectedParent = realpath(sys_get_temp_dir().DIRECTORY_SEPARATOR.self::TEMP_PREFIX);
        $resolved = realpath($root);

        if ($expectedParent === false || $resolved === false) {
            return;
        }

        if (! str_starts_with($resolved, $expectedParent.DIRECTORY_SEPARATOR)) {
            return;
        }

        (new Filesystem)->deleteDirectory($resolved);
    }
}
