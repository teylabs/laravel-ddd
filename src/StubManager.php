<?php

namespace Tey\LaravelDDD;

use Illuminate\Foundation\Console\StubPublishCommand;
use ReflectionClass;

class StubManager
{
    public function __construct() {}

    public function allStubs()
    {
        return [
            ...$this->dddStubs(),
            ...$this->frameworkStubs(),
        ];
    }

    public function dddStubs()
    {
        return [
            realpath(__DIR__.'/../stubs/action.stub') => 'action.stub',
            realpath(__DIR__.'/../stubs/dto.stub') => 'dto.stub',
            realpath(__DIR__.'/../stubs/value-object.stub') => 'value-object.stub',
            realpath(__DIR__.'/../stubs/view-model.stub') => 'view-model.stub',
            realpath(__DIR__.'/../stubs/base-view-model.stub') => 'base-view-model.stub',
            realpath(__DIR__.'/../stubs/base-model.stub') => 'base-model.stub',
            realpath(__DIR__.'/../stubs/factory.stub') => 'factory.stub',
        ];
    }

    public function frameworkStubs()
    {
        $laravelStubCommand = new ReflectionClass(new StubPublishCommand);

        $dir = dirname($laravelStubCommand->getFileName());

        $stubs = [
            $dir.'/stubs/cast.inbound.stub' => 'cast.inbound.stub',
            $dir.'/stubs/cast.stub' => 'cast.stub',
            $dir.'/stubs/class.stub' => 'class.stub',
            $dir.'/stubs/class.invokable.stub' => 'class.invokable.stub',
            $dir.'/stubs/console.stub' => 'console.stub',
            $dir.'/stubs/enum.stub' => 'enum.stub',
            $dir.'/stubs/enum.backed.stub' => 'enum.backed.stub',
            $dir.'/stubs/event.stub' => 'event.stub',
            $dir.'/stubs/job.queued.stub' => 'job.queued.stub',
            $dir.'/stubs/job.stub' => 'job.stub',
            $dir.'/stubs/listener.typed.queued.stub' => 'listener.typed.queued.stub',
            $dir.'/stubs/listener.queued.stub' => 'listener.queued.stub',
            $dir.'/stubs/listener.typed.stub' => 'listener.typed.stub',
            $dir.'/stubs/listener.stub' => 'listener.stub',
            $dir.'/stubs/mail.stub' => 'mail.stub',
            $dir.'/stubs/markdown-mail.stub' => 'markdown-mail.stub',
            $dir.'/stubs/markdown-notification.stub' => 'markdown-notification.stub',
            $dir.'/stubs/model.pivot.stub' => 'model.pivot.stub',
            $dir.'/stubs/model.stub' => 'model.stub',
            $dir.'/stubs/notification.stub' => 'notification.stub',
            $dir.'/stubs/observer.plain.stub' => 'observer.plain.stub',
            $dir.'/stubs/observer.stub' => 'observer.stub',
            // $dir . '/stubs/pest.stub' => 'pest.stub',
            // $dir . '/stubs/pest.unit.stub' => 'pest.unit.stub',
            $dir.'/stubs/policy.plain.stub' => 'policy.plain.stub',
            $dir.'/stubs/policy.stub' => 'policy.stub',
            $dir.'/stubs/provider.stub' => 'provider.stub',
            $dir.'/stubs/request.stub' => 'request.stub',
            $dir.'/stubs/resource.stub' => 'resource.stub',
            $dir.'/stubs/resource-collection.stub' => 'resource-collection.stub',
            $dir.'/stubs/rule.stub' => 'rule.stub',
            $dir.'/stubs/scope.stub' => 'scope.stub',
            // $dir.'/stubs/test.stub' => 'test.stub',
            // $dir.'/stubs/test.unit.stub' => 'test.unit.stub',
            $dir.'/stubs/trait.stub' => 'trait.stub',
            $dir.'/stubs/view-component.stub' => 'view-component.stub',
            // Factories will use a ddd-specific stub
            // realpath($dir . '/../../Database/Console/Factories/stubs/factory.stub') => 'factory.stub',
            realpath($dir.'/../../Database/Console/Seeds/stubs/seeder.stub') => 'seeder.stub',
            realpath($dir.'/../../Database/Migrations/stubs/migration.create.stub') => 'migration.create.stub',
            realpath($dir.'/../../Database/Migrations/stubs/migration.stub') => 'migration.stub',
            realpath($dir.'/../../Database/Migrations/stubs/migration.update.stub') => 'migration.update.stub',
            realpath($dir.'/../../Routing/Console/stubs/controller.api.stub') => 'controller.api.stub',
            realpath($dir.'/../../Routing/Console/stubs/controller.invokable.stub') => 'controller.invokable.stub',
            realpath($dir.'/../../Routing/Console/stubs/controller.model.api.stub') => 'controller.model.api.stub',
            realpath($dir.'/../../Routing/Console/stubs/controller.model.stub') => 'controller.model.stub',
            realpath($dir.'/../../Routing/Console/stubs/controller.nested.api.stub') => 'controller.nested.api.stub',
            realpath($dir.'/../../Routing/Console/stubs/controller.nested.singleton.api.stub') => 'controller.nested.singleton.api.stub',
            realpath($dir.'/../../Routing/Console/stubs/controller.nested.singleton.stub') => 'controller.nested.singleton.stub',
            realpath($dir.'/../../Routing/Console/stubs/controller.nested.stub') => 'controller.nested.stub',
            realpath($dir.'/../../Routing/Console/stubs/controller.plain.stub') => 'controller.plain.stub',
            realpath($dir.'/../../Routing/Console/stubs/controller.singleton.api.stub') => 'controller.singleton.api.stub',
            realpath($dir.'/../../Routing/Console/stubs/controller.singleton.stub') => 'controller.singleton.stub',
            realpath($dir.'/../../Routing/Console/stubs/controller.stub') => 'controller.stub',
            realpath($dir.'/../../Routing/Console/stubs/middleware.stub') => 'middleware.stub',
        ];

        // Some stubs are not available across all Laravel versions,
        // so we'll just skip the files that don't exist.
        return collect($stubs)->filter(function ($stub, $path) {
            return file_exists($path);
        })->all();
    }
}
