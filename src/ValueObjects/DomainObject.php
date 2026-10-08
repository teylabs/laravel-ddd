<?php

namespace Tey\LaravelDDD\ValueObjects;

use Illuminate\Support\Str;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\LaravelDDD\Support\Path;

class DomainObject
{
    public function __construct(
        public readonly string $name,
        public readonly string $domain,
        public readonly string $namespace,
        public readonly string $fullyQualifiedName,
        public readonly string $path,
        public readonly ?string $type = null,
    ) {}

    public static function fromClass(string $fullyQualifiedClass, ?string $objectType = null): ?self
    {
        if (! DomainResolver::isDomainClass($fullyQualifiedClass)) {
            return null;
        }

        // First extract the object base name
        $objectName = class_basename($fullyQualifiedClass);

        $objectNamespace = '';

        $possibleObjectNamespaces = config("ddd.namespaces.{$objectType}")
            ? [$objectType => config("ddd.namespaces.{$objectType}")]
            : config('ddd.namespaces', []);

        foreach ($possibleObjectNamespaces as $type => $namespace) {
            if (blank($namespace)) {
                continue;
            }

            $rootObjectNamespace = preg_quote($namespace);

            $pattern = "/({$rootObjectNamespace})(.*)$/";

            $result = preg_match($pattern, $fullyQualifiedClass, $matches);

            if (! $result) {
                continue;
            }

            $objectNamespace = str(data_get($matches, 1))->toString();

            $objectName = str(data_get($matches, 2))
                ->trim('\\')
                ->toString();

            $objectType = $type;

            break;
        }

        // If there wasn't a resolvable namespace, we'll treat it
        // as a root-level domain object.
        if (! $objectNamespace) {
            // Examples:
            // - Domain\Invoicing\[Nested\Thing]
            // - Domain\Invoicing\[Deeply\Nested\Thing]
            // - Domain\Invoicing\[Thing]
            $objectName = str($fullyQualifiedClass)
                ->after(Str::finish(DomainResolver::domainRootNamespace(), '\\'))
                ->after('\\')
                ->toString();
        }

        // Extract the domain portion
        $domainName = str($fullyQualifiedClass)
            ->after(Str::finish(DomainResolver::domainRootNamespace(), '\\'))
            ->before("\\{$objectNamespace}")
            ->toString();

        // Edge case to handle root-level domain objects
        if (
            $objectName === $objectNamespace
            && ! str($fullyQualifiedClass)->endsWith("{$objectNamespace}\\{$objectName}")
        ) {
            $objectNamespace = '';
        }

        // Reconstruct the path; root-level objects have no object namespace to join.
        $path = Path::join(...array_filter([
            DomainResolver::domainPath(),
            $domainName,
            $objectNamespace,
            "{$objectName}.php",
        ], 'strlen'));

        // dump([
        //     'fullyQualifiedClass' => $fullyQualifiedClass,
        //     'fullNamespace' => $fullNamespace,
        //     'domainName' => $domainName,
        //     'objectNamespace' => $objectNamespace,
        //     'objectName' => $objectName,
        //     'objectType' => $objectType,
        // ]);

        return new self(
            name: $objectName,
            domain: $domainName,
            namespace: $objectNamespace,
            fullyQualifiedName: $fullyQualifiedClass,
            path: $path,
            type: $objectType,
        );
    }
}
