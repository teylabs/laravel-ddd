<?php

namespace Tey\LaravelDDD\Exceptions;

use InvalidArgumentException;

class EmptyRootNamespace extends InvalidArgumentException
{
    public static function for(string $configKey): self
    {
        $example = $configKey === 'ddd.domain_namespace' ? 'Domain' : 'App\\Modules';

        return new self("The {$configKey} configuration is empty; set it to a namespace such as {$example}.");
    }
}
