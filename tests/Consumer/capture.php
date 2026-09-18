<?php

// A single package implementation per process: PHP cannot unload class definitions.
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Lorisleiva\Lody\LodyServiceProvider;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use Symfony\Component\Process\Process;
use Tey\LaravelDDD\LaravelDDDServiceProvider;
use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Support\GeneratorBlueprint;
use Tey\LaravelDDD\Tests\FixtureApplication;

$composerLoader = require dirname(__DIR__, 2).'/vendor/autoload.php';

$packageRoot = realpath($argv[1] ?? '');
if ($packageRoot === false || ! is_file($packageRoot.'/src/LaravelDDDServiceProvider.php')) {
    throw new RuntimeException('Expected a package source root.');
}

// This loader precedes Composer's optimized classmap as well as its PSR-4 map.
$packageLoader = new ClassLoader;
$packageLoader->setPsr4('Tey\\LaravelDDD\\', [$packageRoot.'/src']);
$packageLoader->register(true);

foreach ([LaravelDDDServiceProvider::class, GeneratorBlueprint::class, AutoloadManager::class] as $class) {
    $path = (new ReflectionClass($class))->getFileName();
    if (! str_starts_with(realpath($path), $packageRoot.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException("The requested package implementation was not loaded: {$class}");
    }
}

// Fixed consumer inputs, shared by the reference and candidate, not their resolvers.
$consumerConfig = [
    'ddd.domain_path' => 'src/Domain',
    'ddd.domain_namespace' => 'Domain',
    'ddd.application_path' => 'src/Application',
    'ddd.application_namespace' => 'Application',
    'ddd.application_objects' => ['controller', 'request'],
    'ddd.layers' => ['Infrastructure' => 'src/Infrastructure'],
    'ddd.autoload' => ['providers' => true, 'commands' => true, 'listeners' => true, 'policies' => false, 'factories' => false, 'migrations' => false],
    'ddd.autoload_ignore' => ['Tests', 'Database/Migrations', 'Ignored'],
    'ddd.base_model' => null,
];

$app = null;
try {
    // Let FixtureApplication snapshot/remap the real Composer loader. Restore
    // package precedence even if opening the application root throws.
    $packageLoader->unregister();
    try {
        $root = FixtureApplication::basePath();
    } finally {
        $packageLoader->register(true);
    }
    // Both the primary fixture resolution and Composer's fallback must stay inside
    // the owned root. A second package loader must not leave vendor mappings live.
    $fixture = dirname(__DIR__).'/.skeleton';
    $fixtureMappings = json_decode(file_get_contents($fixture.'/composer.json'), true, flags: JSON_THROW_ON_ERROR)['autoload']['psr-4'];
    foreach ($fixtureMappings as $prefix => $relative) {
        $expected = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, rtrim($relative, '/'));
        if (($composerLoader->getPrefixesPsr4()[$prefix] ?? []) !== [$expected]) {
            throw new RuntimeException("Composer still has a fixture fallback outside the owned root: {$prefix}");
        }
    }

    $app = TestbenchApplication::create($root, static function ($app) use ($consumerConfig) {
        $app->booting(static fn () => $app['config']->set($consumerConfig));
    }, ['extra' => ['providers' => [LodyServiceProvider::class, LaravelDDDServiceProvider::class]]]);

    $root = base_path();
    $normalize = static fn (string $value): string => str_replace(["\r\n", $root], ["\n", '<APP>'], $value);
    File::copyDirectory($fixture.'/src', base_path('src'));
    File::copy($fixture.'/composer.json', base_path('composer.json'));
    (new Process(['composer', 'dump-autoload', '--no-scripts', '--no-interaction'], $root))->mustRun();
    File::ensureDirectoryExists(app_path('Http/Controllers'));
    File::copy($fixture.'/app/Http/Controllers/Controller.php', app_path('Http/Controllers/Controller.php'));

    foreach ([
        'Domain\\Invoicing\\Models\\Invoice' => 'src/Domain/Invoicing/Models/Invoice.php',
        'Application\\Commands\\ApplicationSync' => 'src/Application/Commands/ApplicationSync.php',
        'Infrastructure\\Providers\\InfrastructureServiceProvider' => 'src/Infrastructure/Providers/InfrastructureServiceProvider.php',
    ] as $class => $relative) {
        if (realpath((new ReflectionClass($class))->getFileName()) !== realpath($root.'/'.$relative)) {
            throw new RuntimeException("Fixture class escaped the owned root: {$class}");
        }
    }

    $commands = [
        ['ddd:class', ['name' => 'Probe', '--domain' => 'Comparison']],
        ['ddd:controller', ['name' => 'Billing/InvoiceController', '--domain' => 'Invoicing', '--model' => 'Invoice', '--requests' => true, '--api' => true]],
        ['ddd:model', ['name' => 'Ledger', '--domain' => 'Comparison', '--factory' => true]],
    ];
    $observations = ['commands' => [], 'files' => [], 'discovery' => []];

    foreach ($commands as [$command, $input]) {
        $definition = Artisan::all()[$command]->getDefinition();
        $options = [];
        foreach ($definition->getOptions() as $option) {
            $options[$option->getName()] = [$option->getShortcut(), $option->isValueRequired(), $option->isValueOptional(), $option->isArray(), $option->getDefault()];
        }
        $arguments = [];
        foreach ($definition->getArguments() as $argument) {
            $arguments[$argument->getName()] = [$argument->isRequired(), $argument->isArray(), $argument->getDefault()];
        }
        $exit = Artisan::call($command, $input);
        $observations['commands'][] = ['name' => $command, 'input' => $input, 'arguments' => $arguments, 'options' => $options, 'exit' => $exit, 'output' => $normalize(Artisan::output())];
    }

    // Record all PHP artifacts under the scenario's generated roots, not paths
    // calculated by the candidate resolver. Existing fixture files are retained
    // too, making accidental overwrite/deletion visible.
    foreach (File::allFiles(base_path('src')) as $file) {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        $observations['files'][$relative] = str_replace("\r\n", "\n", $file->getContents());
    }
    ksort($observations['files']);

    $autoload = app(AutoloadManager::class);
    $observations['discovery'] = [
        'providers' => $autoload->discoverProviders(),
        'commands' => $autoload->discoverCommands(),
        'listeners' => $autoload->discoverListeners(),
    ];

    echo json_encode([
        'provenance' => ['package_root' => $packageRoot, 'php' => PHP_VERSION, 'laravel' => app()->version()],
        'observations' => $observations,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
} finally {
    try {
        $app?->terminate();
    } finally {
        try {
            FixtureApplication::restore();
        } finally {
            $packageLoader->unregister();
        }
    }
}
