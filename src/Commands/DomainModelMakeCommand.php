<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Foundation\Console\ModelMakeCommand;
use Illuminate\Support\Str;
use Tey\LaravelDDD\Commands\Concerns\ForwardsToDomainCommands;
use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\MapsRelatedKinds;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Commands\ModelCommand;
use Tey\Mod\Relation\RelationResolution;

class DomainModelMakeCommand extends ModelCommand
{
    use ForwardsToDomainCommands,
        HasDomainStubs,
        MapsRelatedKinds,
        ResolvesDomainFromInput;

    protected $name = 'ddd:model';

    protected function getNameInput()
    {
        return Str::studly($this->argument('name'));
    }

    /**
     * @return bool|int|null
     */
    public function handle()
    {
        $this->beforeHandle();

        $this->resolvePlan();

        $this->createBaseModelIfNeeded();

        // Prevent the upstream confirm() prompt introduced in newer Laravel versions
        // when a model file already exists. Domain generators don't support the
        // "additional components" flow — use explicit flags (--factory, --migration, etc.).
        if (! $this->option('force') && $this->alreadyExists($this->getNameInput())) {
            $this->components->error($this->type.' already exists.');

            return false;
        }

        $result = parent::handle();

        $this->afterHandle();

        return is_bool($result) ? $result : null;
    }

    /**
     * --requests without a controller has no relation to follow from the
     * model: the requests are generated as natively (forwarded to ddd:request).
     *
     * @return list<RelationResolution>
     */
    protected function plannedRelations(ResolvedArtifact $primary): array
    {
        if (! $this->option('requests') || $this->option('controller') || $this->option('resource') || $this->option('api') || $this->option('all')) {
            return parent::plannedRelations($primary);
        }

        $this->input->setOption('requests', false);

        try {
            return parent::plannedRelations($primary);
        } finally {
            $this->input->setOption('requests', true);
        }
    }

    protected function createFormRequests()
    {
        ModelMakeCommand::createFormRequests();
    }

    /**
     * HasFactory names the factory the model's relation resolved (also for
     * --all), through HasDomainFactory; without a factory, as natively.
     */
    protected function buildFactoryReplacements()
    {
        $factory = collect($this->currentPlan()->relations)
            ->first(fn (RelationResolution $resolution) => $resolution->relation->toFileType === $this->relatedFileType('factory'))?->target?->fqcn();

        if ($factory === null) {
            return ModelMakeCommand::buildFactoryReplacements();
        }

        $factoryNamespace = Str::start($factory, '\\');

        $factoryCode = <<<EOT
        /** @use HasFactory<$factoryNamespace> */
            use HasFactory;
        EOT;

        return [
            '{{ factory }}' => $factoryCode,
            '{{ factoryImport }}' => 'use Tey\LaravelDDD\Factories\HasDomainFactory as HasFactory;',
        ];
    }

    protected function buildClass($name)
    {
        $stub = parent::buildClass($name);

        if ($this->isUsingPublishedStub()) {
            return $stub;
        }

        $replace = [];

        if ($baseModel = $this->getBaseModel()) {
            $baseModelClass = class_basename($baseModel);

            $replace = array_merge($replace, [
                'extends Model' => "extends {$baseModelClass}",
                'use Illuminate\Database\Eloquent\Model;' => "use {$baseModel};",
            ]);
        }

        $stub = str_replace(
            array_keys($replace),
            array_values($replace),
            $stub
        );

        return $this->sortImports($stub);
    }

    protected function createBaseModelIfNeeded()
    {
        if (! $this->shouldCreateBaseModel()) {
            return;
        }

        $baseModel = config('ddd.base_model');

        $this->warn("Base model {$baseModel} doesn't exist, generating...");

        $domain = DomainResolver::guessDomainFromClass($baseModel);

        $name = str($baseModel)
            ->after($domain)
            ->replace(['\\', '/'], '/')
            ->toString();

        $this->call(DomainBaseModelMakeCommand::class, [
            '--domain' => $domain,
            'name' => $name,
        ]);
    }

    protected function getBaseModel(): ?string
    {
        return config('ddd.base_model', null);
    }

    protected function shouldCreateBaseModel(): bool
    {
        $baseModel = config('ddd.base_model');

        if (is_null($baseModel)) {
            return false;
        }

        // If the class exists, we don't need to create it.
        if (class_exists($baseModel)) {
            return false;
        }

        // If the class is outside of the domain layer, we won't attempt to create it.
        if (! DomainResolver::isDomainClass($baseModel)) {
            return false;
        }

        // At this point the class is probably a domain object, but we should
        // check if the expected path exists.
        if (file_exists(app()->basePath(DomainResolver::guessPathFromClass($baseModel)))) {
            return false;
        }

        return true;
    }
}
