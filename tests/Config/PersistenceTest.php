<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\RuntimeException as ProcessException;
use Tey\LaravelDDD\ConfigManager;
use Tey\LaravelDDD\Facades\DDD;

// Writing the config file is its own concern, separate from deciding what goes
// in it. save() rendered the file by editing the manager's own values, staged it
// through one fixed path shared by every call, and then copy()'d over the
// destination — which truncates it before writing — without checking any of it.

beforeEach(function () {
    $this->cleanSlate();
});

/**
 * Write a consumer's config file and return a manager reading it.
 *
 * Named apart from the merge suite's helper so the two files stay independent.
 */
function persistedConfig(array $config): ConfigManager
{
    $path = config_path('ddd.php');

    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, '<?php return '.var_export($config, true).';');

    return new ConfigManager($path);
}

/**
 * Staged files left lying beside the config file.
 *
 * The directory holds the application's own config files, so this looks for the
 * staged ones specifically — they are named after the destination.
 */
function strayFilesBeside(string $path): array
{
    return array_values(array_filter(
        glob($path.'.*') ?: [],
        fn ($candidate) => $candidate !== $path,
    ));
}

/**
 * A stream that accepts one byte fewer than it is given.
 *
 * A short write is otherwise hard to produce on demand — it wants a full disk —
 * and it is the case that matters: file_put_contents() reports a NUMBER, not
 * false, so a save that only checks for false publishes a truncated file.
 */
class ShortWritingStream
{
    public $context;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        return true;
    }

    public function stream_write($data): int
    {
        return max(0, strlen($data) - 1);
    }

    public function stream_lock($operation): bool
    {
        return true;
    }

    public function stream_close(): void {}

    public function url_stat($path, $flags)
    {
        return false;
    }
}

/**
 * Whether a file can still be created in a directory.
 *
 * Asked by trying, because the permission flags do not answer it portably. The
 * probe is uniquely named so it cannot collide with anything the suite owns, and
 * it is removed again whether or not the write succeeded.
 */
function directoryStillAcceptsWrites(string $directory): bool
{
    $probe = $directory.DIRECTORY_SEPARATOR.'ddd-write-probe-'.getmypid().'-'.bin2hex(random_bytes(6));

    $written = @file_put_contents($probe, 'probe');

    if (is_file($probe)) {
        @unlink($probe);
    }

    return $written !== false;
}

/**
 * Create a symbolic link, or skip when the platform will not allow one at all.
 *
 * $workingDirectory is where the link is created FROM, which matters for a
 * relative target: Windows resolves one against the working directory rather
 * than against the link's own directory, so a relative link has to be made from
 * beside the link for the target to be found. The directory is restored
 * whatever happens.
 *
 * Only a genuine inability to create links — Windows without the privilege —
 * ends in a skip.
 */
function symlinkOrSkip(string $target, string $link, ?string $workingDirectory = null): void
{
    $previous = getcwd();

    try {
        if ($workingDirectory !== null) {
            chdir($workingDirectory);
        }

        @symlink($target, $link);
    } finally {
        if ($workingDirectory !== null && is_string($previous)) {
            chdir($previous);
        }
    }

    if (! is_link($link)) {
        test()->markTestSkipped('Symbolic links cannot be created here.');
    }
}

/**
 * TEMPORARY DIAGNOSTIC — remove once the Windows symlink behaviour is proved.
 *
 * Every question the failing Windows jobs raise, asked at one moment and
 * printed: what the link is according to each API, what it resolves to,
 * whether that resolved path exists, and whether any of it changes once the
 * stat cache is cleared. Everything here is synthetic fixture data.
 */
