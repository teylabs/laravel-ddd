<?php

namespace Tey\LaravelDDD\Support\Layout;

use Tey\Mod\Exceptions\ModException;

/**
 * The configuration compiled, but what was asked of it does not exist in the layout.
 */
final class InvalidLayoutUse extends ModException
{
    public static function emptyNamespace(string $configKey): self
    {
        return new self("The {$configKey} configuration is empty; set it to a namespace such as ".($configKey === 'ddd.domain_namespace' ? 'Domain' : 'App\\Modules').'.');
    }

    public static function noKind(string $type, string $root): self
    {
        return new self("The layout declares no [{$type}] kind for root [{$root}].");
    }

    /**
     * @param  list<string>  $issues
     */
    public static function layerNotInLayout(string $namespace, array $issues): self
    {
        return new self(trim("Layer [{$namespace}] is not part of the layout. ".implode(' ', $issues)));
    }
}
