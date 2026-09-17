<?php

namespace Tey\LaravelDDD\Tests;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Throwable;

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
 * Everything this touches is process-global — two environment variables and the
 * PSR-4 map of the live Composer loader — so all of it is snapshotted before it
 * is changed and put back exactly as found. open() and restore() may be called
 * in any order and any number of times.
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

    /**
     * The package's own namespace, used to tell its loader apart from any other.
     */
    private const PACKAGE_NAMESPACE = 'Tey\\LaravelDDD\\';

    private const ENV_KEY = 'APP_BASE_PATH';

    private const TEMP_PREFIX = 'laravel-ddd-harness';

    private static ?string $basePath = null;

    /**
     * The exact loader whose mappings were changed, retained so they are put
     * back on that same object rather than on whichever loader happens to be
     * registered first at teardown.
     */
    private static ?ClassLoader $loader = null;

    /** @var array<string, array<int, string>> */
    private static array $originalPsr4 = [];

    /** @var array<string, array{0: bool, 1: mixed}>|null */
    private static ?array $environmentSnapshot = null;

    private static bool $shutdownRegistered = false;

    /**
     * The isolated application root for this process, created on first use.
     */
    public static function basePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }

        $files = new Filesystem;
        $root = self::temporaryDirectory();

        // Located before anything is mutated: if the package's loader cannot be
        // found there is no safe way to scope the namespaces, and failing here
        // leaves nothing to unwind.
        $loader = self::packageClassLoader();

        self::snapshotEnvironment();

        $rootCreated = false;

        try {
            $files->ensureDirectoryExists($root);

            if (! is_dir($root)) {
                throw new RuntimeException("Could not create the fixture application root at {$root}.");
            }

            $rootCreated = true;

            self::copySkeleton($files, self::skeletonPath(), $root);

            // Testbench resolves the base path through a static that reads this
            // variable, and several of its own code paths — config:cache
            // rebuilding the application among them — go through the static
            // rather than the TestCase instance. Overriding only the instance
            // method leaves those pointing back at the installed skeleton.
            $_ENV[self::ENV_KEY] = $root;
            $_SERVER[self::ENV_KEY] = $root;

            self::pointLoaderAtFixtureRoot($loader, $root);

            self::$loader = $loader;
            self::$basePath = $root;
        } catch (Throwable $failure) {
            // Unwind whatever got as far as happening, so a failed setup cannot
            // leave a stray root behind or a redirected environment for the rest
            // of the process.
            self::restoreLoaderMappings();
            self::restoreEnvironment();

            if ($rootCreated) {
                self::deleteOwnedRoot($root);
            }

            self::$loader = null;
            self::$basePath = null;

            throw $failure;
        }

        if (! self::$shutdownRegistered) {
            self::$shutdownRegistered = true;

            // The root outlives individual tests, so it is torn down with the
            // process. Registered once; restore() is safe to call again.
            register_shutdown_function(static fn () => self::restore());
        }

        return $root;
    }

    /**
     * Put back everything that was changed and delete the root.
     *
     * Safe to call when nothing is open, and safe to call more than once. A
     * later basePath() opens a fresh root rather than handing back a deleted
     * one.
     */
    public static function restore(): void
    {
        self::restoreLoaderMappings();
        self::restoreEnvironment();

        if (self::$basePath !== null) {
            self::deleteOwnedRoot(self::$basePath);
            self::$basePath = null;
        }

        self::$loader = null;
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

    /**
     * Copy the skeleton without following the vendor symlink inside it.
     *
     * Testbench puts a `vendor` symlink in its skeleton pointing back at the
     * project's real vendor directory:
     *
     *     testbench-core/laravel/vendor -> <project>/vendor
     *
     * A plain recursive copy follows it, because isDir() resolves symlinks — so
     * it descends into <project>/vendor, reaches testbench-core/laravel again,
     * follows the same symlink, and repeats until the path is too long to open.
     * The fixture root has no use for a vendor directory anyway: the classes it
     * needs are resolved by the re-pointed loader, and composerReload() writes
     * its own vendor there if a test wants one.
     *
     * Symfony's Finder does not follow symlinks unless asked, and vendor is
     * excluded explicitly so the intent survives anyone changing that.
     */
    private static function copySkeleton(Filesystem $files, string $source, string $root): void
    {
        $finder = Finder::create()
            ->files()
            ->in($source)
            ->exclude('vendor')
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->followLinks(false);

        $copied = 0;

        foreach ($finder as $file) {
            $destination = $root.DIRECTORY_SEPARATOR.$file->getRelativePathname();

            $files->ensureDirectoryExists(dirname($destination));

            if (! $files->copy($file->getPathname(), $destination)) {
                throw new RuntimeException("Could not copy {$file->getPathname()} into the fixture root.");
            }

            $copied++;
        }

        if ($copied === 0) {
            throw new RuntimeException("Copied no files from the Testbench skeleton at {$source}.");
        }
    }

    private static function temporaryDirectory(): string
    {
        // Unique per process so concurrent runs, and repeated runs that leave a
        // directory behind after a crash, cannot collide.
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.self::TEMP_PREFIX
            .DIRECTORY_SEPARATOR.getmypid().'-'.bin2hex(random_bytes(6));
    }

    private static function snapshotEnvironment(): void
    {
        if (self::$environmentSnapshot !== null) {
            return;
        }

        // Presence and value are recorded separately: a variable that was absent
        // has to end up absent again, not set to null or to an empty string.
        self::$environmentSnapshot = [
            'env' => [array_key_exists(self::ENV_KEY, $_ENV), $_ENV[self::ENV_KEY] ?? null],
            'server' => [array_key_exists(self::ENV_KEY, $_SERVER), $_SERVER[self::ENV_KEY] ?? null],
        ];
    }

    private static function restoreEnvironment(): void
    {
        if (self::$environmentSnapshot === null) {
            return;
        }

        [$hadEnv, $previousEnv] = self::$environmentSnapshot['env'];
        [$hadServer, $previousServer] = self::$environmentSnapshot['server'];

        if ($hadEnv) {
            $_ENV[self::ENV_KEY] = $previousEnv;
        } else {
            unset($_ENV[self::ENV_KEY]);
        }

        if ($hadServer) {
            $_SERVER[self::ENV_KEY] = $previousServer;
        } else {
            unset($_SERVER[self::ENV_KEY]);
        }

        self::$environmentSnapshot = null;
    }

    private static function pointLoaderAtFixtureRoot(ClassLoader $loader, string $root): void
    {
        $existing = $loader->getPrefixesPsr4();

        foreach (self::OWNED_NAMESPACES as $prefix => $relative) {
            self::$originalPsr4[$prefix] = $existing[$prefix] ?? [];

            $loader->setPsr4($prefix, [$root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative)]);
        }
    }

    private static function restoreLoaderMappings(): void
    {
        if (self::$loader !== null) {
            foreach (self::$originalPsr4 as $prefix => $paths) {
                self::$loader->setPsr4($prefix, $paths);
            }
        }

        self::$originalPsr4 = [];
    }

    /**
     * The Composer loader that autoloads this package.
     *
     * Not simply the first registered loader: the fixture root has its own
     * composer.json, and anything that registers another loader would otherwise
     * receive the fixture mappings at setup, or have them written onto it at
     * teardown. The package's own namespace identifies the right one.
     */
    private static function packageClassLoader(): ClassLoader
    {
        foreach (spl_autoload_functions() ?: [] as $autoloader) {
            if (! is_array($autoloader) || ! (($autoloader[0] ?? null) instanceof ClassLoader)) {
                continue;
            }

            /** @var ClassLoader $candidate */
            $candidate = $autoloader[0];

            if (array_key_exists(self::PACKAGE_NAMESPACE, $candidate->getPrefixesPsr4())) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Cannot find the Composer class loader that registers '.self::PACKAGE_NAMESPACE.'.'
        );
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
