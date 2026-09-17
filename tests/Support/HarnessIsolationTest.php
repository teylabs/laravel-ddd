<?php

use Composer\Autoload\ClassLoader;
use Tey\LaravelDDD\Tests\BootsTestApplication;
use Tey\LaravelDDD\Tests\FixtureApplication;

// The suite generates files into an application root. It used to generate them
// into vendor/orchestra/testbench-core/laravel — writing fixtures, overwriting
// that package's composer.json, and leaving a vendor/ directory behind inside an
// installed dependency.
//
// These guard the isolation itself. Without them the harness could drift back to
// writing into vendor/ and nothing in the suite would notice, because every
// behavioural test passes either way.

uses(BootsTestApplication::class);

/**
 * Resolve a path to its canonical form.
 *
 * Temp directories are often symlinked — on macOS /var points at /private/var —
 * so comparing a resolved path against an unresolved one fails for a reason
 * that has nothing to do with isolation.
 */
function canonicalPath(string $path): string
{
    return realpath($path) ?: $path;
}

it('runs the application from a root outside the package vendor directory', function () {
    $vendor = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor';

    expect(canonicalPath(base_path()))->not->toStartWith(canonicalPath($vendor))
        ->and(canonicalPath(base_path()))->toBe(canonicalPath(FixtureApplication::basePath()));
});

it('owns a fixture root that is unique to this process', function () {
    $root = FixtureApplication::basePath();

    expect($root)->toContain('laravel-ddd-harness')
        // The process id is in the directory name, so two runs — and two
        // concurrent processes — cannot land on the same root.
        ->and(basename($root))->toStartWith(getmypid().'-');
});

it('never writes fixtures into the installed Testbench skeleton', function () {
    $skeleton = FixtureApplication::skeletonPath();

    // PREREQUISITE: this reads the installed package as it is on disk, so a
    // checkout polluted by a suite run from before this isolation existed will
    // fail here until vendor/orchestra/testbench-core is reinstalled. The
    // failure is real — those files do not belong in an installed dependency —
    // but the cause may be historical rather than the change under test.
    $remedy = ' — if this checkout predates harness isolation, reinstall vendor/orchestra/testbench-core to clear it';

    // setupTestApplication() copies src/, database/ and config/ddd.php into the
    // application root. If the root ever points back at the installed package,
    // these appear here.
    expect(is_dir($skeleton.'/src'))->toBeFalse('The installed Testbench skeleton has fixture sources copied into it'.$remedy)
        ->and(file_exists($skeleton.'/config/ddd.php'))->toBeFalse('A fixture config was written into the installed Testbench skeleton'.$remedy);

    // vendor needs care, because two very different things can sit at that path.
    //
    // Testbench manages a symlink there itself — Foundation\Actions\
    // CreateVendorSymlink and DeleteVendorSymlink point <skeleton>/vendor at the
    // project's real vendor so its CLI can boot the skeleton app. That link is
    // legitimate and may be present before this suite starts; is_dir() follows
    // it, which is why an earlier version of this guard rejected it.
    //
    // A REAL directory at that path is the pollution being guarded against: it
    // is what composer dump-autoload leaves when it runs with the skeleton as
    // its working directory.
    $vendor = $skeleton.DIRECTORY_SEPARATOR.'vendor';

    if (is_link($vendor)) {
        $target = readlink($vendor);

        expect(realpath($target) ?: $target)->toBe(
            realpath(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'),
            'The skeleton vendor symlink points somewhere other than this project\'s vendor directory'
        );
    } else {
        expect(file_exists($vendor))->toBeFalse(
            'composer dump-autoload ran inside the installed Testbench skeleton'.$remedy
        );
    }
});

it('does not copy the vendor symlink Testbench places in its skeleton', function () {
    // Testbench symlinks <skeleton>/vendor -> <project>/vendor. A recursive copy
    // follows it (isDir() resolves symlinks), descends into the project vendor,
    // reaches testbench-core/laravel again, follows the same symlink, and
    // repeats until the path is too long to open.
    //
    // The fixture root may legitimately have its own vendor/ — composerReload()
    // runs composer dump-autoload there, and Testbench may symlink one in via
    // CreateVendorSymlink. What it must never contain is a COPY of the project's
    // dependencies, which is what following the skeleton's symlink produces.
    $vendor = base_path('vendor');

    if (is_link($vendor)) {
        // A link is Testbench's doing, not the copy's; copying a symlink would
        // have produced a real file or directory here.
        expect(realpath(readlink($vendor)))->toBe(realpath(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'));
    }

    expect(is_dir($vendor.'/orchestra/testbench-core') && ! is_link($vendor))->toBeFalse(
        'The skeleton copy followed the vendor symlink and copied the project dependencies into the fixture root'
    );
});

it('scopes the fixture namespaces to the fixture root on the live loader', function () {
    $autoloader = collect(spl_autoload_functions() ?: [])
        ->first(fn ($candidate) => is_array($candidate) && ($candidate[0] ?? null) instanceof ClassLoader);

    expect($autoloader)->not->toBeNull();

    $prefixes = $autoloader[0]->getPrefixesPsr4();

    // Compared raw rather than resolved: the mapped directories need not exist
    // yet, and the loader was configured from this exact string.
    $root = FixtureApplication::basePath();

    // These are mapped to the installed skeleton by the package's composer.json.
    // They are re-pointed at runtime so that metadata never has to be rewritten.
    foreach (['Domain\\', 'Application\\', 'Infrastructure\\', 'App\\'] as $prefix) {
        expect($prefixes)->toHaveKey($prefix);
        expect($prefixes[$prefix][0])->toStartWith($root);
    }
});

it('generates into the fixture root rather than the installed skeleton', function () {
    $this->setupTestApplication();

    // A real generator run, so this fails if generation escapes the fixture root
    // rather than only checking configuration.
    $this->artisan('ddd:model', ['name' => 'IsolationProbe', '--domain' => 'Invoicing'])
        ->assertSuccessful()
        ->execute();

    $generated = base_path('src/Domain/Invoicing/Models/IsolationProbe.php');

    expect(file_exists($generated))->toBeTrue()
        ->and(canonicalPath($generated))->toStartWith(canonicalPath(FixtureApplication::basePath()))
        ->and(file_exists(FixtureApplication::skeletonPath().'/src/Domain/Invoicing/Models/IsolationProbe.php'))->toBeFalse();
});
