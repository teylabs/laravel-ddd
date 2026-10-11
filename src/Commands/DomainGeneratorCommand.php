<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Tey\LaravelDDD\Commands\Concerns\InteractsWithStubs;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\Mod\Commands\GenericClassCommand;

abstract class DomainGeneratorCommand extends GenericClassCommand
{
    use InteractsWithStubs,
        ResolvesDomainFromInput;

    /**
     * Subclasses name, describe and type themselves, as before.
     *
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $description = '';

    /**
     * @var string
     */
    protected $type;

    protected function getRelativeDomainNamespace(): string
    {
        return DomainResolver::getRelativeObjectNamespace($this->blueprint->type);
    }

    protected function getNameInput()
    {
        return Str::studly($this->argument('name'));
    }

    /**
     * Generate as GeneratorCommand does: mod's GenericClassCommand::handle()
     * would replace each command's $type ("Data Transfer Object", "View Model")
     * with the kind's label in the output.
     *
     * @return bool|null
     */
    protected function handleNatively()
    {
        return GeneratorCommand::handle();
    }

    /**
     * The placeholders mod's replaceClass() fills from the base mod chose.
     * Laravel-DDD fills them itself, after the native build, as in 3.x.
     */
    private const MOD_PLACEHOLDERS = ['{{ base }}', '{{base}}', '{{ baseClass }}', '{{baseClass}}', '{{ baseImport }}', '{{baseImport}}', '{{ extends }}', '{{extends}}'];

    /**
     * Keep mod's replaceClass() off Laravel-DDD's placeholders: it would
     * blank {{ extends }} and the {{ base* }} ones, since mod chooses no
     * base for these kinds. buildClass() then fills them once, as in 3.x.
     *
     * @param  string  $stub
     * @param  string  $name
     * @return string
     */
    protected function replaceClass($stub, $name)
    {
        $shields = [];

        foreach (self::MOD_PLACEHOLDERS as $index => $placeholder) {
            $shields[$placeholder] = "\0ddd-placeholder-{$index}\0";
        }

        return strtr(parent::replaceClass(strtr($stub, $shields), $name), array_flip($shields));
    }

    /**
     * Domain generators declare no options of their own (no --force).
     *
     * @return array<int, mixed>
     */
    protected function getOptions()
    {
        return [];
    }
}