function dumpLinkFacts(string $moment, string $link, string $target): void
{
    $read = function (string $link, string $target): array {
        $resolved = realpath($link);
        $raw = @readlink($link);
        $lstat = @lstat($link);

        return [
            'is_link' => var_export(is_link($link), true),
            'is_file' => var_export(is_file($link), true),
            'file_exists' => var_export(file_exists($link), true),
            'is_dir' => var_export(is_dir($link), true),
            'realpath' => var_export($resolved, true),
            'realpath_exists' => var_export($resolved !== false && file_exists($resolved), true),
            'readlink' => var_export($raw, true),
            'lstat_mode' => $lstat === false ? 'false' : decoct($lstat['mode'] & 0170000),
            'target_exists' => var_export(file_exists($target), true),
            'target_base_model' => var_export(
                is_file($target) ? ((array) (include $target))['base_model'] ?? '(absent)' : '(no file)',
                true
            ),
        ];
    };

    $before = $read($link, $target);

    // The comparison the brief asks for: anything that differs here was being
    // answered from PHP's stat cache rather than from the filesystem.
    clearstatcache(true);

    $after = $read($link, $target);

    $line = "[ddd-diag] {$moment} cwd=".getcwd();

    foreach ($after as $key => $value) {
        $line .= " {$key}={$value}";

        if ($before[$key] !== $value) {
            $line .= "(cached:{$before[$key]})";
        }
    }

    fwrite(STDERR, $line.PHP_EOL);
}

it('leaves its own values alone when saving, and saves the same file again', function () {
    // save() used to hide each namespace separator behind a marker and write the
    // result back through set(). The FILE was correct, so this was invisible
    // there — but the manager was left holding
    // 'Domain[[BACKSLASH]]Shared[[BACKSLASH]]Models[[BACKSLASH]]Base', and
    // anything reading the config from the container after a save saw that.
    $path = config_path('ddd.php');

    $config = persistedConfig([
        'domain_namespace' => 'Domain',
        'base_model' => 'Domain\Shared\Models\Base',
        'namespaces' => ['model' => 'Models'],
    ])->syncWithLatest();

    $config->save();

    expect($config->get('base_model'))->toBe('Domain\Shared\Models\Base')
        ->and($config->get('domain_namespace'))->toBe('Domain');

    $afterFirstSave = file_get_contents($path);

    // Saving the same manager twice must produce the same file. With the values
    // corrupted in place, the second render started from marker-laden strings.
    $config->save();

    expect(file_get_contents($path))->toBe($afterFirstSave)
        ->and($config->get('base_model'))->toBe('Domain\Shared\Models\Base')
        ->and((include $path)['base_model'])->toBe('Domain\Shared\Models\Base');

    unlink($path);
});

it('renders without touching the values it renders from', function () {
    $config = persistedConfig(['base_model' => 'Domain\Shared\Models\Base'])->syncWithLatest();

    $before = $config->get();

    expect($config->render())->toBeString()->toContain("'Domain\\Shared\\Models\\Base'")
        ->and($config->get())->toBe($before);

    unlink(config_path('ddd.php'));
});

