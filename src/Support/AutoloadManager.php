<?php

namespace Tey\LaravelDDD\Support;

use ArrayObject;
use Closure;
use Composer\Autoload\ClassLoader;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Mockery;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tey\LaravelDDD\Facades\DDD;
use Tey\LaravelDDD\Factories\DomainFactory;
use Tey\LaravelDDD\Support\Layout\DddLayout;
use Tey\LaravelDDD\Support\Layout\DddReverse;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryDefinition;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Exceptions\InvalidDiscoveryCache;
use Tey\Mod\Layout\CompiledRoot;
use Throwable;
use WeakMap;

class AutoloadManager
{
    use Conditionable;

    /** The discovered families, the config key that enables each and the mod discovery type. */
    private const FAMILIES = [
        'providers' => ['provider', 'provider'],
        'commands' => ['command', 'command'],
        'listeners' => ['listener', 'listener'],
    ];

    protected $app;

    protected string $appNamespace;

    protected array $registeredCommands = [];

    protected array $registeredProviders = [];

    protected array $resolvedPolicies = [];

    protected array $resolvedFactories = [];

    protected static ?Closure $policyResolver = null;

    protected static ?Closure $factoryResolver = null;

    protected array $registeredListeners = [];

    protected array $registeredSubscribers = [];

    protected bool $booted = false;

    protected bool $consoleBooted = false;

    protected bool $ran = false;

    /** @var WeakMap<object, ArrayObject<string, true>>|null successful event registrations per dispatcher */
    private static ?WeakMap $registeredEvents = null;

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
        foreach ($this->registeredProviders as $provider) {
            $this->app?->forgetInstance($provider);
        }

        $this->registeredProviders = [];

        $this->registeredCommands = [];

        $this->resolvedPolicies = [];

        $this->resolvedFactories = [];

        $this->registeredListeners = [];

        $this->registeredSubscribers = [];

        ArtisanCommands::booted($this->app, $this);

