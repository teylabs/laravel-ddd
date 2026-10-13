<?php

namespace Tey\LaravelDDD\ValueObjects;

use Tey\LaravelDDD\Support\Layout\DddReverse;

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

    /**
     * The domain object a class is, mapped back through the layout; null for
     * a class no domain rule owns (or not of the given type).
     */
    public static function fromClass(string $fullyQualifiedClass, ?string $objectType = null): ?self
    {
        return DddReverse::current()->objectFromClass($fullyQualifiedClass, $objectType);
    }
}
