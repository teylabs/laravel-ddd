<?php

namespace Tey\LaravelDDD\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\LaravelDDD\Support\Layout\DddReverse;

abstract class DomainFactory extends Factory
{
    /**
     * Get the domain namespace.
     *
     * @return string
     */
    protected static function domainNamespace()
    {
        return Str::finish(DomainResolver::domainRootNamespace(), '\\');
    }

    /**
     * Get the factory name for the given domain model name.
     *
     * @param  class-string<Model>  $modelName
     * @return null|class-string<Factory>
     */
    public static function resolveFactoryName(string $modelName)
    {
        // The factory the layout places beside the model when it exists, else the standard
        // location under /database/factories; null when the class is not a domain model.
        return DddReverse::current()->factoryFor($modelName, static::$namespace);
    }
}
