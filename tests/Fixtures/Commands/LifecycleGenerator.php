<?php

namespace Tey\LaravelDDD\Tests\Fixtures\Commands;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Tey\LaravelDDD\Commands\DomainGeneratorCommand;
use Tey\LaravelDDD\Support\GeneratorBlueprint;

class LifecycleGenerator extends DomainGeneratorCommand
{
    protected $name = 'ddd:lifecycle';

    public ?Closure $callback = null;

    public array $invocations = [];

    public function blueprint(): ?GeneratorBlueprint
    {
        return $this->blueprint;
    }

    public function handle(?Filesystem $files = null)
    {
        $this->invocations[] = [
            'name' => $this->argument('name'),
            'domain' => $this->option('domain'),
            'class' => $this->blueprint?->schema->fullyQualifiedName,
            'files' => $files,
        ];

        return $this->callback ? ($this->callback)($this) : 17;
    }

    protected function getStub()
    {
        throw new \LogicException('This command exercises the lifecycle without rendering a stub.');
    }
}
