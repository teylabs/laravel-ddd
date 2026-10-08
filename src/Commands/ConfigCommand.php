<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tey\LaravelDDD\ComposerManager;
use Tey\LaravelDDD\Facades\DDD;
use Tey\LaravelDDD\Support\Layer;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\form;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\table;

class ConfigCommand extends Command
{
    public $name = 'ddd:config';

    protected $description = 'Configure or modify ddd-related assets and settings';

    protected ComposerManager $composer;

    protected function getArguments()
    {
        return [
            new InputArgument('action', InputArgument::OPTIONAL, 'The action to perform.'),
        ];
    }

    protected function getOptions()
    {
        return [
            new InputOption('layer', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Define a new layer and register it in composer.json'),
        ];
    }

    public function handle(): int
    {
        $action = str($this->argument('action'))->trim()->lower()->toString();

        if (! $action && $this->option('layer')) {
            $action = 'layers';
        }

        return match ($action) {
            'wizard' => $this->wizard(),
            'update' => $this->update(),
            'detect' => $this->detect(),
            'composer' => $this->syncComposer(),
            'layers' => $this->layers(),
            'exit' => $this->exit(),
            default => $this->home(),
        };
    }

    protected function home(): int
    {
        $action = select('Laravel-DDD Config Utility', [
            'wizard' => 'Run the configuration wizard',
            'update' => 'Rebuild and merge ddd.php with latest package version',
            'detect' => 'Detect domain namespace from composer.json',
            'composer' => 'Sync composer.json from ddd.php',
            'exit' => 'Exit',
        ], scroll: 10);

        return match ($action) {
            'wizard' => $this->wizard(),
            'update' => $this->update(),
            'detect' => $this->detect(),
            'composer' => $this->syncComposer(),
            'exit' => $this->exit(),
            default => $this->exit(),
        };
    }

    /**
     * Load composer.json for the actions that read or write it.
     */
    protected function loadComposer(): bool
    {
        if (! file_exists($this->laravel->basePath('composer.json'))) {
            $this->error('composer.json not found.');

            return false;
        }

        $this->composer = DDD::composer()->usingOutput($this->output);

        return true;
    }

    /**
     * The primary directory of a PSR-4 path, which may be a list of directories.
     */
    protected function primaryPath(mixed $path): ?string
    {
        return is_array($path) ? Arr::first($path) : $path;
    }

    protected function layers()
    {
        if (! $this->loadComposer()) {
            return self::FAILURE;
        }

        if ($layers = $this->option('layer')) {
            foreach ($layers as $layer) {
                $parts = explode(':', $layer);

                $this->composer->registerPsr4Autoload(
                    namespace: data_get($parts, 0),
                    path: data_get($parts, 1)
                );
            }

            $this->composer->saveAndReload();
        }

        $this->info('Configuration updated.');

        return self::SUCCESS;
    }

    public static function hasRequiredVersionOfLaravelPrompts(): bool
    {
        return function_exists('\Laravel\Prompts\form');
    }

    protected function wizard(): int
    {
        if (! static::hasRequiredVersionOfLaravelPrompts()) {
            $this->error('This command is not supported with your currently installed version of Laravel Prompts.');

            return self::FAILURE;
        }

        if (! $this->loadComposer()) {
            return self::FAILURE;
        }

        $namespaces = collect($this->composer->getPsr4Namespaces());

        $layers = $namespaces->map(fn ($path, $namespace) => new Layer($namespace, $this->primaryPath($path)));
        $laravelAppLayer = $layers->first(fn (Layer $layer) => str($layer->namespace)->exactly('App'));
        $possibleDomainLayers = $layers->filter(fn (Layer $layer) => str($layer->namespace)->startsWith('Domain'));
        $possibleApplicationLayers = $layers->filter(fn (Layer $layer) => str($layer->namespace)->startsWith('App'));

        $domainLayer = $possibleDomainLayers->first();
        $applicationLayer = $possibleApplicationLayers->first();

        $detected = collect([
            'domain_path' => $domainLayer?->path,
            'domain_namespace' => $domainLayer?->namespace,
            'application_path' => $applicationLayer?->path,
            'application_namespace' => $applicationLayer?->namespace,
        ]);

        $config = $detected->merge(Config::get('ddd'));

        info('Detected DDD configuration:');

        table(
            headers: ['Key', 'Value'],
            rows: $detected->dot()->map(fn ($value, $key) => [$key, $value])->all()
        );

        $choices = [
            'domain_path' => [
                'src/Domain' => 'src/Domain',
                'src/Domains' => 'src/Domains',
                ...[
                    $config->get('domain_path') => $config->get('domain_path'),
                ],
                ...$possibleDomainLayers->mapWithKeys(
                    fn (Layer $layer) => [$layer->path => $layer->path]
                ),
            ],
            'domain_namespace' => [
                'Domain' => 'Domain',
                'Domains' => 'Domains',
                ...[
                    $config->get('domain_namespace') => $config->get('domain_namespace'),
                ],
                ...$possibleDomainLayers->mapWithKeys(
                    fn (Layer $layer) => [$layer->namespace => $layer->namespace]
                ),
            ],
            'application_path' => [
                'app/Modules' => 'app/Modules',
                'src/Modules' => 'src/Modules',
                'Modules' => 'Modules',
                'src/Application' => 'src/Application',
                'Application' => 'Application',
                ...[
                    data_get($config, 'application_path') => data_get($config, 'application_path'),
                ],
                ...$possibleApplicationLayers->mapWithKeys(
                    fn (Layer $layer) => [$layer->path => $layer->path]
                ),
            ],
            'application_namespace' => [
                'App\Modules' => 'App\Modules',
                'Application' => 'Application',
                'Modules' => 'Modules',
                ...[
                    data_get($config, 'application_namespace') => data_get($config, 'application_namespace'),
                ],
                ...$possibleApplicationLayers->mapWithKeys(
                    fn (Layer $layer) => [$layer->namespace => $layer->namespace]
                ),
            ],
            'layers' => [
                'src/Infrastructure' => 'src/Infrastructure',
                'src/Integrations' => 'src/Integrations',
                'src/Support' => 'src/Support',
            ],
        ];

        $form = form()
            ->add(
                function ($responses) use ($choices, $detected, $config) {
                    return suggest(
                        label: 'Domain Path',
                        options: $choices['domain_path'],
                        default: $detected->get('domain_path') ?: $config->get('domain_path'),
                        hint: 'The path to the domain layer relative to the base path.',
                        required: true,
                    );
                },
                name: 'domain_path'
            )
            ->add(
                function ($responses) use ($choices, $config) {
                    return suggest(
                        label: 'Domain Namespace',
                        options: $choices['domain_namespace'],
                        default: class_basename($responses['domain_path']) ?: $config->get('domain_namespace'),
                        required: true,
                        hint: 'The root domain namespace.',
                    );
                },
                name: 'domain_namespace'
            )
            ->add(
                function ($responses) use ($choices) {
                    return suggest(
                        label: 'Path to Application Layer',
                        options: $choices['application_path'],
                        hint: "For objects that don't belong in the domain layer (controllers, form requests, etc.)",
                        placeholder: 'Leave blank to skip and use defaults',
                        scroll: 10,
                    );
                },
                name: 'application_path'
            )
            ->addIf(
                fn ($responses) => filled($responses['application_path']),
                function ($responses) use ($choices, $laravelAppLayer) {
                    $applicationPath = $responses['application_path'];
                    $laravelAppPath = $laravelAppLayer?->path;

                    $namespace = match (true) {
                        $laravelAppLayer && str($applicationPath)->exactly($laravelAppPath) => $laravelAppLayer->namespace,
                        $laravelAppLayer && str($applicationPath)->startsWith("{$laravelAppPath}/") => str($applicationPath)->studly()->toString(),
                        default => str($applicationPath)->classBasename()->studly()->toString(),
                    };

                    return suggest(
                        label: 'Application Layer Namespace',
                        options: $choices['application_namespace'],
                        default: $namespace,
                        hint: 'The root application namespace.',
                        placeholder: 'Leave blank to use defaults',
                    );
                },
                name: 'application_namespace'
            )
            ->add(
                function ($responses) use ($choices) {
                    return multiselect(
                        label: 'Custom Layers (Optional)',
                        options: $choices['layers'],
                        hint: 'Layers can be customized in the ddd.php config file at any time.',
                    );
                },
                name: 'layers'
            );

        $responses = $form->submit();

        $this->info('Building configuration...');

        foreach ($responses as $key => $value) {
            $responses[$key] = $value ?: $config->get($key);
        }

        // The layers prompt answers with paths; config/ddd.php maps namespace => path.
        $responses['layers'] = collect($responses['layers'] ?? [])
            ->mapWithKeys(fn ($path, $namespace) => [
                is_string($namespace) ? $namespace : str($path)->basename()->studly()->toString() => $path,
            ])
            ->all();

        DDD::config()->fill($responses)->save();

        $this->info('Configuration updated: '.config_path('ddd.php'));

        return self::SUCCESS;
    }

    protected function detect(): int
    {
        if (! $this->loadComposer()) {
            return self::FAILURE;
        }

        $search = ['Domain', 'Domains'];

        $detected = [];

        foreach ($search as $namespace) {
            if ($path = $this->primaryPath($this->composer->getAutoloadPath($namespace))) {
                $detected['domain_path'] = $path;
                $detected['domain_namespace'] = $namespace;
                break;
            }
        }

        $this->info('Detected configuration:');

        table(
            headers: ['Config', 'Value'],
            rows: collect($detected)
                ->map(fn ($value, $key) => [$key, $value])
                ->all()
        );

        if (confirm('Update configuration with these values?', true)) {
            DDD::config()->fill($detected)->save();

            $this->info('Configuration updated: '.config_path('ddd.php'));
        }

        return self::SUCCESS;
    }

    protected function update(): int
    {
        $config = DDD::config();

        $confirmed = confirm('Are you sure you want to update ddd.php and merge with latest copy from the package?');

        if (! $confirmed) {
            $this->info('Configuration update aborted.');

            return self::SUCCESS;
        }

        $this->info('Merging ddd.php...');

        $config->syncWithLatest()->save();

        $this->info('Configuration updated: '.config_path('ddd.php'));
        $this->warn('Note: Some values may require manual adjustment.');

        return self::SUCCESS;
    }

    protected function syncComposer(): int
    {
        if (! $this->loadComposer()) {
            return self::FAILURE;
        }

        $namespaces = [
            config('ddd.domain_namespace', 'Domain') => config('ddd.domain_path', 'src/Domain'),
            config('ddd.application_namespace', 'App\\Modules') => config('ddd.application_path', 'app/Modules'),
            ...collect(config('ddd.layers', []))
                ->all(),
        ];

        $this->info('Syncing composer.json from ddd.php...');

        $results = [];

        $added = 0;

        foreach ($namespaces as $namespace => $path) {
            if ($this->composer->hasPsr4Autoload($namespace)) {
                $results[] = [$namespace, $path, 'Already Registered'];

                continue;
            }

            $rootNamespace = Str::before($namespace, '\\');

            if ($this->composer->hasPsr4Autoload($rootNamespace)) {
                $results[] = [$namespace, $path, 'Skipped'];

                continue;
            }

            $this->composer->registerPsr4Autoload($namespace, $path);

            $results[] = [$namespace, $path, 'Added'];

            $added++;
        }

        if ($added > 0) {
            $this->composer->saveAndReload();
        }

        table(
            headers: ['Namespace', 'Path', 'Status'],
            rows: $results
        );

        return self::SUCCESS;
    }

    protected function exit(): int
    {
        $this->info('Goodbye!');

        return self::SUCCESS;
    }
}
