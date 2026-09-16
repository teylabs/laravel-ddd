<?php

namespace Tey\LaravelDDD\Commands\Concerns;

use Illuminate\Console\ManuallyFailedException;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\LaravelDDD\Support\GeneratorBlueprint;

use function Laravel\Prompts\suggest;

trait ResolvesDomainFromInput
{
    use HandleHooks,
        HasGeneratorBlueprint,
        QualifiesDomainModels;

    protected $nameIsAbsolute = false;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Symfony has bound and validated the input, and Laravel has already
            // prompted for missing arguments. Isolatable commands keep handler-
            // time preparation so a rejected lock cannot prompt or create files.
            if (! $this instanceof Isolatable) {
                $this->prepareHandle();
                $this->handlePrepared = true;
            }

            return parent::execute($input, $output);
        } catch (ManuallyFailedException $exception) {
            // Preparation used to run inside Laravel's handler dispatch, where
            // manual failures are rendered and converted to a failure status.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->blueprint = null;
            $this->handlePrepared = false;
        }
    }

    protected function beforeHandle()
    {
        $this->prepareDomainInput();
    }

    protected function configure(): void
    {
        parent::configure();

        // Since Laravel 13.24, the framework's generator commands declare their
        // definition via $signature (laravel/framework#60926), which takes
        // precedence over $name and skips getOptions() entirely. Configuring
        // against the built definition survives both declaration styles, but
        // by this point the fluent path has already overwritten $this->name
        // with the parsed signature name, so recover it from the class default.
        if ($name = (new ReflectionClass(static::class))->getDefaultProperties()['name'] ?? null) {
            $this->setName($this->name = $name);
        }

        if (! $this->getDefinition()->hasOption('domain')) {
            $this->getDefinition()->addOption(
                new InputOption('domain', null, InputOption::VALUE_OPTIONAL, 'The domain name')
            );
        }
    }

    protected function rootNamespace()
    {
        return $this->blueprint->rootNamespace();
    }

    protected function getDefaultNamespace($rootNamespace)
    {
        return $this->blueprint
            ? $this->blueprint->getDefaultNamespace($rootNamespace)
            : parent::getDefaultNamespace($rootNamespace);
    }

    protected function getPath($name)
    {
        return $this->blueprint
            ? $this->blueprint->getPath($name)
            : parent::getPath($name);
    }

    protected function qualifyClass($name)
    {
        return $this->blueprint->qualifyClass($name);
    }

    protected function promptForDomainName(): string
    {
        $choices = collect(DomainResolver::domainChoices())
            ->mapWithKeys(fn ($name) => [Str::lower($name) => $name]);

        // Prompt for the domain
        $domainName = suggest(
            label: 'What is the domain?',
            options: fn ($value) => collect($choices)
                ->filter(fn ($name) => Str::contains($name, $value, ignoreCase: true))
                ->toArray(),
            placeholder: 'Start typing to search...',
            required: true
        );

        // Normalize the case of the domain name
        // if it is an existing domain.
        if ($match = $choices->get(Str::lower($domainName))) {
            $domainName = $match;
        }

        return $domainName;
    }

    protected function prepareDomainInput(): void
    {
        $nameInput = $this->getNameInput();

        // If the name contains a domain prefix, extract it
        // and strip it from the name argument.
        $domainExtractedFromName = null;

        if (Str::contains($nameInput, ':')) {
            $domainExtractedFromName = Str::before($nameInput, ':');
            $nameInput = Str::after($nameInput, ':');
        }

        $domainName = match (true) {
            // Domain was specified explicitly via option (priority)
            filled($this->option('domain')) => $this->option('domain'),

            // Domain was specified as a prefix in the name
            filled($domainExtractedFromName) => $domainExtractedFromName,

            default => $this->promptForDomainName(),
        };

        $this->blueprint = new GeneratorBlueprint(
            commandName: $this->getName(),
            nameInput: $nameInput,
            domainName: $domainName,
            arguments: $this->arguments(),
            options: $this->options(),
        );

        $this->input->setArgument('name', $this->blueprint->nameInput);

        $this->input->setOption('domain', $this->blueprint->domainName);
    }
}
