<?php

use Illuminate\Filesystem\Filesystem;
use Tey\LaravelDDD\Tests\FixtureApplication;

// The skeleton copy must not traverse symlinks.
//
// Testbench places testbench-core/laravel/vendor -> <project>/vendor inside its
// own skeleton. A copy that follows it descends into the project vendor, reaches
// testbench-core/laravel, follows the same symlink again, and repeats until the
// path is too long to open.
//
// Excluding `vendor` by name is not enough on its own: it only covers the one
// link that is known about today. This builds a synthetic skeleton in a temp
// directory — the real installed one is never touched — and checks a non-vendor
// directory symlink and a symlinked file as well.

/**
 * Invoke the private copy routine directly, so the guard tests the copy itself
 * rather than a whole application boot.
 */
function copySkeletonUnderTest(Filesystem $files, string $source, string $destination): void
{
    $method = new ReflectionMethod(FixtureApplication::class, 'copySkeleton');
    $method->setAccessible(true);
    $method->invoke(null, $files, $source, $destination);
}

it('copies a skeleton without following any symlink out of it', function () {
    $files = new Filesystem;

    $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-ddd-copy-guard-'.bin2hex(random_bytes(6));
    $skeleton = $base.DIRECTORY_SEPARATOR.'skeleton';
    $outside = $base.DIRECTORY_SEPARATOR.'outside';
    $destination = $base.DIRECTORY_SEPARATOR.'destination';

    $files->ensureDirectoryExists($skeleton.'/app');
    $files->ensureDirectoryExists($outside.'/secret');

    file_put_contents($skeleton.'/app/Keep.php', '<?php // a real file that must be copied');
    file_put_contents($outside.'/secret/Leaked.php', '<?php // must never reach the destination');
    file_put_contents($outside.'/Target.php', '<?php // must never reach the destination');

    // Three shapes: the vendor link that is excluded by name, a directory link
    // that is not, and a file link. Only the first is covered by the exclusion.
    $linked = @symlink($outside, $skeleton.'/vendor')
        && @symlink($outside.'/secret', $skeleton.'/linked-directory')
        && @symlink($outside.'/Target.php', $skeleton.'/app/LinkedFile.php');

    if (! $linked) {
        // Windows without developer mode cannot create symlinks. Skipping is
        // honest here: the risk this guards does not arise without them.
        foreach (['/vendor', '/linked-directory', '/app/LinkedFile.php'] as $link) {
            is_link($skeleton.$link) && unlink($skeleton.$link);
        }

        $files->deleteDirectory($base);

        test()->markTestSkipped('This platform does not allow creating symlinks.');
    }

    try {
        copySkeletonUnderTest($files, $skeleton, $destination);

        expect(file_exists($destination.'/app/Keep.php'))->toBeTrue('Expecting real files to be copied')
            ->and(file_exists($destination.'/vendor'))->toBeFalse('The excluded vendor link was copied')
            ->and(file_exists($destination.'/linked-directory'))->toBeFalse('A non-vendor directory symlink was followed')
            ->and(file_exists($destination.'/app/LinkedFile.php'))->toBeFalse('A symlinked file was copied, bringing its target contents in');

        // Nothing from outside the skeleton may appear anywhere in the copy.
        $copied = collect($files->allFiles($destination))
            ->map(fn ($file) => $file->getFilename())
            ->all();

        expect($copied)->not->toContain('Leaked.php')
            ->and($copied)->not->toContain('Target.php')
            ->and($copied)->toContain('Keep.php');
    } finally {
        // Unlink first: deleting through a symlinked directory would follow it
        // and take the target's contents with it.
        foreach (['/vendor', '/linked-directory', '/app/LinkedFile.php'] as $link) {
            is_link($skeleton.$link) && unlink($skeleton.$link);
        }

        $files->deleteDirectory($base);
    }
});
