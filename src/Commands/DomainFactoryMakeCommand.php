<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Database\Console\Factories\FactoryMakeCommand;
use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\InteractsWithStubs;
use Tey\LaravelDDD\Commands\Concerns\MapsRelatedKinds;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\Mod\Commands\FactoryCommand;

class DomainFactoryMakeCommand extends FactoryCommand
{
    use HasDomainStubs,
        InteractsWithStubs,
        MapsRelatedKinds,
        ResolvesDomainFromInput;

    protected $name = 'ddd:factory';

    protected function getStub()
    {
        return $this->resolveDddStubPath('factory.stub');
    }

    /**
     * The domain factory stub names its model itself; render it as natively,
     * then fill the domain placeholders.
     */
    protected function buildClass($name)
    {
        return $this->applyPlaceholders(FactoryMakeCommand::buildClass($name));
    }

    protected function getNamespace($name)
    {
        return str($this->blueprint->getFactoryFor($this->getNameInput())->fullyQualifiedName)
            ->beforeLast('\\')->toString();
    }

    protected function preparePlaceholders(): array
    {
        $name = $this->getNameInput();

        $modelName = $this->option('model') ?: $this->guessModelName($name);

        $domainModel = $this->blueprint->getModelFor($modelName);

        $domainFactory = $this->blueprint->getFactoryFor($name);

        return [
            'namespacedModel' => $domainModel->fullyQualifiedName,
            'model' => class_basename($domainModel->fullyQualifiedName),
            'factory' => $domainFactory->name,
            'namespace' => $domainFactory->namespace,
        ];
    }

    protected function guessModelName($name)
    {
        if (str_ends_with($name, 'Factory')) {
            $name = substr($name, 0, -7);
        }

        return $this->blueprint->getModelFor(class_basename($name))->name;
    }
}