it('stages beside the destination and removes the staged file afterwards', function () {
    // Every save wrote to sys_get_temp_dir()/ddd.php: one fixed name, shared by
    // every process on the machine, and left behind after the copy. The staged
    // file now sits beside the destination — same filesystem, so it can be moved
    // into place in one step — and is removed either way.
    // Snapshotted, never touched: this path is not ours to delete, and another
    // process on this machine may legitimately own a file there.
    $sharedPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ddd.php';
    $sharedBefore = is_file($sharedPath) ? md5_file($sharedPath) : null;

    $path = config_path('ddd.php');

    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, '<?php return '.var_export(['base_model' => 'Domain\Shared\Models\Base'], true).';');

    $config = new class($path) extends ConfigManager
    {
        public array $stagingPaths = [];

        public array $existedDuringFormat = [];

        protected function stagingPath(string $destination): string
        {
            return $this->stagingPaths[] = parent::stagingPath($destination);
        }

        protected function format(string $path, string $rendered): void
        {
            // The staged file is present at this point, so the assertions below
            // distinguish "cleaned up" from "never written".
            $this->existedDuringFormat[] = file_exists($path);

            parent::format($path, $rendered);
        }
    };

    $config->syncWithLatest()->save();
    $config->save();

    expect($config->existedDuringFormat)->toBe([true, true])
        ->and($config->stagingPaths)->toHaveCount(2)
        ->and($config->stagingPaths[0])->not->toBe($config->stagingPaths[1]);

    foreach ($config->stagingPaths as $stagingPath) {
        expect(dirname($stagingPath))->toBe(dirname($path))
            // Laravel loads every .php file in the config directory; a partly
            // written one must not be among them.
            ->and(str_ends_with($stagingPath, '.php'))->toBeFalse()
            ->and(file_exists($stagingPath))->toBeFalse("save() left {$stagingPath} behind");
    }

    expect(is_file($sharedPath) ? md5_file($sharedPath) : null)
        ->toBe($sharedBefore, 'save() must not write to a fixed shared path')
        ->and(strayFilesBeside($path))->toBe([]);

    unlink($path);
});

it('does not disturb another save that is already in progress', function () {
    // Genuinely interleaved rather than merely sequential: the inner save runs
    // while the outer one is holding its own staged file, which is exactly the
    // situation the shared path could not survive.
    $outerPath = config_path('ddd.php');
    $innerPath = config_path('ddd-other.php');

    File::ensureDirectoryExists(dirname($outerPath));
    file_put_contents($outerPath, '<?php return '.var_export(['base_model' => 'Domain\Outer\Model'], true).';');
    file_put_contents($innerPath, '<?php return '.var_export(['base_model' => 'Domain\Inner\Model'], true).';');

    $inner = new ConfigManager($innerPath);
    $inner->syncWithLatest();

    $outer = new class($outerPath, $inner) extends ConfigManager
    {
        public function __construct(string $path, protected ConfigManager $other)
        {
            parent::__construct($path);
        }

        protected function format(string $path, string $rendered): void
        {
            $this->other->save();

            parent::format($path, $rendered);
        }
    };

    $outer->syncWithLatest()->save();

    expect((include $outerPath)['base_model'])->toBe('Domain\Outer\Model')
        ->and((include $innerPath)['base_model'])->toBe('Domain\Inner\Model');

    unlink($outerPath);
    unlink($innerPath);
});

it('reads the config file at the path it resolved when none was given', function () {
    // The constructor assigned a default to $this->configPath and then tested
    // the original argument, so a manager built without one always started from
    // the package defaults — and saving it wrote those defaults over whatever
    // the consumer had published.
    $path = config_path('ddd.php');

    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, '<?php return '.var_export([
        'domain_path' => 'src/Consumer',
        'base_model' => 'Domain\Consumer\Model',
    ], true).';');

    expect($path)->toBe(app()->configPath('ddd.php'));

    $config = new ConfigManager;

    expect($config->get('base_model'))->toBe('Domain\Consumer\Model')
        ->and($config->get('domain_path'))->toBe('src/Consumer');

    unlink($path);
});

it('still writes a complete file when no formatter is available', function () {
    // Formatting is best-effort and stays that way: the previous call discarded
    // the process result, so consumers have been saving successfully without a
    // usable binary. A save must not start failing over formatting.
    $path = config_path('ddd.php');

    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, '<?php return '.var_export(['base_model' => 'Domain\Shared\Models\Base'], true).';');

    $config = new class($path) extends ConfigManager
    {
        protected function formatterPath(): ?string
        {
            return null;
        }
    };

    $config->syncWithLatest()->save();

    expect((include $path)['base_model'])->toBe('Domain\Shared\Models\Base')
        ->and(include $path)->toHaveKeys(array_keys(require DDD::packagePath('config/ddd.php')));

    unlink($path);
});

