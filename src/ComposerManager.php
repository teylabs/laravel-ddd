<?php

namespace Tey\LaravelDDD;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Arr;
use Illuminate\Support\Composer;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Tey\LaravelDDD\Support\Path;

class ComposerManager
{
    public readonly string $composerFile;

    protected Composer $composer;

    protected array $data;

    /**
     * The paths (keys joined by "\0") that hold a JSON object in the file as read.
     *
     * @var array<string, true>
     */
    protected array $objects = [];

    protected ?OutputStyle $output = null;

    public function __construct(?string $composerFile = null)
    {
        $this->composer = app(Composer::class)->setWorkingPath(app()->basePath());

        $this->composerFile = $composerFile ?? app()->basePath('composer.json');

        $json = is_file($this->composerFile) ? @file_get_contents($this->composerFile) : false;

        if ($json === false) {
            throw new RuntimeException("{$this->composerFile} could not be read.");
        }

        $data = json_decode($json);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("{$this->composerFile} is not valid JSON: ".json_last_error_msg());
        }

        if (! $data instanceof stdClass) {
            throw new RuntimeException("{$this->composerFile} is not a JSON object.");
        }

        $this->recordObjects($data, []);

        $this->data = json_decode($json, true);
    }

    /**
     * Remember where the file holds JSON objects, so an empty one saves back as {} and not [].
     */
    protected function recordObjects(mixed $value, array $path): void
    {
        if ($value instanceof stdClass) {
            $this->objects[implode("\0", $path)] = true;
        }

        if ($value instanceof stdClass || is_array($value)) {
            foreach ($value as $key => $child) {
                $this->recordObjects($child, [...$path, $key]);
            }
        }
    }

    /**
     * The data for json_encode: an empty array where the file had an object becomes {}.
     */
    protected function encodable(mixed $value, array $path = []): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value === [] && isset($this->objects[implode("\0", $path)])) {
            return new stdClass;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->encodable($child, [...$path, $key]);
        }

        return $value;
    }

    public static function make(?string $composerFile = null): self
    {
        return new self($composerFile);
    }

    public function usingOutput(OutputStyle $output)
    {
        $this->output = $output;

        return $this;
    }

    protected function guessAutoloadPathFromNamespace(string $namespace): string
    {
        $relativePath = Str::rtrim(Path::fromNamespace($namespace), '/\\');

        // Candidates are relative to the application's base path: src/{path}, then {path}.
        foreach ([Path::join('src', $relativePath), $relativePath] as $path) {
            if (is_dir(app()->basePath($path))) {
                return $this->normalizePathForComposer($path);
            }
        }

        return $this->normalizePathForComposer("src/{$relativePath}");
    }

    protected function normalizePathForComposer($path): string
    {
        $path = Path::normalize($path);

        return str_replace(['\\', '/'], '/', $path);
    }

    public function hasPsr4Autoload(string $namespace): bool
    {
        return collect($this->getPsr4Namespaces())
            ->hasAny([
                $namespace,
                Str::finish($namespace, '\\'),
            ]);
    }

    public function registerPsr4Autoload(string $namespace, $path)
    {
        $namespace = str($namespace)
            ->rtrim('/\\')
            ->finish('\\')
            ->toString();

        $path = $path ?? $this->guessAutoloadPathFromNamespace($namespace);

        return $this->fill(
            ['autoload', 'psr-4', $namespace],
            $this->normalizePathForComposer($path)
        );
    }

    public function fill($path, $value)
    {
        data_fill($this->data, $path, $value);

        return $this;
    }

    protected function update($set = [], $forget = [])
    {
        foreach ($forget as $key) {
            $this->forget($key);
        }

        foreach ($set as $pair) {
            [$path, $value] = $pair;
            $this->fill($path, $value);
        }

        return $this;
    }

    public function forget($key)
    {
        $keys = Arr::wrap($key);

        foreach ($keys as $key) {
            Arr::forget($this->data, $key);
        }

        return $this;
    }

    public function get($path, $default = null)
    {
        return data_get($this->data, $path, $default);
    }

    public function getPsr4Namespaces()
    {
        return $this->get(['autoload', 'psr-4'], []);
    }

    public function getAutoloadPath($namespace)
    {
        $namespace = Str::finish($namespace, '\\');

        return $this->get(['autoload', 'psr-4', $namespace]);
    }

    public function unsetPsr4Autoload($namespace)
    {
        $namespace = Str::finish($namespace, '\\');

        if (is_array($this->data['autoload']['psr-4'] ?? null)) {
            unset($this->data['autoload']['psr-4'][$namespace]);
        }

        return $this;
    }

    public function reload()
    {
        $this->output?->writeLn('Reloading composer (dump-autoload)...');

        $this->composer->dumpAutoloads();

        return $this;
    }

    public function save()
    {
        // The file that was read, encoded as Composer::modify() does (no trailing newline).
        file_put_contents($this->composerFile, $this->toJson(JSON_UNESCAPED_UNICODE));

        return $this;
    }

    public function saveAndReload()
    {
        return $this->save()->reload();
    }

    public function toJson(int $flags = 0)
    {
        return json_encode($this->encodable($this->data), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | $flags);
    }

    public function toArray()
    {
        return $this->data;
    }
}
