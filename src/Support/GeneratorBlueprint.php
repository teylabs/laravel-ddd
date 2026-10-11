<?php

namespace Tey\LaravelDDD\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Tey\LaravelDDD\Enums\LayerType;
use Tey\LaravelDDD\Exceptions\EmptyRootNamespace;
use Tey\LaravelDDD\Support\Layout\DddLayout;
use Tey\LaravelDDD\Support\Layout\DddPlacement;
use Tey\LaravelDDD\ValueObjects\CommandContext;
use Tey\LaravelDDD\ValueObjects\ObjectSchema;
use Tey\Mod\Artifact\ResolvedArtifact;

class GeneratorBlueprint
{
    public string $nameInput;

    public string $normalizedName;

    public string $baseName;

    public string $domainName;

    public ?Domain $domain = null;

    public CommandContext $command;

    public ObjectSchema $schema;

    public Layer $layer;

    public bool $isAbsoluteName;

    public string $type;

    /** The conventional placement (mod), or the schema callback's identity when one answered. */
    protected ?ResolvedArtifact $artifact = null;

    public function __construct(
        string $commandName,
        string $nameInput,
        string $domainName,
        array $arguments = [],
        array $options = [],
    ) {
        $this->command = new CommandContext($commandName, $arguments, $options);

        $this->nameInput = str($nameInput)->toString();

        $this->isAbsoluteName = str($this->nameInput)->startsWith('/');

        $this->type = $this->guessObjectType();

        $this->normalizedName = Path::normalizeNamespace(
            str($nameInput)
                ->studly()
                ->replace(['.', '\\', '/'], '\\')
                ->trim('\\')
                ->when($this->type === 'factory', fn ($name) => $name->finish('Factory'))
                ->toString()
        );

        $this->baseName = class_basename($this->normalizedName);

        $this->domain = new Domain($domainName);

        $this->domainName = $this->domain->domainWithSubdomain;

        $this->layer = DomainResolver::resolveLayer($this->domainName, $this->type);

        $this->schema = $this->resolveSchema();
    }

    public static function capture(Command $command) {}

    /**
     * An empty domain or application namespace would generate unparsable classes.
     * Migrations are anonymous classes and need no namespace.
     */
    protected function ensureRootNamespaceIsConfigured(): void
    {
        if ($this->type === 'migration') {
            return;
        }

        $configKey = match ($this->layer->type) {
            LayerType::Domain => 'ddd.domain_namespace',
            LayerType::Application => 'ddd.application_namespace',
            default => null,
        };

        if ($configKey && blank(config($configKey))) {
            throw EmptyRootNamespace::for($configKey);
        }
    }

    protected function guessObjectType(): string
    {
        return static::objectTypeFor($this->command->name);
    }

    /**
     * The object type a ddd:* command generates ('ddd:dto' → data_transfer_object).
     */
    public static function objectTypeFor(string $commandName): string
    {
        return match ($commandName) {
            'ddd:base-view-model' => 'view_model',
            'ddd:base-model' => 'model',
            'ddd:value' => 'value_object',
            'ddd:dto' => 'data_transfer_object',
            'ddd:migration' => 'migration',
            default => str($commandName)->after(':')->snake()->toString(),
        };
    }

    protected function resolveSchema(): ObjectSchema
    {
        $customResolver = app('ddd')->getObjectSchemaResolver();

        $blueprint = is_callable($customResolver)
            ? App::call($customResolver, [
                'domainName' => $this->domainName,
                'nameInput' => $this->nameInput,
                'type' => $this->type,
                'command' => $this->command,
            ])
            : null;

        if ($blueprint instanceof ObjectSchema) {
            $this->artifact = DddLayout::current()->artifactFromSchema($blueprint, $this->type, $this->layer);

            return $blueprint;
        }

        // A "\Name" input is namespace-relative to the layer root. Migrations are files: the
        // layout gives the directory; the native creator names the file.
        $relative = ! $this->isAbsoluteName && DddPlacement::isNamespaceRelative($this->nameInput);
        $name = ($relative ? '\\' : '').($this->type === 'migration' ? str($this->nameInput)->snake()->toString() : $this->normalizedName);

        $this->artifact = DddPlacement::current()->resolve($this->type, $this->domain, $name, $this->isAbsoluteName, $this->layer);

        if ($this->artifact->namespace() === null) {
            $namespace = $this->layer->namespaceFor($this->type);

            return new ObjectSchema(
                name: $this->normalizedName,
                namespace: $namespace,
                fullyQualifiedName: $namespace.'\\'.$this->normalizedName,
                path: Path::join(dirname($this->artifact->path()), $this->normalizedName.'.php'),
            );
        }

        return new ObjectSchema(
            name: $this->normalizedName,
            namespace: DddPlacement::namespaceOf($this->artifact, $relative),
            fullyQualifiedName: (string) $this->artifact->fqcn(),
            path: Path::normalize($this->artifact->path()),
        );
    }

    /**
     * The resolved artifact this blueprint generates: mod's conventional
     * placement, or the schema callback's identity verbatim.
     */
    public function artifact(): ResolvedArtifact
    {
        return $this->artifact ??= DddLayout::current()->artifactFromSchema($this->schema, $this->type, $this->layer);
    }

    public function rootNamespace()
    {
        return str($this->schema->namespace)->finish('\\')->toString();
    }

    public function getDefaultNamespace($rootNamespace)
    {
        return $this->schema->namespace;
    }

    public function getPath($name)
    {
        return Path::normalize(app()->basePath($this->schema->path));
    }

    public function qualifyClass($name)
    {
        return $this->schema->fullyQualifiedName;
    }

    /**
     * Related references retain domain naming conventions. Schema callbacks
     * are evaluated by the child generator when it creates its own blueprint.
     */
    public function getModelFor(string $name)
    {
        return $this->domain->model($name);
    }

    public function getFactoryFor(string $name)
    {
        return $this->domain->factory($name);
    }

    public function getRequestFor(string $name)
    {
        // An absolute name is one the child generator would treat as absolute,
        // and it decides that from the name it is given — so the same rule is
        // applied to the name being forwarded here. Dropping the flag put the
        // request's type namespace back in (Requests\Custom\StoreInvoiceRequest)
        // while the generated file sat at the layer root under Custom, so the
        // controller imported a class nothing had written.
        return $this->domain->object('request', $name, str($name)->startsWith('/'));
    }

    public function getMigrationPath()
    {
        return $this->domain->migrationPath;
    }

    public function getNamespaceFor($type, $name = null)
    {
        return $this->domain->namespaceFor($type, $name);
    }
}