        return $this;
    }

    /**
     * Whether a discovered family is on: true, or a list of paths (manual discovery).
     */
    private function enabled(string $family): bool
    {
        $value = config('ddd.autoload.'.$family);

        return $value === true || (is_array($value) && $value !== []);
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

    public function getCustomLayerPaths(): array
    {
        return collect([
            ...array_values(config('ddd.layers', [])),
        ])->map(fn ($path) => Path::normalize($this->app->basePath($path)))->toArray();
    }

    protected function handleProviders()
    {
        $providers = $this->cached('providers') ?? $this->discoverProviders();

        foreach ($providers as $provider) {
            $this->registeredProviders[$provider] = $provider;
        }

        return $this;
    }

    protected function handleCommands()
    {
        $commands = $this->cached('commands') ?? $this->discoverCommands();

        foreach ($commands as $command) {
            $this->registeredCommands[$command] = $command;
        }

        return $this;
    }

    public function run()
    {
        if (! $this->isBooted()) {
            $this->boot();
        }

        foreach ($this->registeredProviders as $provider) {
            $this->app->register($provider);
        }

        $this->registerListeners($this->registeredListeners);
        $this->registerSubscribers($this->registeredSubscribers);

        if ($this->app->runningInConsole() && ! $this->isConsoleBooted()) {
            // Resolved when Artisan starts: a later boot of another manager for
            // this application replaces what Artisan sees.
            ArtisanCommands::hook($this->app, $this);

            $this->consoleBooted = true;
        }

        $this->ran = true;

        return $this;
    }

    public function getRegisteredCommands(): array
    {
        return $this->registeredCommands;
    }

    public function getRegisteredProviders(): array
    {
        return $this->registeredProviders;
    }

    public function getResolvedPolicies(): array
    {
        return $this->resolvedPolicies;
    }

    public function getResolvedFactories(): array
    {
        return $this->resolvedFactories;
    }

    protected function handlePolicies()
    {
        $resolved = &$this->resolvedPolicies;

        Gate::guessPolicyNamesUsing(static::$policyResolver = function (string $class) use (&$resolved): array|string {
            // Domain-root models only (v3 DomainObject::fromClass); others take the native guess below.
            if (DddReverse::current()->objectFromClass($class, 'model') !== null && ($policy = DddReverse::current()->policyFor($class))) {
                $resolved[$class] = $policy;

                return $policy;
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
        $resolved = &$this->resolvedFactories;

        Factory::guessFactoryNamesUsing(static::$factoryResolver = function (string $modelName) use (&$resolved) {
            if ($factoryName = DomainFactory::resolveFactoryName($modelName)) {
                $resolved[$modelName] = $factoryName;

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
        $discovered = $this->cached('listeners') ?? $this->discoverListeners();

        foreach ($discovered['listeners'] ?? [] as $event => $eventListeners) {
            foreach ($eventListeners as $listener) {
                $this->registeredListeners[$event][] = $listener;
            }
        }

        foreach ($discovered['subscribers'] ?? [] as $subscriber) {
            $this->registeredSubscribers[$subscriber] = $subscriber;
        }

        return $this;
    }

    private function registerListeners(array $listeners): void
    {
        foreach ($listeners as $event => $eventListeners) {
            foreach ($eventListeners as $listener) {
                $parts = is_array($listener) ? $listener : [$listener];
                $identity = array_map(fn ($part) => is_object($part)
                    ? ['object', spl_object_id($part)]
                    : ['value', $part], $parts);

                $this->registerOnce('listener:'.serialize([$event, $identity]), fn () => Event::listen($event, $listener));
            }
        }
    }

    private function registerSubscribers(array $subscribers): void
    {
        foreach ($subscribers as $subscriber) {
            $this->registerOnce('subscriber:'.$subscriber, fn () => Event::subscribe($subscriber));
        }
    }

    private function registerOnce(string $key, Closure $register): void
    {
        $dispatcher = Event::getFacadeRoot();
        self::$registeredEvents ??= new WeakMap;
        $registered = self::$registeredEvents[$dispatcher] ??= new ArrayObject;

        if (isset($registered[$key])) {
            return;
        }

        $register();

        // Keep only scalar identities: registry entries must not retain the
        // dispatcher (including through a closure bound to its application).
        $registered[$key] = true;
    }

    /**
     * One family from the discovery cache, or null when the cache is absent,
     * foreign, stale or names classes that no longer exist (then boot scans in
     * memory; it never writes to a deployment's cache directory).
     */
    private function cached(string $family): ?array
    {
        if (! is_file(DomainCache::path('discovery'))) {
            return null;
        }

        try {
            $inventory = $this->discovery($this->families())->readCache();
        } catch (InvalidDiscoveryCache) {
            return null;
        }

        $value = $this->family($family, $inventory, $this->scanPaths($family));

        if ($family !== 'listeners') {
            return $this->cachedClassesExist($value) ? $value : null;
        }

        $classes = $value['subscribers'];

        foreach ($value['listeners'] as $listeners) {
            foreach ($listeners as $listener) {
                $classes[] = is_array($listener) ? $listener[0] : $listener;
            }
        }

        return $this->cachedClassesExist($classes) && $this->cachedListenerMethodsExist($value) ? $value : null;
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
     * are discovered too. Autoload errors deliberately propagate.
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
        return $this->discover('providers');
    }

    public function discoverCommands(): array
    {
        return $this->discover('commands');
    }

    public function discoverListeners(): array
    {
        return $this->discover('listeners');
    }

    /**
     * A cold scan of one family: the files admitted by finder() (called once,
     * with v3's paths) are the candidates; mod owns class identity and
     * eligibility; the result is ordered by v3 scan path, then file path.
     */
    private function discover(string $family): array
    {
        $paths = $this->scanPaths($family);

        if ($paths === []) {
            return $family === 'listeners' ? ['listeners' => [], 'subscribers' => []] : [];
        }

        $inventory = $this->discovery([$family], [$family => $this->admitted($paths)])->scan();

        return $this->family($family, $inventory, $paths);
    }

    /**
     * The directories a family scans, exactly as v3: every layer path when the
     * config value is true, the configured paths otherwise, existing ones only.
     *
     * @return array<array-key, string>
     */
    private function scanPaths(string $family): array
    {
        $configValue = config('ddd.autoload.'.$family);

        if ($configValue === false || $configValue === null) {
            return [];
        }

        return $this->normalizePaths($configValue === true ? $this->getAllLayerPaths() : $configValue);
    }

    /**
     * The base-relative paths of the files finder() admits (one finder() call).
     *
     * @return list<string>
     */
    private function admitted(array $paths): array
    {
        $base = rtrim(Path::normalize($this->app->basePath()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $files = [];

        foreach ($this->finder($paths) as $file) {
            $pathname = Path::normalize($file->getPathname());

            if (str_starts_with($pathname, $base)) {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($pathname, strlen($base)));
            }
        }

        return $files;
    }

    /**
     * Families discovery covers right now (cache identity).
     *
     * @return list<string>
     */
    private function families(): array
    {
        return array_values(array_filter(array_keys(self::FAMILIES), fn (string $family) => $this->enabled($family)));
    }

    /**
     * A mod discovery over the compiled ddd layout (plus discovery-only roots
     * for manual paths), for the given families. $admitted holds, per family,
     * the candidate files; a family without them gets them from finder() when
     * mod asks (a cache write).
     *
     * @param  list<string>  $families
     * @param  array<string, list<string>>  $admitted
     */
    private function discovery(array $families, array $admitted = [])
    {
        $layout = DddLayout::current();
        $manual = [];

        foreach ($families as $family) {
            $value = config('ddd.autoload.'.$family);

            if (is_array($value)) {
                array_push($manual, ...array_values($this->normalizePaths($value)));
            }
        }

        $compiled = $layout->withDiscoveryPaths($this->outsideLayoutRoots($layout, array_values(array_unique($manual))));
        $kinds = [];
        $types = [];

        foreach ($families as $family) {
            [$type, $discoveryType] = self::FAMILIES[$family];

            foreach ($layout->kindIds($type) as $kindId) {
                if ($compiled->hasFileType($kindId)) {
                    $kinds[$kindId] = $discoveryType;
                    $types[$discoveryType] = $family;
                }
            }
        }

        // Only the kinds asked for: every other kind of the layout is switched off.
        foreach (array_keys($compiled->fileTypes()) as $kindId) {
            $kinds[$kindId] ??= false;
        }

        $candidates = function (CompiledRoot $root, string $basePath, DiscoveryDefinition $definition) use (&$admitted, $types): array {
            $family = $types[$definition->type->value] ?? null;

            if ($family === null) {
                return [];
            }

            $admitted[$family] ??= $this->admitted($this->scanPaths($family));
            $prefix = $root->path === '' ? '' : $root->path.'/';

            return array_values(array_filter($admitted[$family], fn (string $file) => $prefix === '' || str_starts_with($file, $prefix)));
        };

        $options = DiscoveryOptions::fromConfig([
            'file_types' => $kinds,
            'cache' => DomainCache::path('discovery'),
            'on_stale_cache' => 'scan',
        ])->withCandidates($candidates);

        return new Discovery(layout: $compiled, options: $options, basePath: $this->app->basePath());
    }

    /**
     * Manual paths that no root of the ddd layout already covers: a path inside
     * the domain, application or a layer root is discovered through that root.
     *
     * @param  list<string>  $paths  absolute directories
     * @return list<string>
     */
    private function outsideLayoutRoots(DddLayout $layout, array $paths): array
    {
        $base = rtrim(Path::normalize($this->app->basePath()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $roots = array_map(fn (CompiledRoot $root) => $base.Path::normalize($root->path), $layout->compiled()->roots());

        return array_values(array_filter($paths, function (string $path) use ($roots) {
            $path = rtrim(Path::normalize($path), DIRECTORY_SEPARATOR);

            foreach ($roots as $root) {
                if ($path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * One family out of an inventory, in v3's shape and order: grouped by the
     * scan paths in their configured order (a path listed twice yields its
     * classes twice, as v3's Finder did), sorted by file path within a path.
     */
    private function family(string $family, object $inventory, array $paths): array
    {
        $type = self::FAMILIES[$family][1];
        $base = rtrim(Path::normalize($this->app->basePath()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $entries = array_values($inventory->ofType(DiscoveryType::from($type)));
        usort($entries, fn (object $a, object $b) => strcmp($a->path, $b->path));
        $ordered = [];

        foreach ($paths as $path) {
            $prefix = rtrim(Path::normalize($path), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

            foreach ($entries as $entry) {
                if (str_starts_with($base.Path::normalize($entry->path), $prefix)) {
                    $ordered[] = $entry;
                }
            }
        }

        if ($family !== 'listeners') {
            return array_values(array_map(fn (object $entry) => $entry->class, $ordered));
        }

        return $this->listenerManifest($ordered);
    }

    /**
     * Listeners as v3 reported them: event => ['Class' (handle/__invoke) |
     * [Class, method]], each once per event; subscribers are the discovered
     * listener classes with a public subscribe() taking exactly one argument.
     *
     * @param  list<object>  $entries  discovered listener classes (class, path, events)
     */
    private function listenerManifest(array $entries): array
    {
        $listeners = [];

        foreach ($entries as $entry) {
            foreach ($entry->events as $binding) {
                $resolved = in_array($binding['method'], ['handle', '__invoke'], true)
                    ? $entry->class
                    : [$entry->class, $binding['method']];

                if (! in_array($resolved, $listeners[$binding['event']] ?? [], true)) {
                    $listeners[$binding['event']][] = $resolved;
                }
            }
        }

        $candidates = [];

        foreach ($listeners as $eventListeners) {
            foreach ($eventListeners as $listener) {
                $candidates[is_array($listener) ? $listener[0] : $listener] = true;
            }
        }

        $subscribers = array_values(array_filter(array_keys($candidates), function (string $class) {
            try {
                $method = (new ReflectionClass($class))->getMethod('subscribe');

                return $method->isPublic() && $method->getNumberOfParameters() === 1;
            } catch (Throwable) {
                return false;
            }
        }));

        return ['listeners' => $listeners, 'subscribers' => $subscribers];
    }

    /**
     * Write the discovery cache (one file for every enabled family) from a cold scan.
     */
    public function cacheDiscovery()
    {
        $discovery = $this->discovery($this->families());

        $discovery->cacheInventory();

        return $this;
    }

    public function cacheCommands()
    {
        return $this->cacheDiscovery();
    }

    public function cacheProviders()
    {
        return $this->cacheDiscovery();
    }

    public function cacheListeners()
    {
        return $this->cacheDiscovery();
    }

    public function getRegisteredListeners(): array
    {
        return $this->registeredListeners;
    }

    public function getRegisteredSubscribers(): array
    {
        return $this->registeredSubscribers;
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
