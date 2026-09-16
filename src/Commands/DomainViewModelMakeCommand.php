<?php

namespace Tey\LaravelDDD\Commands;

use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Support\DomainResolver;

class DomainViewModelMakeCommand extends DomainGeneratorCommand
{
    use HasDomainStubs;

    protected $name = 'ddd:view-model';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a view model';

    protected $type = 'View Model';

    protected function configure(): void
    {
        $this->setAliases([
            'ddd:viewmodel',
        ]);

        parent::configure();
    }

    protected function getStub()
    {
        return $this->resolveDddStubPath('view-model.stub');
    }

    protected function preparePlaceholders(): array
    {
        $baseClass = config('ddd.base_view_model');
        $baseClassName = class_basename($baseClass);

        return [
            'extends' => filled($baseClass) ? " extends {$baseClassName}" : '',
            'baseClassImport' => filled($baseClass) ? "use {$baseClass};" : '',
        ];
    }

    public function handle()
    {
        if ($this->shouldCreateBaseViewModel()) {
            $baseViewModel = config('ddd.base_view_model');

            $this->warn("Base view model {$baseViewModel} doesn't exist, generating...");

            $domain = DomainResolver::guessDomainFromClass($baseViewModel);

            $name = str($baseViewModel)
                ->after($domain)
                ->replace(['\\', '/'], '/')
                ->toString();

            $this->call(DomainBaseViewModelMakeCommand::class, [
                '--domain' => $domain,
                'name' => $name,
            ]);
        }

        return parent::handle();
    }

    protected function shouldCreateBaseViewModel(): bool
    {
        $baseViewModel = config('ddd.base_view_model');

        // If the class exists, we don't need to create it.
        if (class_exists($baseViewModel)) {
            return false;
        }

        // If the class is outside of the domain layer, we won't attempt to create it.
        if (! DomainResolver::isDomainClass($baseViewModel)) {
            return false;
        }

        // At this point the class is probably a domain object, but we should
        // check if the expected path exists.
        if (file_exists(app()->basePath(DomainResolver::guessPathFromClass($baseViewModel)))) {
            return false;
        }

        return true;
    }
}
