<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Foundation\Console\ModelMakeCommand;
use Illuminate\Support\Str;
use Tey\LaravelDDD\Commands\Concerns\ForwardsToDomainCommands;
use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\LaravelDDD\Support\DomainResolver;

class DomainModelMakeCommand extends ModelMakeCommand
{
    use ForwardsToDomainCommands,
        HasDomainStubs,
        ResolvesDomainFromInput;

    protected $name = 'ddd:model';

    protected function getNameInput()
    {
        return Str::studly($this->argument('name'));
    }

    /**
     * @return bool|null
     */
    public function handle()
    {
        $this->beforeHandle();

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

    protected function buildFactoryReplacements()
    {
        $replacements = parent::buildFactoryReplacements();

        if ($this->option('factory')) {
            $factoryNamespace = Str::start($this->blueprint->getFactoryFor($this->getNameInput())->fullyQualifiedName, '\\');

            $factoryCode = <<<EOT
            /** @use HasFactory<$factoryNamespace> */
                use HasFactory;
            EOT;

            $replacements['{{ factory }}'] = $factoryCode;
            $replacements['{{ factoryImport }}'] = 'use Tey\LaravelDDD\Factories\HasDomainFactory as HasFactory;';
        }

        return $replacements;
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
