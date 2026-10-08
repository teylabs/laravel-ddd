<?php

namespace Tey\LaravelDDD\Support;

use Illuminate\Support\Str;
use Tey\LaravelDDD\Enums\LayerType;

class DomainResolver
{
    /**
     * Get the list of current domain choices.
     */
    public static function domainChoices(): array
    {
        $folders = glob(app()->basePath(static::domainPath().'/*'), GLOB_ONLYDIR);

        return collect($folders)
            ->map(fn ($path) => basename($path))
            ->sort()
            ->toArray();
    }

    /**
     * Get the current configured domain path.
     */
    public static function domainPath(): ?string
    {
        return config('ddd.domain_path');
    }

    /**
     * Get the current configured root domain namespace.
     */
    public static function domainRootNamespace(): ?string
    {
        return config('ddd.domain_namespace');
    }

    /**
     * Get the current configured application layer path.
     */
    public static function applicationLayerPath(): ?string
    {
        return config('ddd.application_path');
    }

    /**
     * Get the current configured root application layer namespace.
     */
    public static function applicationLayerRootNamespace(): ?string
    {
        return config('ddd.application_namespace');
    }

    /**
     * Resolve the relative domain object namespace.
     *
     * @param  string  $type  The domain object type.
     */
    public static function getRelativeObjectNamespace(string $type): string
    {
        return config("ddd.namespaces.{$type}", str($type)->plural()->studly()->toString());
    }

    /**
     * Determine whether a given object type is part of the application layer.
     */
    public static function isApplicationLayer(string $type): bool
    {
        $filter = app('ddd')->getApplicationLayerFilter() ?? function (string $type) {
            $applicationObjects = config('ddd.application_objects', ['controller', 'request']);

            return in_array($type, $applicationObjects);
        };

        return $filter($type);
    }

    /**
     * Resolve the root namespace for a given domain object type.
     *
     * @param  string  $type  The domain object type.
     */
    public static function resolveRootNamespace(string $type): ?string
    {
        return static::isApplicationLayer($type)
            ? static::applicationLayerRootNamespace()
            : static::domainRootNamespace();
    }

    /**
     * Resolve the intended layer of a specified domain name keyword.
     */
    public static function resolveLayer(string $domain, ?string $type = null): ?Layer
    {
        $layers = config('ddd.layers', []);

        // Objects in the application layer take precedence
        if ($type && static::isApplicationLayer($type)) {
            return new Layer(
                static::applicationLayerRootNamespace().'\\'.$domain,
                Path::join(static::applicationLayerPath(), $domain),
                LayerType::Application,
            );
        }

        return match (true) {
            array_key_exists($domain, $layers)
                && is_string($layers[$domain]) => new Layer($domain, $layers[$domain], LayerType::Custom),

            default => new Layer(
                static::domainRootNamespace().'\\'.$domain,
                Path::join(static::domainPath(), $domain),
                LayerType::Domain,
            )
        };
    }

    /**
     * Get the fully qualified namespace for a domain object.
     *
     * @param  string  $domain  The domain name.
     * @param  string  $type  The domain object type.
     * @param  string|null  $name  The domain object name.
     */
    public static function getDomainObjectNamespace(string $domain, string $type, ?string $name = null): string
    {
        $resolver = function (string $domain, string $type, ?string $name) {
            $layer = static::resolveLayer($domain, $type);

            $namespace = collect([
                $layer->namespace,
                static::getRelativeObjectNamespace($type),
            ])->filter()->implode('\\');

            if ($name) {
                $namespace .= '\\'.Path::normalizeNamespace(trim($name, '\\/'));
            }

            return $namespace;
        };

        return $resolver($domain, $type, $name);
    }

    /**
     * Attempt to resolve the domain of a given domain class.
     */
    public static function guessDomainFromClass(string $class): ?string
    {
        if (! static::isDomainClass($class)) {
            // Not a domain object
            return null;
        }

        $domain = str($class)
            ->after(Str::finish(static::domainRootNamespace(), '\\'))
            ->before('\\')
            ->toString();

        return $domain;
    }

    /**
     * Attempt to resolve the file path of a given domain class.
     */
    public static function guessPathFromClass(string $class): ?string
    {
        if (! static::isDomainClass($class)) {
            // Not a domain object
            return null;
        }

        $classWithoutDomainRoot = str($class)
            ->after(Str::finish(static::domainRootNamespace(), '\\'))
            ->toString();

        return Path::join(...[static::domainPath(), "{$classWithoutDomainRoot}.php"]);
    }

    /**
     * Attempt to resolve the folder of a given domain class.
     */
    public static function guessFolderFromClass(string $class): ?string
    {
        $path = static::guessPathFromClass($class);

        if (! $path) {
            return null;
        }

        $filenamePortion = basename($path);

        return Str::beforeLast($path, $filenamePortion);
    }

    /**
     * Determine whether a class is an object within the domain layer.
     *
     * @param  string  $class  The fully qualified class name.
     */
    public static function isDomainClass(string $class): bool
    {
        return str($class)->startsWith(Str::finish(static::domainRootNamespace(), '\\'));
    }
}