it('still writes a complete file when the formatter fails', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1)]);

    $path = config_path('ddd.php');

    persistedConfig(['base_model' => 'Domain\Shared\Models\Base'])->syncWithLatest()->save();

    expect((include $path)['base_model'])->toBe('Domain\Shared\Models\Base');

    unlink($path);
});

it('publishes the rendered file rather than what a broken formatter left behind', function () {
    // A formatter that fails part way through rewriting the file leaves it
    // truncated. Best-effort means the save still succeeds; it does not mean
    // publishing the wreckage.
    $path = config_path('ddd.php');

    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, '<?php return '.var_export(['base_model' => 'Domain\Shared\Models\Base'], true).';');

    $config = new class($path) extends ConfigManager
    {
        public bool $reportSuccess = false;

        protected function runFormatter(string $path): bool
        {
            file_put_contents($path, '<?php return [');

            return $this->reportSuccess;
        }
    };

    $config->syncWithLatest()->save();

    expect((include $path)['base_model'])->toBe('Domain\Shared\Models\Base')
        ->and(include $path)->toHaveKeys(array_keys(require DDD::packagePath('config/ddd.php')));

    // And the same when the formatter truncates the file but claims it worked,
    // which is why the staged file is inspected rather than the exit code alone.
    $config->reportSuccess = true;

    $config->save();

    expect((include $path)['base_model'])->toBe('Domain\Shared\Models\Base');

    unlink($path);
});

it('leaves the existing configuration in place when it cannot be written', function () {
    $path = config_path('ddd.php');

    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, '<?php return '.var_export(['base_model' => 'Domain\Untouched\Model'], true).';');

    $original = file_get_contents($path);

    $config = new ConfigManager($path);
    $config->syncWithLatest();

    // The directory rather than the file: staging and replacement both need to
    // create and move a file in it, so this fails deterministically wherever
    // directory permissions are enforced.
    // Restored in a finally below, including when an assertion throws: a
    // directory left at 0555 takes the rest of the suite down with it.
    $originalMode = fileperms(dirname($path)) & 0777;

    chmod(dirname($path), 0555);

    try {
        if (directoryStillAcceptsWrites(dirname($path))) {
            // Not a permission flag but an actual write. is_writable() reports
            // false for a Windows directory carrying the read-only attribute
            // even though files can still be created in it, so asking the flag
            // let this run there against a save that had every right to succeed.
            $this->markTestSkipped('Writes to the config directory still succeed after chmod here.');
        }

        expect(fn () => $config->save())->toThrow(RuntimeException::class);
    } finally {
        chmod(dirname($path), $originalMode);
    }

    // Byte for byte. copy() truncates the destination before writing, so a
    // failure part way through used to leave a damaged file behind.
    expect(file_get_contents($path))->toBe($original)
        ->and(strayFilesBeside($path))->toBe([]);

    unlink($path);
});

it('fails rather than publishing a short write', function () {
    // file_put_contents() returns the number of bytes written. A disk that fills
    // mid-write returns a number, not false, so testing only for false published
    // a truncated file.
    $path = config_path('ddd.php');

    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, '<?php return '.var_export(['base_model' => 'Domain\Untouched\Model'], true).';');

    $original = file_get_contents($path);

    $registered = ! in_array('ddd-short', stream_get_wrappers(), true)
        && stream_wrapper_register('ddd-short', ShortWritingStream::class);

    $config = new class($path) extends ConfigManager
    {
        protected function stagingPath(string $destination): string
        {
            return 'ddd-short://stage';
        }
    };

    $config->syncWithLatest();

    try {
        expect(fn () => $config->save())->toThrow(RuntimeException::class, 'wrote');

        expect(file_get_contents($path))->toBe($original);
    } finally {
        // Only what this test put there, and only if it put it there.
        if ($registered) {
            stream_wrapper_unregister('ddd-short');
        }
    }

    unlink($path);
});

