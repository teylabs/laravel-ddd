<?php

namespace Tey\LaravelDDD;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Arr;
use Illuminate\Support\Composer;
use Illuminate\Support\Str;
use RuntimeException;
use Tey\LaravelDDD\Support\Path;

class ComposerManager
{
    public readonly string $composerFile;

    protected Composer $composer;

    protected array $data;

    /**
     * The file contents decoded with JSON objects kept as objects, used to
     * write empty objects back as {} rather than [].
     */
    protected mixed $shape;

    protected ?OutputStyle $output = null;

    public function __construct(?string $composerFile = null)
    {
        $this->composer = app(Composer::class)->setWorkingPath(app()->basePath());

        $this->composerFile = $composerFile ?? app()->basePath('composer.json');

        $contents = is_file($this->composerFile) ? @file_get_contents($this->composerFile) : false;

        if ($contents === false) {
            throw new RuntimeException("{$this->composerFile} could not be read.");
        }

        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("{$this->composerFile} is not valid JSON: ".json_last_error_msg());
        }

        if (! is_array($data)) {
            throw new RuntimeException("{$this->composerFile} is not a JSON object.");
        }

        $this->data = $data;

        $this->shape = json_decode($contents);
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
        $rootFolders = [
            'src',
            '',
        ];

        $relativePath = Str::rtrim(Path::fromNamespace($namespace), '/\\');

        foreach ($rootFolders as $folder) {
            $path = $folder === '' ? $relativePath : Path::join($folder, $relativePath);

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

        return $this->forget("autoload.psr-4.{$namespace}");
    }

    public function reload()
    {
        $this->output?->writeLn('Reloading composer (dump-autoload)...');

        $this->composer->dumpAutoloads();

        return $this;
    }

    public function save()
    {
        file_put_contents(
            $this->composerFile,
            json_encode(
                $this->preserveEmptyObjects($this->data, $this->shape),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );

        return $this;
    }

    public function saveAndReload()
    {
        return $this->save()->reload();
    }

    public function toJson()
    {
        return json_encode($this->preserveEmptyObjects($this->data, $this->shape), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Restore an empty array to {} wherever the file held a JSON object.
     */
    protected function preserveEmptyObjects(mixed $value, mixed $shape): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($shape instanceof \stdClass) {
            if ($value === []) {
                return new \stdClass;
            }

            $shape = get_object_vars($shape);
        }

        if (! is_array($shape)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (array_key_exists($key, $shape)) {
                $value[$key] = $this->preserveEmptyObjects($item, $shape[$key]);
            }
        }

        return $value;
    }

    public function toArray()
    {
        return $this->data;
    }
}
