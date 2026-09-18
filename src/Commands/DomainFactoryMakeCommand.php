<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Database\Console\Factories\FactoryMakeCommand;
use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\InteractsWithStubs;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;

class DomainFactoryMakeCommand extends FactoryMakeCommand
{
    use HasDomainStubs,
        InteractsWithStubs,
        ResolvesDomainFromInput;

    protected $name = 'ddd:factory';

    protected function getStub()
    {
        return $this->resolveDddStubPath('factory.stub');
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
