<?php

namespace Tey\LaravelDDD\Support\Upgrade;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Tey\LaravelDDD\Facades\DDD;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\LaravelDDD\Support\Layout\DddLayout;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\Layout;
use Tey\Mod\ModServiceProvider;
use Tey\Mod\Placement\PlacementContext;

/**
 * Plans and applies the move of an application from Laravel-DDD to plain
 * tey/mod: config/ddd.php becomes config/mod.php with the built-in `ddd`
 * layout, any customisation becomes a Mod::layout('ddd') extension in a
 * provider, Laravel-DDD classes in application code become Laravel's own,
 * published stubs get mod's names, and the Laravel-DDD caches go.
 *
 * Anything that cannot move without changing what the application does at
 * runtime is a blocker: the plan is still shown, but nothing is written.
 */
final class ModUpgrade
{
    /** Container key of the Composer runner: fn (list<string> $command): int (exit code). */
    public const COMPOSER_RUNNER = 'ddd.upgrade.composer-runner';

    public const PROVIDER = 'App\\Providers\\ModLayoutServiceProvider';

    private const DISCOVERED = ['providers' => 'provider', 'commands' => 'command', 'listeners' => 'listener'];

    /** Published Laravel-DDD stub => mod's stub for the same kind. */
    private const STUBS = ['dto.stub' => 'mod.dto.stub', 'view-model.stub' => 'mod.view-model.stub', 'value-object.stub' => 'mod.value-object.stub', 'action.stub' => 'mod.action.stub'];

    private const PLACEHOLDERS = [
        '{{ baseClassImport }}' => '{{ baseImport }}', '{{baseClassImport}}' => '{{baseImport}}',
        '{{ extendsBaseClass }}' => '{{ extends }}', '{{extendsBaseClass}}' => '{{extends}}',
    ];

    private function __construct(private readonly Application $app) {}

    public static function for(Application $app): self
    {
        return new self($app);
    }

    public function plan(): ModUpgradePlan
    {
        $plan = new ModUpgradePlan;
        $config = (array) config('ddd', []);

        $this->blockers($plan, $config);

        if (! $this->namespacesMissing($config)) {
            $this->layout($plan, $config);
            $this->config($plan, $config);
            $this->provider($plan);
        }

        $this->rewrites($plan);
        $this->stubs($plan);
        $this->caches($plan, $config);

        if (is_file($this->app->configPath('ddd.php'))) {
            $plan->attention[] = 'config/ddd.php is no longer read once tey/laravel-ddd is removed; delete it after checking the result.';
        }

        return $plan;
    }

    /**
     * Write the plan (never with blockers).
     */
    public function apply(ModUpgradePlan $plan): void
    {
        if ($plan->blocked()) {
            throw new \LogicException('A plan with blockers is never applied.');
        }

        foreach ($plan->writes as $relative => ['after' => $contents]) {
            $path = $this->path($relative);

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            file_put_contents($path, $contents);
        }

        foreach ($plan->deletes as $relative) {
            @unlink($this->path($relative));
        }

        foreach ($plan->providers as $provider) {
            ServiceProvider::addProviderToBootstrapFile($provider, $this->app->bootstrapPath('providers.php'));
        }
    }

    /**
     * Run the Composer changes in order, Laravel-DDD's removal last. Returns the first failing exit code, or 0.
     */
    public function runComposer(ModUpgradePlan $plan): int
    {
        $runner = $this->app->bound(self::COMPOSER_RUNNER)
            ? $this->app->make(self::COMPOSER_RUNNER)
            : fn (array $command): int => (new Process($command, $this->app->basePath(), null, null, null))->run();

        foreach ($plan->composer as $command) {
            if (($exit = $runner($command)) !== 0) {
                return $exit;
            }
        }

        return 0;
    }

