<?php

namespace Tey\LaravelDDD\Support;

use Illuminate\Support\Str;
use Tey\LaravelDDD\Enums\LayerType;

class Layer
{
    public readonly ?string $namespace;

    public readonly ?string $path;

    public function __construct(
        string $namespace,
        ?string $path = null,
        public ?LayerType $type = null,
    ) {
        $this->namespace = Path::normalizeNamespace(Str::replaceEnd('\\', '', $namespace));

        $this->path = is_null($path)
            ? Path::fromNamespace($this->namespace)
            : Path::normalize(Str::replaceEnd('/', '', $path));
    }

    public static function fromNamespace(string $namespace): self
    {
        return new self($namespace);
    }

    public function path(?string $path = null): string
    {
        if (is_null($path)) {
            return $this->path;
        }

        $baseName = class_basename($path);

        $relativePath = str($path)
            ->beforeLast($baseName)
            ->replaceStart($this->namespace, '')
            ->replace(['\\', '/'], DIRECTORY_SEPARATOR)
            ->append($baseName)
            ->finish('.php')
            ->toString();

        return Path::join($this->path, $relativePath);
    }

    public function namespaceFor(string $type, ?string $name = null): string
    {
        $namespace = collect([
            $this->namespace,
            DomainResolver::getRelativeObjectNamespace($type),
        ])->filter()->implode('\\');

        if ($name) {
            $namespace .= "\\{$name}";
        }

        return Path::normalizeNamespace($namespace);
    }

    /**
     * Select the namespace without changing the caller's name normalization.
     */
    public function namespaceForObject(string $type, string $name, bool $absolute = false): string
    {
        return match (true) {
            $absolute => $this->namespace,
            str($name)->startsWith('\\') => $this->guessNamespaceFromName($name),
            default => $this->namespaceFor($type),
        };
    }

    public function guessNamespaceFromName(string $name): string
    {
        $baseName = class_basename($name);

        return Path::normalizeNamespace(
            str($name)
                ->before($baseName)
                ->trim('\\')
                ->prepend($this->namespace.'\\')
                ->toString()
        );
    }
}