it('writes values whose text looks like the template', function () {
    // The stub is filled by substitution, and the values being substituted in
    // are consumer data. Replacing placeholders one after another meant a value
    // whose own text contained {{another_key}} was rewritten by a later
    // replacement; hiding namespace separators behind a fixed marker meant a
    // value containing that marker's text was rewritten on the way out.
    $path = config_path('ddd.php');

    $values = [
        'domain_path' => 'src/{{layers}}/Domain',
        'base_model' => 'Domain\Shared\{{base_dto}}\Model',
        'base_dto' => 'Literal [[BACKSLASH]] value',
        'base_action' => '{{cache_directory}}',
    ];

    persistedConfig($values)->syncWithLatest()->save();

    $saved = include $path;

    foreach ($values as $key => $value) {
        expect($saved[$key])->toBe($value, "{$key} did not round trip");
    }

    // The placeholder it names is still filled with its own value rather than
    // consumed by the one above.
    expect($saved['cache_directory'])->toBe((require DDD::packagePath('config/ddd.php'))['cache_directory']);

    unlink($path);
});

it('round trips values that cannot show their separators literally', function () {
    // Separators are shown unescaped because the published file has always
    // looked that way, but only where that reads back identically: inside single
    // quotes a backslash matters before another backslash or a quote, and a
    // trailing one would swallow the closing quote entirely.
    $path = config_path('ddd.php');

    $values = [
        'base_model' => 'Domain\Shared\Models\Base',
        'base_dto' => 'Domain\\\\Doubled\\\\Separators',
        'base_action' => 'Domain\Trailing\\',
        'base_view_model' => "Domain\\O'Hara\\ViewModel",
        'namespaces' => [
            'model' => 'Models\\\\Nested',
            'factory' => 'Factories\\',
        ],
    ];

    persistedConfig($values)->syncWithLatest()->save();

    $saved = include $path;

    expect($saved['base_model'])->toBe($values['base_model'])
        ->and($saved['base_dto'])->toBe($values['base_dto'])
        ->and($saved['base_action'])->toBe($values['base_action'])
        ->and($saved['base_view_model'])->toBe($values['base_view_model'])
        ->and($saved['namespaces']['model'])->toBe($values['namespaces']['model'])
        ->and($saved['namespaces']['factory'])->toBe($values['namespaces']['factory']);

    // The ordinary case still reads the way the published config always has.
    expect(file_get_contents($path))->toContain("'Domain\\Shared\\Models\\Base'");

    // And a second round trip leaves all of it alone.
    (new ConfigManager($path))->syncWithLatest()->save();

    expect(include $path)->toBe($saved);

    unlink($path);
});

it('keeps the permissions the configuration file already had', function () {
    // Replacing a file by renaming another one over it replaces its permissions
    // too, and the staged file is created under the process umask — so a config
    // deliberately kept at 0600 would come back 0644 after an upgrade.
    if (DIRECTORY_SEPARATOR === '\\') {
        $this->markTestSkipped('Windows has no mode bits to carry over; the save does not try.');
    }

    $path = config_path('ddd.php');

    $config = persistedConfig(['base_model' => 'Domain\Shared\Models\Base'])->syncWithLatest();

    chmod($path, 0600);

    $config->save();

    expect(fileperms($path) & 0777)->toBe(0600)
        ->and((include $path)['base_model'])->toBe('Domain\Shared\Models\Base');

    chmod($path, 0644);
    unlink($path);
});