    private function blockers(ModUpgradePlan $plan, array $config): void
    {
        foreach (['domain_namespace', 'application_namespace'] as $key) {
            if (! filled($config[$key] ?? null)) {
                $plan->blockers[] = "ddd.{$key} is empty; mod's ddd layout needs a namespace for that root.";
            }
        }

        foreach ((array) ($config['autoload'] ?? []) as $family => $value) {
            if (is_array($value)) {
                $plan->blockers[] = sprintf('ddd.autoload.%s lists paths (%s); mod discovers only within the layout\'s roots.', $family, implode(', ', $value));
            }
        }

        foreach ($this->codeFiles() as $relative => $contents) {
            if (str_contains($contents, 'filterAutoloadPathsUsing')) {
                $plan->blockers[] = "{$relative} calls DDD::filterAutoloadPathsUsing(); move the filter to Mod::discoverUsing() first.";
            }

            foreach (['resolveObjectSchemaUsing', 'filterApplicationLayerUsing'] as $hook) {
                if (str_contains($contents, $hook)) {
                    $plan->attention[] = "{$relative} calls DDD::{$hook}(), which only Laravel-DDD's generators use; mod's generators place files by the layout.";
                }
            }
        }
    }

    private function namespacesMissing(array $config): bool
    {
        return ! filled($config['domain_namespace'] ?? null) || ! filled($config['application_namespace'] ?? null);
    }

    /**
     * The Mod::layout('ddd') calls that make mod's built-in layout place and discover as this configuration does.
     */
    private function layout(ModUpgradePlan $plan, array $config): void
    {
        $base = Layout::fresh(DddLayout::EXTENDS)->compiled();
        $roots = [
            'domain' => [$this->namespace($config['domain_namespace']), str_replace('\\', '/', (string) ($config['domain_path'] ?? 'src/Domain'))],
            'application' => [$this->namespace($config['application_namespace']), str_replace('\\', '/', (string) ($config['application_path'] ?? 'app/Modules'))],
        ];

        foreach ($roots as $name => [$namespace, $path]) {
            $shipped = $base->roots()[$name];

            if ($shipped->namespace !== $namespace || $shipped->path !== str_replace('\\', '/', $path)) {
                $plan->layoutCalls[] = ['mounts', [$name, $namespace, str_replace('\\', '/', $path)]];
                if ($name === 'domain') {
                    $plan->layoutCalls[] = ['path', [str_replace('\\', '/', $path).'/{domain}']];
                }
                $plan->converted[] = "Root [{$name}]: {$namespace} → {$path}";
            }
        }

        $types = array_values(array_unique([...array_filter(array_keys((array) ($config['namespaces'] ?? [])), 'is_string'), ...DddLayout::TYPES]));

        foreach ($types as $type) {
            $kind = DddLayout::MOD_KINDS[$type] ?? null;
            $home = $this->isApplicationObject($type, $config) ? 'application' : 'domain';
            $folder = trim(str_replace('\\', '/', DomainResolver::getRelativeObjectNamespace($type)), '/');
            $in = $home.':{domain+}'.($folder === '' ? '' : '/'.$folder);

            if ($kind === null) {
                if (in_array($type, DddLayout::TYPES, true)) {
                    continue; // subscriber: discovered with listeners, never generated on its own
                }

                $id = Str::kebab(str_replace('_', '-', $type));
                $plan->layoutCalls[] = ['generates', [$id, 'in' => $in]];
                $plan->attention[] = "Custom type [{$type}] becomes mod's file type [{$id}]; `php artisan mod:{$id}` generates it.";

                continue;
            }

            if ($this->shippedPlacement($base, $kind) !== [$home, $folder]) {
                $plan->layoutCalls[] = ['generates', [$kind, 'in' => $in]];
                $plan->converted[] = "File type [{$kind}] ({$type}): {$in}";
            }
        }

        $this->discovery($plan, $config, $roots);
    }

    /**
     * Where mod's shipped ddd layout places a kind: its root and folder below the domain.
     *
     * @return array{0: string, 1: string}
     */
    private function shippedPlacement(CompiledLayout $base, string $kind): array
    {
        $timestamped = $base->fileType($kind)->isTimestamped();
        $artifact = $base->place($kind, $timestamped ? 'create_probes_table' : 'Probe', PlacementContext::of(['domain' => 'Probe']), $timestamped ? ['timestamp' => '2026_01_01_000000'] : []);
        $directory = dirname($artifact->path());

        foreach ($base->roots() as $name => $root) {
            if ($root->path !== '' && str_starts_with($directory.'/', $root->path.'/Probe/')) {
                return [$name, trim(substr($directory, strlen($root->path.'/Probe')), '/')];
            }

            if ($root->path !== '' && $directory === $root->path.'/Probe') {
                return [$name, ''];
            }
        }

        return ['', $directory];
    }

