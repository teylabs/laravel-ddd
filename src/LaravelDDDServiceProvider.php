<?php

namespace Tey\LaravelDDD;

use Illuminate\Database\Migrations\MigrationCreator;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tey\LaravelDDD\Facades\Autoload;
use Tey\LaravelDDD\Listeners\CacheClearSubscriber;
use Tey\LaravelDDD\Listeners\MigrationsPrunedSubscriber;
use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Support\DomainMigration;
use Tey\LaravelDDD\Support\Layout\DddLayout;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\ModServiceProvider;

class LaravelDDDServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-ddd')
            ->hasConfigFile()
            ->hasCommands([
                Commands\InstallCommand::class,
                Commands\ConfigCommand::class,
                Commands\PublishCommand::class,
                Commands\StubCommand::class,
                Commands\UpgradeCommand::class,
                Commands\OptimizeCommand::class,
                Commands\OptimizeClearCommand::class,
                Commands\DomainListCommand::class,
                Commands\DomainModelMakeCommand::class,
                Commands\DomainFactoryMakeCommand::class,
                Commands\DomainBaseModelMakeCommand::class,
                Commands\DomainDtoMakeCommand::class,
                Commands\DomainValueObjectMakeCommand::class,
                Commands\DomainViewModelMakeCommand::class,
                Commands\DomainBaseViewModelMakeCommand::class,
                Commands\DomainActionMakeCommand::class,
                Commands\DomainCastMakeCommand::class,
                Commands\DomainChannelMakeCommand::class,
                Commands\DomainConsoleMakeCommand::class,
                Commands\DomainControllerMakeCommand::class,
                Commands\DomainClassMakeCommand::class,
                Commands\DomainEnumMakeCommand::class,
                Commands\DomainEventMakeCommand::class,
                Commands\DomainExceptionMakeCommand::class,
                Commands\DomainInterfaceMakeCommand::class,
                Commands\DomainJobMakeCommand::class,
                Commands\DomainListenerMakeCommand::class,
                Commands\DomainMailMakeCommand::class,
                Commands\DomainMiddlewareMakeCommand::class,
                Commands\DomainNotificationMakeCommand::class,
                Commands\DomainObserverMakeCommand::class,
                Commands\DomainPolicyMakeCommand::class,
                Commands\DomainProviderMakeCommand::class,
                Commands\DomainResourceMakeCommand::class,
                Commands\DomainRequestMakeCommand::class,
                Commands\DomainRuleMakeCommand::class,
                Commands\DomainScopeMakeCommand::class,
                Commands\DomainSeederMakeCommand::class,
                Commands\DomainTraitMakeCommand::class,
                Commands\Migration\DomainMigrateMakeCommand::class,
            ]);

        if ($this->app->runningUnitTests()) {
            $package->hasRoutes(['testing']);
        }

        $this->registerBindings();
    }

    protected function laravelVersion($value)
    {
        return version_compare(app()->version(), $value, '>=');
    }

    protected function registerMigrations()
    {
        $this->app->when(MigrationCreator::class)
            ->needs('$customStubPath')
            ->give(fn () => $this->app->basePath('stubs'));

        $this->app->singleton(Commands\Migration\DomainMigrateMakeCommand::class, function ($app) {
            // Once we have the migration creator registered, we will create the command
            // and inject the creator. The creator is responsible for the actual file
            // creation of the migrations, and may be extended by these developers.
            $creator = $app['migration.creator'];
            $composer = $app['composer'];

            return new Commands\Migration\DomainMigrateMakeCommand($creator, $composer);
        });

        $this->loadMigrationsFrom(DomainMigration::paths());

        return $this;
    }

    protected function registerBindings()
    {
        $this->app->scoped(DomainManager::class, function () {
            return new DomainManager;
        });

        $this->app->scoped(ComposerManager::class, function () {
            return ComposerManager::make($this->app->basePath('composer.json'));
        });

        $this->app->scoped(ConfigManager::class, function () {
            return new ConfigManager($this->app->configPath('ddd.php'));
        });

        $this->app->scoped(StubManager::class, function () {
            return new StubManager;
        });

        $this->app->scoped(AutoloadManager::class, function () {
            return new AutoloadManager;
        });

        ModServiceProvider::registerGenerationServices($this->app);
        // Preview identity formatting reads the host's live layout through the public value.
        $this->app->bindIf(CompiledLayout::class, fn () => DddLayout::current()->compiled());

        $this->app->bind('ddd', DomainManager::class);
        $this->app->bind('ddd.autoloader', AutoloadManager::class);
        $this->app->bind('ddd.config', ConfigManager::class);
        $this->app->bind('ddd.composer', ComposerManager::class);
        $this->app->bind('ddd.stubs', StubManager::class);

        return $this;
    }

    public function packageBooted()
    {
        Autoload::run();

        if ($this->app->runningInConsole()) {
            $this->optimizes(
                optimize: 'ddd:optimize',
                clear: 'ddd:clear',
                key: 'laravel-ddd',
            );
        }

        Event::subscribe(MigrationsPrunedSubscriber::class);
        Event::subscribe(CacheClearSubscriber::class);
    }

    public function packageRegistered()
    {
        $this->registerMigrations();
    }
}
