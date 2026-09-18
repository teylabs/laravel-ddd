<?php

namespace Tey\LaravelDDD\Support;

use Closure;
use Composer\Autoload\ClassLoader;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Mockery;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tey\LaravelDDD\Facades\DDD;
use Tey\LaravelDDD\Factories\DomainFactory;
use Tey\LaravelDDD\ValueObjects\DomainObject;
use Throwable;

class AutoloadManager
{
    use Conditionable;

    protected $app;

    protected string $appNamespace;

    protected static array $registeredCommands = [];

    protected static array $registeredProviders = [];

    protected static array $resolvedPolicies = [];

    protected static array $resolvedFactories = [];

    protected static ?Closure $policyResolver = null;

    protected static ?Closure $factoryResolver = null;

    protected static array $registeredListeners = [];

    protected static array $registeredSubscribers = [];

    protected bool $booted = false;

    protected bool $consoleBooted = false;

    protected bool $ran = false;

    public function __construct(protected ?Container $container = null)
    {
        $this->container = $container ?? Container::getInstance();

        $this->app = $this->container->make(Application::class);

        $this->appNamespace = $this->app->getNamespace();
    }

    public function boot()
    {
        $this->booted = true;

        if (! config()->has('ddd.autoload')) {
            return $this->flush();
        }

        $this
            ->flush()
            ->when(config('ddd.autoload.providers') === true, fn () => $this->handleProviders())
            ->when($this->app->runningInConsole() && config('ddd.autoload.commands') === true, fn () => $this->handleCommands())
            ->when(config('ddd.autoload.policies') === true, fn () => $this->handlePolicies())
            ->when(config('ddd.autoload.factories') === true, fn () => $this->handleFactories())
            ->when(config('ddd.autoload.listeners') === true, fn () => $this->handleListeners());

        return $this;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function isConsoleBooted(): bool
    {
        return $this->consoleBooted;
    }

    public function hasRun(): bool
    {
        return $this->ran;
    }

    protected function flush()
    {
        foreach (static::$registeredProviders as $provider) {
            $this->app?->forgetInstance($provider);
        }

        static::$registeredProviders = [];

        static::$registeredCommands = [];

        static::$resolvedPolicies = [];

        static::$resolvedFactories = [];

        static::$registeredListeners = [];

        static::$registeredSubscribers = [];

        return $this;
    }

    protected function normalizePaths($path): array
    {
        return collect($path)
            ->filter(fn ($path) => is_dir($path))
            ->toArray();
    }

    public function getAllLayerPaths(): array
    {
        return collect([
            DomainResolver::domainPath(),
            DomainResolver::applicationLayerPath(),
            ...array_values(config('ddd.layers', [])),
        ])->map(fn ($path) => Path::normalize($this->app->basePath($path)))->toArray();
    }

    protected function getCustomLayerPaths(): array
    {
        return collect([
            ...array_values(config('ddd.layers', [])),
        ])->map(fn ($path) => Path::normalize($this->app->basePath($path)))->toArray();
    }

    protected function handleProviders()
    {
        $providers = DomainCache::has('domain-providers')
            ? DomainCache::get('domain-providers')
            : $this->discoverProviders();

        if (DomainCache::has('domain-providers') && ! $this->cachedClassesExist($providers)) {
            $providers = $this->discoverProviders();
        }

        foreach ($providers as $provider) {
            static::$registeredProviders[$provider] = $provider;
        }

        return $this;
    }

    protected function handleCommands()
    {
        $commands = DomainCache::has('domain-commands')
            ? DomainCache::get('domain-commands')
            : $this->discoverCommands();

        if (DomainCache::has('domain-commands') && ! $this->cachedClassesExist($commands)) {
            $commands = $this->discoverCommands();
        }

        foreach ($commands as $command) {
            static::$registeredCommands[$command] = $command;
        }

        return $this;
    }

    public function run()
    {
        if (! $this->isBooted()) {
            $this->boot();
        }

        $registration = new DomainRegistration;

        $registration->providers($this->app, static::$registeredProviders);
        $registration->listeners(static::$registeredListeners);
        $registration->subscribers(static::$registeredSubscribers);

        if ($this->app->runningInConsole() && ! $this->isConsoleBooted()) {
            $registration->commands(fn () => static::$registeredCommands);

            $this->consoleBooted = true;
        }

        $this->ran = true;

        return $this;
    }

    public function getRegisteredCommands(): array
    {
        return static::$registeredCommands;
    }

    public function getRegisteredProviders(): array
    {
        return static::$registeredProviders;
    }

    public function getResolvedPolicies(): array
    {
        return static::$resolvedPolicies;
    }

    public function getResolvedFactories(): array
    {
        return static::$resolvedFactories;
    }

    protected function handlePolicies()
    {
        Gate::guessPolicyNamesUsing(static::$policyResolver = function (string $class): array|string {
            if ($model = DomainObject::fromClass($class, 'model')) {
                $resolved = (new Domain($model->domain))
                    ->object('policy', "{$model->name}Policy")
                    ->fullyQualifiedName;

                static::$resolvedPolicies[$class] = $resolved;

                return $resolved;
            }

            $classDirname = str_replace('/', '\\', dirname(str_replace('\\', '/', $class)));

            $classDirnameSegments = explode('\\', $classDirname);

            return Arr::wrap(Collection::times(count($classDirnameSegments), function ($index) use ($class, $classDirnameSegments) {
                $classDirname = implode('\\', array_slice($classDirnameSegments, 0, $index));

                return $classDirname.'\\Policies\\'.class_basename($class).'Policy';
            })->reverse()->values()->first(function ($class) {
                return class_exists($class);
            }) ?: [$classDirname.'\\Policies\\'.class_basename($class).'Policy']);
        });

        return $this;
    }

    protected function handleFactories()
    {
        Factory::guessFactoryNamesUsing(static::$factoryResolver = function (string $modelName) {
            if ($factoryName = DomainFactory::resolveFactoryName($modelName)) {
                static::$resolvedFactories[$modelName] = $factoryName;

                return $factoryName;
            }

            $modelName = Str::startsWith($modelName, $this->appNamespace.'Models\\')
                ? Str::after($modelName, $this->appNamespace.'Models\\')
                : Str::after($modelName, $this->appNamespace);

            return 'Database\\Factories\\'.$modelName.'Factory';
        });

        return $this;
    }

    protected function handleListeners()
    {
        $cached = DomainCache::has('domain-listeners')
            ? DomainCache::get('domain-listeners')
            : $this->discoverListeners();

        $classes = $cached['subscribers'] ?? [];

        foreach ($cached['listeners'] ?? [] as $listeners) {
            foreach ($listeners as $listener) {
                $class = is_array($listener) ? $listener[0] : $listener;
                if (is_string($class)) {
                    $classes[] = Str::before($class, '@');
                }
            }
        }

        if (DomainCache::has('domain-listeners')
            && (! $this->cachedClassesExist($classes) || ! $this->cachedListenerMethodsExist($cached))) {
            $cached = $this->discoverListeners();
        }

        // Normalize older manifests too: subscriber handlers belong to subscribe().
        collect(DomainDiscovery::withoutSubscriberListeners($cached['listeners'] ?? [], $cached['subscribers'] ?? []))
            ->each(fn (array $eventListeners, string $event) => collect($eventListeners)->each(fn ($listener) => static::$registeredListeners[$event][] = $listener
            )
            );

        collect($cached['subscribers'] ?? [])
            ->each(fn (string $subscriber) => static::$registeredSubscribers[$subscriber] = $subscriber
            );

        return $this;
    }

    private function cachedListenerMethodsExist(array $manifest): bool
    {
        foreach ($manifest['subscribers'] ?? [] as $subscriber) {
            if (! method_exists($subscriber, 'subscribe') && ! method_exists($subscriber, '__call')) {
                return false;
            }
        }

        foreach ($manifest['listeners'] ?? [] as $listeners) {
            foreach ($listeners as $listener) {
                if (is_array($listener)) {
                    [$class, $method] = $listener;
                } elseif (is_string($listener)) {
                    [$class, $method] = array_pad(explode('@', $listener, 2), 2, 'handle');
                } else {
                    continue;
                }

                // Laravel falls back to __invoke when the named handler is
                // absent. Do not instantiate listeners just to validate them.
                if (! method_exists($class, $method)
                    && ! method_exists($class, '__invoke')
                    && ! method_exists($class, '__call')) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A removed class invalidates its whole inventory so renamed replacements
     * are discovered too. Recovery is in memory: boot must not write to a
     * deployment's cache directory. Autoload errors deliberately propagate.
     */
    private function cachedClassesExist(array $classes): bool
    {
        foreach (array_unique($classes) as $class) {
            // Optimized Composer maps can retain deleted paths after an SSH
            // hotfix. Avoid executing that stale include; errors inside files
            // that still exist must continue to propagate normally.
            if (! class_exists($class, false)) {
                foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                    $file = $loader->findFile($class);
                    if ($file !== false && ! is_file($file)) {
                        return false;
                    }
                }
            }

            if (! class_exists($class)) {
                return false;
            }
        }

        return true;
    }

    protected function finder($paths)
    {
        $filter = DDD::getAutoloadFilter() ?? function (SplFileInfo $file) {
            $pathAfterDomain = str($file->getRelativePath())
                ->replace('\\', '/')
                ->after('/')
                ->finish('/');

            $ignoredFolders = collect(config('ddd.autoload_ignore', []))
                ->map(fn ($path) => Str::finish($path, '/'));

            if ($pathAfterDomain->startsWith($ignoredFolders)) {
                return false;
            }
        };

        return Finder::create()
            ->files()
            ->in($paths)
            ->filter($filter);
    }

    public function discoverProviders(): array
    {
        $configValue = config('ddd.autoload.providers');

        if ($configValue === false) {
            return [];
        }

        $paths = $this->normalizePaths(
            $configValue === true
                ? $this->getAllLayerPaths()
                : $configValue
        );

        if (empty($paths)) {
            return [];
        }

        return (new DomainDiscovery)->providers($this->finder($paths));
    }

    public function discoverCommands(): array
    {
        $configValue = config('ddd.autoload.commands');

        if ($configValue === false) {
            return [];
        }

        $paths = $this->normalizePaths(
            $configValue === true
                ? $this->getAllLayerPaths()
                : $configValue
        );

        if (empty($paths)) {
            return [];
        }

        return (new DomainDiscovery)->commands($this->finder($paths));
    }

    public function discoverListeners(): array
    {
        $configValue = config('ddd.autoload.listeners');

        if ($configValue === false) {
            return ['listeners' => [], 'subscribers' => []];
        }

        $paths = $this->normalizePaths(
            $configValue === true
                ? $this->getAllLayerPaths()
                : $configValue
        );

        if (empty($paths)) {
            return ['listeners' => [], 'subscribers' => []];
        }

        return (new DomainDiscovery)->listeners($paths, $this->app->basePath(), $this->finder($paths));
    }

    public function cacheCommands()
    {
        DomainCache::set('domain-commands', $this->discoverCommands());

        return $this;
    }

    public function cacheProviders()
    {
        DomainCache::set('domain-providers', $this->discoverProviders());

        return $this;
    }

    public function cacheListeners()
    {
        DomainCache::set('domain-listeners', $this->discoverListeners());

        return $this;
    }

    public function getRegisteredListeners(): array
    {
        return static::$registeredListeners;
    }

    public function getRegisteredSubscribers(): array
    {
        return static::$registeredSubscribers;
    }

    protected function resolveAppNamespace()
    {
        try {
            return Container::getInstance()
                ->make(Application::class)
                ->getNamespace();
        } catch (Throwable) {
            return 'App\\';
        }
    }

    public static function partialMock()
    {
        $mock = Mockery::mock(AutoloadManager::class, [null])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $mock->shouldReceive('isBooted')->andReturn(false);

        return $mock;
    }
}
