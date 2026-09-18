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

require dirname(__DIR__, 2).'/vendor/autoload.php';

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

$root = FixtureApplication::basePath();
$app = TestbenchApplication::create($root, static function ($app) use ($consumerConfig) {
    $app->booting(static fn () => $app['config']->set($consumerConfig));
}, ['extra' => ['providers' => [LodyServiceProvider::class, LaravelDDDServiceProvider::class]]]);

try {
    $root = base_path();
    $normalize = static fn (string $value): string => str_replace(["\r\n", $root], ["\n", '<APP>'], $value);
    $fixture = dirname(__DIR__).'/.skeleton';
    File::copyDirectory($fixture.'/src', base_path('src'));
    File::copy($fixture.'/composer.json', base_path('composer.json'));
    (new Process(['composer', 'dump-autoload', '--no-scripts', '--no-interaction'], $root))->mustRun();
    File::ensureDirectoryExists(app_path('Http/Controllers'));
    File::copy($fixture.'/app/Http/Controllers/Controller.php', app_path('Http/Controllers/Controller.php'));

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
    $app->terminate();
    FixtureApplication::restore();
}