    /**
     * Discovery as Laravel-DDD did it: switched-off families stay off, and
     * classes it discovered outside the kind folders mod looks in keep being
     * discovered (anywhere below a domain, in the application root, in layers).
     *
     * @param  array<string, array{0: string, 1: string}>  $roots
     */
    private function discovery(ModUpgradePlan $plan, array $config, array $roots): void
    {
        $autoload = (array) ($config['autoload'] ?? []);
        $ignore = array_values(array_map(fn ($path) => trim(str_replace('\\', '/', (string) $path), '/'), (array) ($config['autoload_ignore'] ?? [])));
        $kinds = [];
        $anywhere = [];
        $application = [];
        $layers = [];

        foreach (self::DISCOVERED as $family => $kind) {
            if (($autoload[$family] ?? ($family !== 'listeners')) !== true) {
                if (! is_array($autoload[$family] ?? null)) {
                    $kinds[$kind] = false;
                }

                continue;
            }

            $folder = trim(str_replace('\\', '/', DomainResolver::getRelativeObjectNamespace($kind)), '/');

            foreach ($this->discoveredFiles($family) as $file) {
                if (str_starts_with($file, $roots['domain'][1].'/')) {
                    $below = explode('/', substr($file, strlen($roots['domain'][1]) + 1));

                    if (! str_contains('/'.implode('/', array_slice($below, 0, -1)).'/', '/'.$folder.'/')) {
                        $anywhere[$kind] = true;
                    }
                } elseif (str_starts_with($file, $roots['application'][1].'/')) {
                    $application[$kind] = true;
                } else {
                    foreach ((array) ($config['layers'] ?? []) as $key => $path) {
                        if (is_string($path) && str_starts_with($file, str_replace('\\', '/', $path).'/')) {
                            $layers[$key][$kind] = true;
                        }
                    }
                }
            }
        }

        foreach (array_keys($anywhere) as $kind) {
            $plan->layoutCalls[] = ['generates', [$kind, 'discover' => 'anywhere', 'discoverExcept' => $ignore]];
            $plan->converted[] = "Discovery of [{$kind}] anywhere below a domain, except ".implode(', ', $ignore);
        }

        foreach (array_keys($application) as $kind) {
            $id = 'application-'.$kind;
            $plan->layoutCalls[] = ['generates', [$id, 'in' => 'application:{domain+}/'.$this->folder($kind), 'command' => false, 'discover' => 'anywhere', 'discoverExcept' => $ignore]];
            $kinds[$id] = $kind;
            $plan->converted[] = "Discovery of [{$kind}] in the application root, as file type [{$id}]";
        }

        foreach ((array) ($config['layers'] ?? []) as $key => $path) {
            if (! is_string($key) || ! is_string($path)) {
                continue;
            }

            $path = str_replace('\\', '/', $path);

            if (! is_dir($this->path($path))) {
                $plan->converted[] = "Layer [{$key}] has no folder ({$path}) and is dropped.";

                continue;
            }

            $root = Str::kebab($key);

            if (isset($layers[$key])) {
                $plan->layoutCalls[] = ['mounts', [$root, $this->namespace($key), str_replace('\\', '/', $path)]];

                foreach (array_keys($layers[$key]) as $kind) {
                    $plan->layoutCalls[] = ['generates', [$root.'-'.$kind, 'in' => $root.':'.$this->folder($kind), 'command' => false, 'discover' => 'anywhere', 'discoverExcept' => $ignore]];
                    $kinds[$root.'-'.$kind] = $kind;
                }
            }

            $plan->attention[] = "Layer [{$key}] ({$path}): mod:* commands do not generate into it".(isset($layers[$key]) ? '; its discovered classes stay discovered.' : '.');
        }

        if (($autoload['migrations'] ?? true) === false) {
            $kinds['migration'] = false;
        }

        $plan->discovery = [
            'file_types' => $kinds,
            'factories' => ($autoload['factories'] ?? true) !== false,
            'policies' => ($autoload['policies'] ?? true) !== false,
        ];
    }