it('formats a staged file despite its extension', function () {
    // The staged file is deliberately not a .php file, and the formatter is
    // given an explicit path rather than a directory to scan. Whether it acts on
    // that path is a fact about the tool, not something to assume: if it quietly
    // skipped the file, every saved config would go out unformatted and the save
    // would still report success.
    $formatter = (new class extends ConfigManager
    {
        public function binary(): ?string
        {
            return $this->formatterPath();
        }
    })->binary();

    if ($formatter === null) {
        $this->markTestSkipped('No formatter binary is resolvable here.');
    }

    $ugly = '<?php'.PHP_EOL.'return [   "a"=>1,'.PHP_EOL.'  "b" =>2 ];'.PHP_EOL;

    $staged = config_path('ddd.php.formatting.tmp');
    $plain = config_path('ddd-formatting.php');

    File::ensureDirectoryExists(dirname($staged));
    file_put_contents($staged, $ugly);
    file_put_contents($plain, $ugly);

    Process::run([$formatter, $staged]);
    Process::run([$formatter, $plain]);

    $formatted = file_get_contents($staged);

    expect($formatted)->not->toBe($ugly, 'the formatter did not touch the staged file')
        // And it did the same thing it would have done to a .php file.
        ->and($formatted)->toBe(file_get_contents($plain));

    unlink($staged);
    unlink($plain);
});

it('writes through a symlinked configuration file rather than replacing the link', function (string $description, bool $relative) {
    // copy() wrote THROUGH a link. rename() replaces the link itself, so
    // without resolving it first, a consumer pointing config/ddd.php at a
    // shared or release-managed file would find their link quietly turned into
    // a regular file and the real target left stale.
    $link = config_path('ddd.php');
    $target = base_path('shared/ddd.php');

    File::ensureDirectoryExists(dirname($target));
    file_put_contents($target, '<?php return '.var_export(['base_model' => 'Domain\Shared\Models\Base'], true).';');

    // A relative target is stored as written and resolves against the LINK's
    // directory, which is the case a naive readlink() would get wrong. The link
    // is created from that directory so the relative form is portable — Windows
    // resolves the target against the working directory when making the link.
    symlinkOrSkip(
        $relative ? '../shared/ddd.php' : $target,
        $link,
        $relative ? dirname($link) : null,
    );

    // What the link resolves to, which every platform can answer. PHP's
    // readlink() fails with ERROR_INVALID_NAME on a Windows link that stores a
    // relative target, so the raw form is compared further down only where it
    // can be read at all.
    $resolvedBefore = realpath($link);
    $rawBefore = @readlink($link);

    dumpLinkFacts("{$description}: after creating the link", $link, $target);

    $config = new ConfigManager($link);

    // What the manager READ. A save that publishes package defaults over a
    // consumer's file means this came back null rather than their value.
    fwrite(STDERR, '[ddd-diag] '.$description.': constructed base_model='
        .var_export($config->get('base_model'), true).PHP_EOL);

    dumpLinkFacts("{$description}: before saving", $link, $target);

    $config->syncWithLatest()->save();

    dumpLinkFacts("{$description}: after saving", $link, $target);

    expect(is_link($link))->toBeTrue("{$description}: the link itself was replaced")
        ->and(realpath($link))->toBe($resolvedBefore, "{$description}: the link now resolves elsewhere")
        // Written through the link: the target carries the new contents, and
        // reading the link path gives the same thing.
        ->and((include $target)['base_model'])->toBe('Domain\Shared\Models\Base')
        ->and(include $target)->toHaveKeys(array_keys(require DDD::packagePath('config/ddd.php')))
        ->and(include $link)->toBe(include $target)
        ->and(strayFilesBeside($target))->toBe([])
        ->and(strayFilesBeside($link))->toBe([]);

    if (is_string($rawBefore)) {
        // Where readlink() works, the stronger statement: the target was
        // preserved exactly as written, relative form and all.
        expect(readlink($link))->toBe($rawBefore, "{$description}: the stored target changed");
    }

    unlink($link);
    unlink($target);
})->with([
    'absolute target' => ['absolute target', false],
    'relative target' => ['relative target', true],
]);

