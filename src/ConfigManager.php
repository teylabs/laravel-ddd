<?php

namespace Tey\LaravelDDD;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
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

        $this->config = file_exists($configPath) ? require ($configPath) : $this->packageConfig;

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
            return $this->resolve($path, $array);
        }

        $existing = $this->resolve($path, []);

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

    public function save()
    {
        $content = $this->stub;

        // We will temporary substitute namespace slashes
        // with a placeholder to avoid double exporter
        // escaping them as double backslashes.
        $keysWithNamespaces = [
            'domain_namespace',
            'application_namespace',
            'layers',
            'namespaces',
            'base_model',
            'base_dto',
            'base_view_model',
            'base_action',
        ];

        foreach ($keysWithNamespaces as $key) {
            $value = $this->get($key);

            if (is_string($value)) {
                $value = str_replace('\\', '[[BACKSLASH]]', $value);
            }

            if (is_array($value)) {
                $array = $value;
                foreach ($array as $k => $v) {
                    $array[$k] = str_replace('\\', '[[BACKSLASH]]', $v);
                }
                $value = $array;
            }

            $this->set($key, $value);
        }

        foreach ($this->config as $key => $value) {
            $content = str_replace(
                '{{'.$key.'}}',
                VarExporter::export($value),
                $content
            );
        }

        // Restore namespace slashes
        $content = str_replace('[[BACKSLASH]]', '\\', $content);

        // Write it to a temporary file first
        $tempPath = sys_get_temp_dir().'/ddd.php';
        file_put_contents($tempPath, $content);

        // Format it using pint
        Process::run("./vendor/bin/pint {$tempPath}");

        // Copy the temporary file to the config path
        copy($tempPath, $this->configPath);

        return $this;
    }
}
