<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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

        protected function stagingPath(): string
        {
            return $this->stagingPaths[] = parent::stagingPath();
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
        if (is_writable(dirname($path))) {
            $this->markTestSkipped('The config directory could not be made read-only here.');
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
        protected function stagingPath(): string
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