it('keeps the permissions of a symlinked target', function () {
    if (DIRECTORY_SEPARATOR === '\\') {
        $this->markTestSkipped('Windows has no mode bits to carry over; the save does not try.');
    }

    $link = config_path('ddd.php');
    $target = base_path('shared/ddd.php');

    File::ensureDirectoryExists(dirname($target));
    file_put_contents($target, '<?php return '.var_export(['base_model' => 'Domain\Shared\Models\Base'], true).';');
    chmod($target, 0600);

    symlinkOrSkip($target, $link);

    (new ConfigManager($link))->syncWithLatest()->save();

    expect(fileperms($target) & 0777)->toBe(0600)
        ->and(is_link($link))->toBeTrue();

    chmod($target, 0644);
    unlink($link);
    unlink($target);
});

it('leaves a symlink and its target alone when the target cannot be written', function () {
    $link = config_path('ddd.php');
    $target = base_path('shared/ddd.php');

    File::ensureDirectoryExists(dirname($target));
    file_put_contents($target, '<?php return '.var_export(['base_model' => 'Domain\Untouched\Model'], true).';');

    symlinkOrSkip($target, $link);

    $original = file_get_contents($target);
    $originalMode = fileperms(dirname($target)) & 0777;

    chmod(dirname($target), 0555);

    try {
        if (directoryStillAcceptsWrites(dirname($target))) {
            $this->markTestSkipped('Writes to the target directory still succeed after chmod here.');
        }

        $config = new ConfigManager($link);
        $config->syncWithLatest();

        expect(fn () => $config->save())->toThrow(RuntimeException::class);
    } finally {
        chmod(dirname($target), $originalMode);
    }

    expect(file_get_contents($target))->toBe($original)
        ->and(is_link($link))->toBeTrue()
        ->and(strayFilesBeside($target))->toBe([]);

    unlink($link);
    unlink($target);
});

it('refuses a symlink whose target does not exist, and leaves the link alone', function () {
    // A DELIBERATE COMPATIBILITY LIMIT. copy() would have created the missing
    // target; this implementation requires an existing resolved target, so this
    // fails loudly instead of quietly replacing the link with a regular file.
    $link = config_path('ddd.php');
    $missing = base_path('shared/never-written.php');

    // Linked while the target exists and then emptied out, because Windows
    // cannot create a link to something that is not there — it resolves the
    // target to decide between a file and a directory link. The result is the
    // same dangling link on both platforms.
    File::ensureDirectoryExists(dirname($missing));
    file_put_contents($missing, '<?php return [];');

    symlinkOrSkip($missing, $link);

    unlink($missing);

    // Constructing it must not blow up either. file_exists() answers about the
    // LINK on Windows, so a link leading nowhere used to send the constructor
    // into a require of a file that is not there; is_file() asks about the
    // target, and a link with none falls back to the package defaults.
    dumpLinkFacts('dangling: after creating the link', $link, $missing);

    $config = new ConfigManager($link);

    expect($config->get())->toHaveKeys(array_keys(require DDD::packagePath('config/ddd.php')));

    $config->syncWithLatest();

    dumpLinkFacts('dangling: before saving', $link, $missing);

    // The same expectation as before, taken apart only so the facts below are
    // printed even when it fails. It still fails in exactly the same case.
    $thrown = null;

    try {
        $config->save();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    fwrite(STDERR, '[ddd-diag] dangling: save threw '
        .($thrown === null ? 'nothing' : get_class($thrown).' — '.$thrown->getMessage()).PHP_EOL);

    dumpLinkFacts('dangling: after the save attempt', $link, $missing);

    expect($thrown)->toBeInstanceOf(RuntimeException::class);
    expect($thrown?->getMessage())->toContain('symbolic link');

    expect(is_link($link))->toBeTrue('the link was removed')
        ->and(readlink($link))->toBe($missing)
        ->and(file_exists($missing))->toBeFalse()
        ->and(strayFilesBeside($link))->toBe([]);

    unlink($link);
});

it('publishes the configuration when the formatter cannot be launched', function () {
    // formatterPath() only checks that the file is there, so a file that cannot
    // be run gets as far as being run. What happens then is the operating
    // system's business: macOS hands back exit code 126, other systems report a
    // failed launch and Symfony raises. This pins the outcome that must hold
    // either way — the save publishes — while the test below pins the raising
    // case against the exact exception, since this one cannot produce it here.
    $path = config_path('ddd.php');

    $binary = base_path('not-executable-pint');

    File::ensureDirectoryExists(dirname($binary));
    file_put_contents($binary, "#!/bin/sh\necho nope\n");
    chmod($binary, 0644);

    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, '<?php return '.var_export(['base_model' => 'Domain\Shared\Models\Base'], true).';');

    $config = new class($path, $binary) extends ConfigManager
    {
        public function __construct(string $path, protected string $binary)
        {
            parent::__construct($path);
        }

        protected function formatterPath(): ?string
        {
            return $this->binary;
        }
    };

    $config->syncWithLatest()->save();

    expect((include $path)['base_model'])->toBe('Domain\Shared\Models\Base')
        ->and(include $path)->toHaveKeys(array_keys(require DDD::packagePath('config/ddd.php')));

    unlink($binary);
    unlink($path);
});

