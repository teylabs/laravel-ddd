<?php

namespace Tey\LaravelDDD\Commands\Concerns;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use LogicException;
use ReflectionClass;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\LaravelDDD\Exceptions\EmptyRootNamespace;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\LaravelDDD\Support\GeneratorBlueprint;
use Tey\LaravelDDD\Support\Layout\DddLayout;
use Tey\LaravelDDD\Support\Path;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\CollisionPolicy;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Layout\CompiledLayout;

use function Laravel\Prompts\suggest;

/**
 * Builds a ddd:* generator on its mod:* adapter.
 *
 * The domain comes from --domain, the "Domain:Name" prefix or a prompt,
 * exactly as before; it becomes the placement of the compiled ddd layout,
 * and mod places the class, follows relations and lets the native
 * generator write. The blueprint stays the public view of the invocation.
 */
trait ResolvesDomainFromInput
{
    use HandleHooks,
        HasGeneratorBlueprint,
        QualifiesDomainModels;

    protected $nameIsAbsolute = false;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Keep preparation in handle(): consumer overrides may still need
            // to inspect or rewrite raw input before delegating to the parent.
            return parent::execute($input, $output);
        } catch (EmptyRootNamespace $e) { // @phpstan-ignore catch.neverThrown (thrown by the blueprint in handle())
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $this->blueprint = null;
        }
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

    protected function getNameInput()
    {
        return GeneratorCommand::getNameInput();
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
            ? Path::absolute($this->resolvePlan()->primary->path(), $this->laravel->basePath())
            : parent::getPath($name);
    }

    protected function qualifyClass($name)
    {
        // Reached on class generators only; the migration adapter never qualifies a class.
        return $this->blueprint
            ? (string) $this->resolvePlan()->primary->fqcn()
            /** @phpstan-ignore staticMethod.notFound (the migration adapter's parent has no qualifyClass()) */
            : parent::qualifyClass($name);
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

    protected function beforeHandle()
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

    /**
     * The blueprint of the running invocation; placement needs it.
     */
    protected function domainBlueprint(): GeneratorBlueprint
    {
        return $this->blueprint ?? throw new LogicException(
            static::class.' places classes by domain; call beforeHandle() before generating.'
        );
    }

    /**
     * ddd:* takes placement from --domain, the name prefix or the prompt; no --in
     * and no per-dimension options.
     *
     * @return array<string, ?string>
     */
    protected function placementOptions(): array
    {
        return [];
    }

    /**
     * The domain in the layout's placement grammar ('.' between subdomains).
     */
    protected function placementInput(): ?string
    {
        return $this->domainBlueprint()->domain->dotName;
    }

    /**
     * The compiled ddd layout, read per invocation so configuration changes apply.
     */
    protected function layout(): CompiledLayout
    {
        return $this->resolveLayout();
    }

    protected function resolveLayout(): CompiledLayout
    {
        return DddLayout::current()->compiled();
    }

    /**
     * The kind this invocation generates. Before handle() resolves the domain
     * (getStub() called on its own, as 3.x allowed), the type's home kind.
     */
    protected function fileTypeId(): string
    {
        if ($this->blueprint === null) {
            return DddLayout::current()->homeKindIdFor(GeneratorBlueprint::objectTypeFor((string) $this->getName()));
        }

        return DddLayout::current()->kindIdFor($this->blueprint->type, $this->blueprint->layer);
    }

    /**
     * The layout kind of an object type related to this one, in the layer that object belongs to.
     */
    protected function relatedFileType(string $role): string
    {
        $domain = $this->domainBlueprint()->domainName;

        return DddLayout::current()->kindIdFor($role, DomainResolver::resolveLayer($domain, $role));
    }

    /**
     * Planning waits for handle(): the domain may come from a prompt there.
     */
    protected function plansEagerly(): bool
    {
        return false;
    }

    /**
     * Native already-exists and --force semantics, as before.
     */
    protected function collisionPolicy(): CollisionPolicy
    {
        return CollisionPolicy::Native;
    }

    /**
     * The blueprint's artifact: mod's placement of the domain input, or the
     * schema callback's identity verbatim when it relocates the class.
     */
    protected function plan(): GenerationPlan
    {
        if ($this->blueprint === null) {
            // Mod previews plan directly, before invoking the native handler.
            $this->beforeHandle();
        }

        $primary = $this->domainBlueprint()->artifact();

        /** @phpstan-ignore method.notFound (class adapters only; the migration adapter plans in resolvePlan()) */
        return new GenerationPlan($primary, $this->plannedRelations($primary));
    }

    /**
     * Children stay ddd:* commands: the nested name and the target's domain.
     *
     * @return array<string, mixed>
     */
    protected function argumentsFor(ResolvedArtifact $target): array
    {
        $domain = str_replace('/', '.', $target->context->get(DddLayout::DIMENSION) ?? '');

        return [
            'name' => $target->fileType->isClass() ? $target->nestedName() : $target->name,
            '--domain' => $domain !== '' ? $domain : $this->domainBlueprint()->domain->dotName,
        ];
    }

    protected function reportRefusal(ModException $exception): int
    {
        foreach (explode(PHP_EOL, $exception->getMessage()) as $line) {
            $this->components->error($line);
        }

        return self::FAILURE;
    }

    protected function reportReference(ResolvedArtifact $target): void
    {
        $this->components->info(sprintf(
            '%s [%s] is referenced, not generated.',
            Str::headline(Str::afterLast($target->fileType->id, '.')),
            $target->fqcn() ?? $target->path(),
        ));
    }
}
