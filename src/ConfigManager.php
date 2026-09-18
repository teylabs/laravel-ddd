<?php

namespace Tey\LaravelDDD;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessException;
use Symfony\Component\VarExporter\VarExporter;
use Tey\LaravelDDD\Facades\DDD;

class ConfigManager
{
    protected array $packageConfig;

    protected array $config;

    protected string $stub;

    public function __construct(public ?string $configPath = null)
    {
        $this->configPath = $configPath ?? app()->configPath('ddd.php');

        $this->packageConfig = require DDD::packagePath('config/ddd.php');

        // Read the resolved path, not the nullable argument: constructed without
        // one, this used to fall back to package defaults even when the consumer
        // had a config file sitting exactly where configPath points.
        $this->config = file_exists($this->configPath) ? require $this->configPath : $this->packageConfig;

        $this->stub = file_get_contents(DDD::packagePath('config/ddd.php.stub'));
    }

    /**
     * Top-level keys whose value is a collection the consumer owns outright.
     *
     * Most maps here have a key set the package defines — autoload and
     * namespaces list every option the package supports, so a new one has to
     * appear on sync. `layers` is different: the documentation describes it as
     * "additional top-level namespaces and paths", the entries are the
     * consumer's own, and Infrastructure ships as an example rather than as a
     * key the package owns. Merging defaults into it would put back a layer the
     * consumer had deleted.
     */
    protected const CONSUMER_OWNED_KEYS = [
        'layers',
    ];

    public function resolve($path, $value)
    {
        $path = Arr::wrap($path);

        return data_get($this->config, $path, $value);
    }

    /**
     * Merge the package defaults for an array option into what is already there.
     *
     * Kept as the extension point it has always been. Sync dispatches EVERY array
     * option through here and every scalar through resolve(), so a subclass
     * overriding either takes part in the merge exactly as it used to. The path
     * keeps its original shape too: a string for a top-level option, an array for
     * anything nested.
     *
     * What changed is the direction. This used to rebuild the value from the
     * package's array, which discarded any key the package did not also define,
     * and filled list gaps by numeric position. Now defaults are merged into what
     * is there, and a collection the consumer owns is returned whole.
     */
    protected function mergeArray($path, $array)
    {
        if ($this->isConsumerOwnedCollection($path, $array)) {
            // Their list, their layers: taken as given, including when empty.
            return $this->lookup($path, $array);
        }

        $existing = $this->lookup($path, []);

        if (! is_array($existing)) {
            // The consumer replaced the option with something that is not an
            // array at all. That is their decision; leave it alone.
            return $existing;
        }

        $merged = $existing;

        foreach ($array as $key => $default) {
            $merged[$key] = $this->valueFor([...Arr::wrap($path), $key], $default);
        }

        return $merged;
    }

    /**
     * The consumer's value at a path, or the given default.
     *
     * Deliberately not resolve(): resolve() has only ever been handed leaf
     * values — the sync walked down to the scalars and called it there — so an
     * override written against that contract can reasonably expect a scalar,
     * and handing it a whole collection would break it. mergeArray() is the
     * hook for arrays, and it is still the one that classifies and merges them.
     */
    protected function lookup($path, mixed $default): mixed
    {
        return data_get($this->config, Arr::wrap($path), $default);
    }

    /**
     * The value an option should end up with after syncing.
     *
     * @param  string|array  $path
     */
    protected function valueFor($path, mixed $default): mixed
    {
        // resolve() returns what the consumer has, or the default when they have
        // nothing, so an explicit null, false or empty value survives.
        return is_array($default)
            ? $this->mergeArray($path, $default)
            : $this->resolve($path, $default);
    }

    /**
     * Whether an option is a collection the consumer owns rather than a map of
     * package-defined keys.
     *
     * Decided from the PACKAGE's default, never from what the consumer happens
     * to hold. Asking array_is_list() of the consumer's value would call an
     * empty `autoload => []` a list — empty arrays are lists in PHP — and that
     * option would then never receive a newly supported key.
     *
     * A list is consumer-owned because its entries carry no identity to merge
     * on: filling gaps could only mean merging by position, which is how
     * removing an entry from application_objects used to reinstate whichever
     * default sat at that index. That positional merging is deliberately gone
     * and is not reproduced for the sake of matching the old inner calls.
     *
     * @param  string|array  $path
     */
    protected function isConsumerOwnedCollection($path, array $default): bool
    {
        return array_is_list($default)
            || in_array(implode('.', Arr::wrap($path)), static::CONSUMER_OWNED_KEYS, true);
    }