it('publishes the configuration when running the formatter raises', function () {
    // The same guarantee, stated against the exact exception rather than
    // whatever the operating system happens to do with a non-executable file:
    // some report a failed launch, others just hand back a non-zero exit code.
    Process::fake([
        '*' => new ProcessException('The process "vendor/bin/pint" could not be started.'),
    ]);

    $path = config_path('ddd.php');

    persistedConfig(['base_model' => 'Domain\Shared\Models\Base'])->syncWithLatest()->save();

    expect((include $path)['base_model'])->toBe('Domain\Shared\Models\Base')
        ->and(include $path)->toHaveKeys(array_keys(require DDD::packagePath('config/ddd.php')));

    unlink($path);
});

it('replaces a destination that already exists', function () {
    // rename() within one directory, which replaces the destination in a single
    // step on both platforms. The destination always exists after the first save.
    $path = config_path('ddd.php');

    persistedConfig(['base_model' => 'Domain\First\Model'])->syncWithLatest()->save();

    expect((include $path)['base_model'])->toBe('Domain\First\Model');

    $second = new ConfigManager($path);
    $second->set('base_model', 'Domain\Second\Model');
    $second->save();

    expect(file_exists($path))->toBeTrue()
        ->and(require $path)->toBeArray()
        ->and(file_get_contents($path))->toContain('Domain\Second\Model')
        ->not->toContain('Domain\First\Model');

    unlink($path);
});

it('keeps a top-level key the package does not know about', function () {
    // The stub has a placeholder per package key and nothing else, so a key an
    // extension added survived syncWithLatest() in memory and was then dropped
    // by save(). It is appended to the rendered file instead.
    $path = config_path('ddd.php');

    persistedConfig([
        'some_extension_key' => ['a' => 1],
        'another_extension_key' => 'kept',
        'a key with {{braces}} and \\ in it' => 'also kept',
    ])->syncWithLatest()->save();

    $saved = include $path;

    expect($saved['some_extension_key'])->toBe(['a' => 1])
        ->and($saved['another_extension_key'])->toBe('kept')
        ->and($saved['a key with {{braces}} and \\ in it'])->toBe('also kept')
        ->and($saved)->toHaveKeys(array_keys(require DDD::packagePath('config/ddd.php')));

    // And through a second round trip, so the appended keys are read back and
    // written out again unchanged.
    (new ConfigManager($path))->syncWithLatest()->save();

    expect(include $path)->toBe($saved);

    unlink($path);
});

it('keeps the template and its comments', function () {
    $path = config_path('ddd.php');

    persistedConfig(['some_extension_key' => 'kept'])->syncWithLatest()->save();

    expect(file_get_contents($path))
        ->toContain('/*')
        ->toContain('Autoloading');

    unlink($path);
});
