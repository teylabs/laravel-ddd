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
     * Most maps in this config have a key set the package defines — autoload and
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
     * Add anything new from the package without disturbing what is already there.
     *
     * The merge walks the CONSUMER's config and fills in default keys it does not
     * have. Walking the package's config instead — as this used to — silently
     * dropped every consumer key the package did not also define, so a custom
     * layer or an unrecognised top-level key disappeared on sync.
     */
    protected function mergeWithDefaults(array $config, array $defaults, array $path = []): array
    {
        $merged = $config;

        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $config)) {
                $merged[$key] = $default;

                continue;
            }

            $value = $config[$key];

            if ($this->isConsumerOwned([...$path, $key], $value, $default)) {
                continue;
            }

            $merged[$key] = $this->mergeWithDefaults($value, $default, [...$path, $key]);
        }

        return $merged;
    }

    /**
     * Whether a value the consumer supplied should be kept exactly as it is.
     *
     * A list is theirs entirely, empty included: the entries carry no identity of
     * their own, so filling gaps from the package could only mean merging by
     * numeric position. That is how removing an entry from application_objects
     * used to reinstate whichever default happened to sit at that index, and how
     * emptying autoload_ignore used to hand the defaults straight back.
     *
     * Anything that is not an array on both sides is kept as given too, so an
     * explicit null or false survives.
     */
    protected function isConsumerOwned(array $path, mixed $value, mixed $default): bool
    {
        if (! is_array($value) || ! is_array($default)) {
            return true;
        }

        if (array_is_list($value) || array_is_list($default)) {
            return true;
        }

        return in_array(implode('.', $path), static::CONSUMER_OWNED_KEYS, true);
    }

    public function syncWithLatest()
    {
        $this->config = $this->mergeWithDefaults($this->config, $this->packageConfig);

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