    public function syncWithLatest()
    {
        // Start from what the consumer has, so a key the package does not define
        // is carried over rather than dropped, then bring each package option up
        // to date through the hooks above.
        $fresh = $this->config;

        foreach ($this->packageConfig as $key => $default) {
            // The key is passed as a string, the shape a top-level option was
            // always dispatched with.
            $fresh[$key] = $this->valueFor($key, $default);
        }

        $this->config = $fresh;

        return $this;
    }

    public function get($key = null)
    {
        if (is_null($key)) {
            return $this->config;
        }

        return data_get($this->config, $key);
    }

    public function set($key, $value)
    {
        data_set($this->config, $key, $value);

        return $this;
    }

    public function fill($values)
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return $this;
    }

    /**
     * Keys whose values carry namespace separators.
     */
    protected const KEYS_WITH_NAMESPACES = [
        'domain_namespace',
        'application_namespace',
        'layers',
        'namespaces',
        'base_model',
        'base_dto',
        'base_view_model',
        'base_action',
    ];

    /**
     * A marker that stands in for a namespace separator while a value is
     * exported, and cannot collide with anything being exported.
     *
     * The separators are hidden because VarExporter escapes a backslash, and the
     * published file has always shown 'Domain\Shared\Models' rather than
     * 'Domain\\Shared\\Models'. A FIXED marker made that unsafe: a value that
     * happened to contain the marker's own text was rewritten on the way out.
     * This one is random per render and checked against everything being
     * written, so it stands for a separator and nothing else.
     */
    protected function separatorMarker(array $config): string
    {
        $haystack = $this->stub.serialize($config);

        do {
            $marker = '__ddd_ns_'.bin2hex(random_bytes(8)).'__';
        } while (str_contains($haystack, $marker));

        return $marker;
    }

    /**
     * Whether a string's separators can be shown literally.
     *
     * Inside single quotes a backslash only means something before another
     * backslash or a quote, so 'Domain\Shared' reads back exactly as written —
     * but 'Domain\\Shared' reads back as one backslash, and 'Domain\' does not
     * terminate the string at all. Anything in that territory is left alone and
     * exported with its escaping intact, which is correct if less pretty. This
     * is about the file's appearance; it is never about what it means.
     */
    protected function canShowSeparatorsLiterally(string $value): bool
    {
        return ! str_contains($value, "'")
            && ! str_contains($value, '\\\\')
            && ! str_ends_with($value, '\\');
    }

    protected function hideSeparators(mixed $value, string $marker): mixed
    {
        if (is_string($value)) {
            return $this->canShowSeparatorsLiterally($value)
                ? str_replace('\\', $marker, $value)
                : $value;
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->hideSeparators($item, $marker), $value);
        }

        // null, false and anything else the consumer set deliberately: untouched.
        return $value;
    }

    /**
     * The exported PHP literal for every configured value.
     *
     * Exported from a COPY. save() used to hide the separators by writing the
     * markers back into the manager's own values through set(), so after saving,
     * get('base_model') returned 'Domain[[BACKSLASH]]Shared[[BACKSLASH]]Models'
     * — the file was right, the object was left holding nonsense.
     */
    protected function exportedValues(string $marker): array
    {
        $exported = [];

        foreach ($this->config as $key => $value) {
            if (in_array($key, static::KEYS_WITH_NAMESPACES, true)) {
                $value = $this->hideSeparators($value, $marker);
            }

            $exported[$key] = VarExporter::export($value);
        }

        return $exported;
    }

    /**
     * The configuration file's contents, without touching any state.
     */
    public function render(): string
    {
        $marker = $this->separatorMarker($this->config);

        $exported = $this->exportedValues($marker);

        $replacements = [];

        foreach ($exported as $key => $literal) {
            $replacements['{{'.$key.'}}'] = $literal;
        }

        // strtr() rather than str_replace() in a loop: it walks the subject once
        // and never looks at what it has already substituted, so a value whose
        // own text contains {{another_key}} is written out as the consumer wrote
        // it instead of being rewritten by a later replacement.
        $content = strtr($this->stub, $replacements);

        $content = $this->appendUnplaceheldKeys($content, $exported);

        return str_replace($marker, '\\', $content);
    }

    /**
     * Write out any key the stub has no placeholder for.
     *
     * The file is produced from a fixed, commented template, so a key the
     * package does not know about — one an extension added, or one left over
     * from an older release — has nowhere to go. Syncing keeps such a key in
     * memory, and saving used to then drop it from the file: publishing a
     * config, adding a key to it and running the upgrade command silently threw
     * that key away. They are appended instead, so the template and its comments
     * stay as they are and nothing the consumer wrote is lost.
     */
    protected function appendUnplaceheldKeys(string $content, array $exported): string
    {
        $appended = '';

        foreach ($exported as $key => $literal) {
            if (str_contains($this->stub, '{{'.$key.'}}')) {
                continue;
            }

            $appended .= '    '.VarExporter::export($key).' => '.$literal.','.PHP_EOL;
        }

        if ($appended === '') {
            return $content;
        }

        // Before the array's closing bracket, which the stub ends with.
        $closing = strrpos($content, '];');

        if ($closing === false) {
            return $content;
        }

        return substr($content, 0, $closing).$appended.substr($content, $closing);
    }

    /**
     * A file this call owns, beside the destination.
     *
     * Beside it, not in the system temp directory, so the replacement below is a
     * rename within one filesystem rather than a copy across two. The extension
     * is deliberately not .php: Laravel loads every .php file in the config
     * directory, and a half-written one must not be among them.
     *
     * Every save used to write to sys_get_temp_dir()/ddd.php — one fixed name
     * shared by every process on the machine, left behind after the copy.
     */
    protected function stagingPath(string $destination): string
    {
        return $destination.'.'.getmypid().'.'.bin2hex(random_bytes(6)).'.tmp';
    }

    /**
     * The file the save actually writes.
     *
     * Normally the configured path. When that path is a symbolic link it is the
     * link's target, resolved once here: copy() wrote THROUGH a link, and
     * rename() would replace the link itself, so a consumer pointing
     * config/ddd.php at a shared or release-managed file would have found their
     * link quietly turned into a regular file and the real target left stale.
     * Staging beside the resolved target also keeps the replacement on the
     * target's own filesystem.
     *
     * $this->configPath is left alone — it is public, and callers are entitled
     * to read back the path they gave.
     *
     * A link with no target is refused. copy() used to create the missing file;
     * rename() cannot do that without destroying the link, so this is a
     * deliberate compatibility limit and it fails loudly rather than quietly
     * replacing the link.
     */
    protected function destinationPath(): string
    {
        if (! is_link($this->configPath)) {
            return $this->configPath;
        }

        // realpath() follows a chain of links and resolves a relative target
        // against the link's own directory; false means it leads nowhere.
        $target = realpath($this->configPath);

        if ($target === false) {
            throw new RuntimeException(
                "The configuration at {$this->configPath} is a symbolic link whose target does not exist."
            );
        }

        return $target;
    }

    /**
     * Write a file, or fail loudly.
     *
     * A short write is a failure. file_put_contents() returns the number of
     * bytes written, and a disk that fills mid-write returns a number rather
     * than false — testing only for false publishes a truncated file.
     */
    protected function writeFile(string $path, string $content): void
    {
        $written = @file_put_contents($path, $content, LOCK_EX);

        if ($written !== strlen($content)) {
            throw new RuntimeException(
                "Could not write the configuration to {$path}: expected ".strlen($content)
                .' bytes, wrote '.var_export($written, true).'.'
            );
        }
    }

    /**
     * The formatter binary, or null when there is nothing to run.
     */
    protected function formatterPath(): ?string
    {
        $candidates = DIRECTORY_SEPARATOR === '\\'
            ? ['vendor\\bin\\pint.bat', 'vendor\\bin\\pint']
            : ['vendor/bin/pint'];

        foreach ([getcwd(), base_path()] as $root) {
            if (! is_string($root) || $root === '') {
                continue;
            }

            foreach ($candidates as $candidate) {
                $path = $root.DIRECTORY_SEPARATOR.$candidate;

                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Run the formatter over the staged file.
     *
     * Passed as separate arguments rather than interpolated into a command
     * string, so a path containing a space cannot break it.
     */
    protected function runFormatter(string $path): bool
    {
        if (($formatter = $this->formatterPath()) === null) {
            return false;
        }

        try {
            return Process::run([$formatter, $path])->successful();
        } catch (ProcessException $e) {
            // The binary exists — formatterPath() checked that much — but could
            // not be run: not executable, not a program, an unusable working
            // directory, or it timed out. Every process failure Symfony and
            // Laravel raise descends from this one class, while their
            // LogicException and InvalidArgumentException, which mean this code
            // called the process API wrongly, do not — those still escape.
            //
            // A formatter that cannot start is a formatter that did not format,
            // which is the case format() already handles. Letting it through
            // would abort a save that has perfectly good bytes to publish.
            return false;
        }
    }

    /**
     * Format the staged file, keeping the rendered bytes if that goes wrong.
     *
     * Formatting is best-effort and deliberately stays that way: the previous
     * call discarded the process result, so a missing or failing binary produced
     * an unformatted — but complete and valid — file, and consumers have been
     * saving against that. laravel/pint is a runtime requirement of this
     * package, so the binary normally is there; it is resolved rather than
     * assumed because the old command was relative to the working directory,
     * which is not necessarily the application root.
     *
     * What best-effort must not mean is publishing whatever the formatter left
     * behind. A formatter that fails part-way through rewriting the file leaves
     * it truncated, so anything other than a clean run and a plausible file puts
     * the rendered bytes back.
     */
    protected function format(string $path, string $rendered): void
    {
        $formatted = $this->runFormatter($path);

        if ($formatted && $this->looksLikeAConfigFile($path)) {
            return;
        }

        $this->writeFile($path, $rendered);
    }

    protected function looksLikeAConfigFile(string $path): bool
    {
        if (! is_file($path) || filesize($path) === 0) {
            return false;
        }

        $contents = @file_get_contents($path);

        return is_string($contents)
            && str_starts_with($contents, '<?php')
            && str_contains($contents, '];');
    }

    /**
     * Give the staged file the permissions the destination already has.
     *
     * Replacing a file by renaming another one over it replaces its permissions
     * too, and the staged file was created under the process umask. A config
     * kept at 0600 would quietly come back 0644 after an upgrade, which is a
     * change nobody asked for. Done BEFORE the replacement, so a failure here
     * leaves the destination as it was.
     *
     * POSIX only. Windows has no mode bits to carry over — chmod() there only
     * toggles the read-only attribute — so the destination keeps whatever the
     * filesystem gives the new file. Stated as a limit rather than papered over.
     */
    protected function matchDestinationPermissions(string $stagingPath, string $destination): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || ! is_file($destination)) {
            return;
        }

        $mode = @fileperms($destination);

        if ($mode === false) {
            throw new RuntimeException("Could not read the permissions of {$destination}.");
        }

        if (! @chmod($stagingPath, $mode & 0777)) {
            throw new RuntimeException(
                'Could not apply the permissions of '.$destination.' to the staged configuration.'
            );
        }
    }

    /**
     * Put the staged file in place of the destination.
     *
     * rename() within one directory, which replaces the destination in a single
     * step — including on Windows, where PHP asks for MOVEFILE_REPLACE_EXISTING.
     * If it cannot be done, it is not done: there is no unlink-then-move
     * fallback, because that trades a failed save for a deleted configuration.
     */
    protected function replace(string $stagingPath, string $destination): void
    {
        $this->matchDestinationPermissions($stagingPath, $destination);

        if (! @rename($stagingPath, $destination)) {
            throw new RuntimeException("Could not replace the configuration at {$destination}.");
        }
    }

    public function save()
    {
        $content = $this->render();

        // Resolved before anything is written, so a link that leads nowhere
        // fails without a staged file ever existing.
        $destination = $this->destinationPath();

        $stagingPath = $this->stagingPath($destination);

        try {
            // Nothing touches the destination until there is a complete,
            // formatted file to put there. copy() could not offer that: it
            // truncates the destination and then writes, so a failure part way
            // through left the consumer with a damaged config file.
            $this->writeFile($stagingPath, $content);

            $this->format($stagingPath, $content);

            $this->replace($stagingPath, $destination);
        } finally {
            if (is_file($stagingPath)) {
                @unlink($stagingPath);
            }
        }

        return $this;
    }
}