    /**
     * Base-relative files of the classes Laravel-DDD discovers for a family.
     *
     * @return list<string>
     */
    private function discoveredFiles(string $family): array
    {
        $discovered = match ($family) {
            'providers' => DDD::autoloader()->discoverProviders(),
            'commands' => DDD::autoloader()->discoverCommands(),
            default => DDD::autoloader()->discoverListeners(),
        };

        $classes = [];
        array_walk_recursive($discovered, function ($value) use (&$classes) {
            if (is_string($value) && class_exists($value)) {
                $classes[$value] = true;
            }
        });

        $files = [];

        foreach (array_keys($classes) as $class) {
            $file = $this->relative((string) (new ReflectionClass($class))->getFileName());

            if (! str_starts_with($file, '/')) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * config/mod.php: mod's shipped file with the ddd layout and the carried-over settings.
     */
    private function config(ModUpgradePlan $plan, array $config): void
    {
        $source = dirname((string) (new ReflectionClass(ModServiceProvider::class))->getFileName(), 2).'/config/mod.php';
        $contents = (string) file_get_contents($source);
        $domain = $this->namespace($config['domain_namespace']);
        $bases = [
            'dto' => $this->base($config['base_dto'] ?? null, ['Spatie\\LaravelData\\Data']),
            'view-model' => $this->base($config['base_view_model'] ?? null, [$domain.'Shared\\ViewModels\\ViewModel']),
            'value-object' => null,
            'action' => $this->base($config['base_action'] ?? null, []),
        ];

        $contents = $this->replaceOnce($contents, "    'layout' => 'laravel',", "    'layout' => 'ddd',");

        foreach ($bases as $kind => $class) {
            $contents = $this->replaceOnce($contents, "        '{$kind}' => null,", "        '{$kind}' => ".ModUpgradePlan::export($class).',');
        }

        $contents = $this->replaceOnce($contents, "        'file_types' => [],", "        'file_types' => ".ModUpgradePlan::export($plan->discovery['file_types']).',');
        $contents = $this->replaceOnce($contents, "        'factories' => true,", "        'factories' => ".ModUpgradePlan::export($plan->discovery['factories']).',');
        $contents = $this->replaceOnce($contents, "        'policies' => true,", "        'policies' => ".ModUpgradePlan::export($plan->discovery['policies']).',');

        $before = is_file($this->app->configPath('mod.php')) ? (string) file_get_contents($this->app->configPath('mod.php')) : null;
        $plan->write($this->relative($this->app->configPath('mod.php')), $before, $contents);
        $plan->converted[] = 'config/mod.php: layout ddd, bases, discovery';

        if (filled($config['base_model'] ?? null)) {
            $plan->attention[] = "ddd.base_model ({$config['base_model']}) has no mod setting: mod:model generates models extending Illuminate\\Database\\Eloquent\\Model; existing models keep their base.";
        }

        if (($config['base_dto'] ?? null) === 'Spatie\\LaravelData\\Data' && ! class_exists('Spatie\\LaravelData\\Data')) {
            $plan->attention[] = 'spatie/laravel-data is not installed: mod:dto writes its own DataTransferObject base instead of extending Spatie\\LaravelData\\Data.';
        }
    }

    private function provider(ModUpgradePlan $plan): void
    {
        if ($plan->layoutCalls === []) {
            return;
        }

        $relative = $this->relative($this->app->path('Providers/ModLayoutServiceProvider.php'));
        $calls = $plan->renderLayoutCalls('            ');
        $contents = <<<PHP
            <?php

            namespace App\\Providers;

            use Illuminate\\Support\\ServiceProvider;
            use Tey\\Mod\\Facades\\Mod;

            /**
             * mod's ddd layout with this application's former Laravel-DDD configuration
             * (written by ddd:upgrade --to=mod).
             */
            class ModLayoutServiceProvider extends ServiceProvider
            {
                public function register(): void
                {
                    Mod::layout('ddd')
            {$calls};
                }
            }

            PHP;

        $plan->write($relative, is_file($this->path($relative)) ? (string) file_get_contents($this->path($relative)) : null, $contents);
        $plan->providers[] = self::PROVIDER;
        $plan->converted[] = 'bootstrap/providers.php: '.self::PROVIDER;
    }

    /**
     * Laravel-DDD classes in application code become Laravel's own.
     */
    private function rewrites(ModUpgradePlan $plan): void
    {
        foreach ($this->codeFiles() as $relative => $contents) {
            if (! str_contains($contents, 'Tey\\LaravelDDD\\')) {
                continue;
            }

            $after = $this->rewrite($contents);

            if ($after !== $contents) {
                $plan->write($relative, $contents, $after);
            }

            if (str_contains($after, 'Tey\\LaravelDDD\\')) {
                $plan->attention[] = "{$relative} still references Tey\\LaravelDDD; replace it by hand.";
            }

            if (str_contains($contents, 'Tey\\LaravelDDD\\Factories\\DomainFactory') && ! preg_match('/\$model\s*=/', $contents)) {
                $plan->attention[] = "{$relative}: mod's factory conventions replace DomainFactory's model lookup; add `protected \$model = ...::class;` if the factory is used on its own.";
            }
        }
    }

    public function rewrite(string $contents): string
    {
        $original = $contents;
        $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $contents = str_replace("\r\n", "\n", $contents);
        $before = $contents;
        $hasFactory = 'use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;';
        $model = 'use Illuminate\\Database\\Eloquent\\Model;';

        // HasDomainFactory, imported under its own name or as HasFactory.
        if (preg_match('/^use Tey\\\\LaravelDDD\\\\Factories\\\\HasDomainFactory( as HasFactory)?;\n/m', $contents)) {
            $already = str_contains($contents, $hasFactory."\n");
            $contents = preg_replace('/^use Tey\\\\LaravelDDD\\\\Factories\\\\HasDomainFactory( as HasFactory)?;\n/m', $already ? '' : $hasFactory."\n", $contents, 1);
            $contents = preg_replace('/\bHasDomainFactory\b/', 'HasFactory', $contents);
        }

        // DomainModel: Model + HasFactory + an open $guarded, as DomainModel was.
        if (preg_match('/^use Tey\\\\LaravelDDD\\\\Models\\\\DomainModel;\n/m', $contents)) {
            $imports = array_filter([$hasFactory, $model], fn ($line) => ! str_contains($contents, $line."\n"));
            $contents = preg_replace('/^use Tey\\\\LaravelDDD\\\\Models\\\\DomainModel;\n/m', implode("\n", $imports).($imports === [] ? '' : "\n"), $contents, 1);
            $contents = preg_replace('/\bextends DomainModel\b/', 'extends Model', $contents);
            $guarded = preg_match('/\$(guarded|fillable)\b/', $contents) ? '' : "    protected \$guarded = [];\n";
            $contents = preg_replace_callback('/(class \w+ extends Model[^{]*\{\n)(\s*\}|)/', function (array $m) use ($guarded) {
                $empty = $m[2] !== '';
                $body = "    use HasFactory;\n".($guarded === '' ? '' : "\n".$guarded);

                return $m[1].$body.($empty ? '}' : "\n");
            }, $contents, 1);
        }

        // DomainFactory: Laravel's Factory.
        if (preg_match('/^use Tey\\\\LaravelDDD\\\\Factories\\\\DomainFactory;\n/m', $contents)) {
            $contents = preg_replace('/^use Tey\\\\LaravelDDD\\\\Factories\\\\DomainFactory;\n/m', "use Illuminate\\Database\\Eloquent\\Factories\\Factory;\n", $contents, 1);
            $contents = preg_replace('/\bextends DomainFactory\b/', 'extends Factory', $contents);
        }

        return $contents === $before ? $original : str_replace("\n", $newline, $contents);
    }

    /**
     * Published Laravel-DDD stubs get mod's names and placeholders.
     */
    private function stubs(ModUpgradePlan $plan): void
    {
        $directory = $this->app->basePath('stubs/ddd');

        if (! is_dir($directory)) {
            return;
        }

        foreach (Finder::create()->files()->in($directory)->depth(0)->sortByName() as $file) {
            $name = str_replace('.php.stub', '.stub', $file->getFilename());
            $target = self::STUBS[$name] ?? null;

            if ($target === null) {
                $plan->attention[] = "stubs/ddd/{$file->getFilename()} has no mod equivalent; mod's own stubs (php artisan stub:publish, stubs/mod.<kind>.stub) apply.";

                continue;
            }

            $contents = strtr($file->getContents(), self::PLACEHOLDERS);
            $plan->write('stubs/'.$target, is_file($this->app->basePath('stubs/'.$target)) ? (string) file_get_contents($this->app->basePath('stubs/'.$target)) : null, $contents);
            $plan->converted[] = "stubs/ddd/{$file->getFilename()} → stubs/{$target}";

            if ($name === 'action.stub' && str_contains($file->getContents(), '{{ extends }}')) {
                $plan->attention[] = "stubs/{$target}: mod's {{ extends }} names the base by its short name and imports it with {{ baseImport }}.";
            }
        }
    }

    private function caches(ModUpgradePlan $plan, array $config): void
    {
        $files = glob($this->app->bootstrapPath('cache/ddd-*.php')) ?: [];
        $directory = $config['cache_directory'] ?? null;

        if (is_string($directory) && $directory !== '' && is_dir($this->path($directory))) {
            $files = [...$files, ...(glob($this->path($directory).'/*.php') ?: [])];
        }

        foreach (array_unique($files) as $file) {
            $plan->deletes[] = $this->relative($file);
        }

        $plan->nextSteps[] = 'php artisan mod:cache (in production, as part of php artisan optimize)';
    }

    /**
     * Application code that may reference Laravel-DDD, base-relative path => contents.
     *
     * @return array<string, string>
     */
    private function codeFiles(): array
    {
        $config = (array) config('ddd', []);
        $directories = [
            $this->app->path(), $this->app->basePath('src'), $this->app->databasePath(), $this->app->basePath('routes'),
            $this->path((string) ($config['domain_path'] ?? 'src/Domain')), $this->path((string) ($config['application_path'] ?? 'app/Modules')),
        ];

        foreach ((array) ($config['layers'] ?? []) as $path) {
            if (is_string($path)) {
                $directories[] = $this->path($path);
            }
        }

        $directories = array_values(array_unique(array_filter(array_map(fn ($directory) => realpath($directory) ?: null, $directories))));
        $files = [];

        if ($directories !== []) {
            foreach (Finder::create()->files()->name('*.php')->in($directories)->sortByName() as $file) {
                $files[$this->relative($file->getRealPath())] = $file->getContents();
            }
        }

        $bootstrap = $this->app->bootstrapPath('app.php');

        if (is_file($bootstrap)) {
            $files[$this->relative($bootstrap)] = (string) file_get_contents($bootstrap);
        }

        ksort($files);

        return $files;
    }

    private function folder(string $type): string
    {
        return trim(str_replace('\\', '/', DomainResolver::getRelativeObjectNamespace($type)), '/');
    }

    private function isApplicationObject(string $type, array $config): bool
    {
        return in_array($type, (array) ($config['application_objects'] ?? []), true);
    }

    private function base(mixed $class, array $defaults): ?string
    {
        if (! is_string($class) || trim($class, '\\ ') === '') {
            return null;
        }

        $class = ltrim(trim($class), '\\');

        return in_array($class, $defaults, true) ? null : $class;
    }

    private function namespace(mixed $value): string
    {
        return trim(str_replace('/', '\\', (string) $value), '\\').'\\';
    }

    private function replaceOnce(string $contents, string $search, string $replace): string
    {
        if (substr_count($contents, $search) !== 1) {
            throw new \RuntimeException("tey/mod's config/mod.php changed: [{$search}] is not there exactly once.");
        }

        return str_replace($search, $replace, $contents);
    }

    private function path(string $relative): string
    {
        return $this->app->basePath(ltrim(str_replace('\\', '/', $relative), '/'));
    }

    private function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        foreach (array_unique([$this->app->basePath(), realpath($this->app->basePath()) ?: $this->app->basePath()]) as $base) {
            $base = rtrim(str_replace('\\', '/', $base), '/').'/';

            if (str_starts_with($path, $base)) {
                return substr($path, strlen($base));
            }
        }

        return $path;
    }
}
