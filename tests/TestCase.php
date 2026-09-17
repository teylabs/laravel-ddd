<?php

namespace Tey\LaravelDDD\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use Symfony\Component\Process\Process;
use Tey\LaravelDDD\LaravelDDDServiceProvider;
use Tey\LaravelDDD\Support\DomainCache;

class TestCase extends Orchestra
{
    public static $configValues = [];

    public $appConfig = [];

    protected $originalComposerContents;

    protected function setUp(): void
    {
        $this->originalComposerContents = $this->getComposerFileContents();

        $this->afterApplicationCreated(function () {
            $this->cleanSlate();

            Factory::guessFactoryNamesUsing(
                fn (string $modelName) => 'Tey\\LaravelDDD\\Database\\Factories\\'.class_basename($modelName).'Factory'
            );

            DomainCache::clear();

            config()->set('data.structure_caching.enabled', false);

            Artisan::command('data:cache-structures', function () {});
        });

        $this->afterApplicationRefreshed(function () {
            config()->set('data.structure_caching.enabled', false);
        });

        $this->beforeApplicationDestroyed(function () {
            $this->cleanSlate();
        });

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $basePath = $this->resolveApplicationBasePath();

        $this->cleanSlate();

        file_put_contents($basePath.'/composer.json', $this->originalComposerContents);

        parent::tearDown();
    }

    public static function configValues(array $values)
    {
        static::$configValues = $values;
    }

    public static function resetConfig()
    {
        static::$configValues = [];
    }

    protected function defineEnvironment($app)
    {
        if (in_array(BootsTestApplication::class, class_uses_recursive($this))) {
            static::$configValues = [
                'ddd.domain_path' => 'src/Domain',
                'ddd.domain_namespace' => 'Domain',
                'ddd.application_path' => 'src/Application',
                'ddd.application_namespace' => 'Application',
                'ddd.application_objects' => [
                    'controller',
                    'request',
                    'middleware',
                ],
                'ddd.layers' => [
                    'Infrastructure' => 'src/Infrastructure',
                ],
                'ddd.autoload' => [
                    'providers' => true,
                    'commands' => true,
                    'policies' => true,
                    'factories' => true,
                    'migrations' => true,
                ],
                'ddd.autoload_ignore' => [
                    'Tests',
                    'Database/Migrations',
                ],
                'ddd.cache_directory' => 'bootstrap/cache/ddd',
                'cache.default' => 'file',
                'data.structure_caching.enabled' => false,
                ...static::$configValues,
            ];
        }

        tap($app['config'], function (Repository $config) {
            foreach (static::$configValues as $key => $value) {
                $config->set($key, $value);
            }

            foreach ($this->appConfig as $key => $value) {
                $config->set($key, $value);
            }

            $config->set('data.structure_caching.enabled', false);
        });
    }

    protected function refreshApplicationWithConfig(array $config)
    {
        $this->appConfig = $config;

        // $this->afterApplicationRefreshed(fn () => $this->appConfig = []);

        $this->reloadApplication();

        $this->appConfig = [];

        return $this;
    }

    protected function withConfig(array $config)
    {
        $this->appConfig = $config;

        return $this;
    }

    /**
     * Point Testbench at the fixture root instead of its own installed skeleton.
     *
     * Everything else follows from this: base_path(), app_path() and the
     * composer dump-autoload subprocess all resolve inside the fixture root, so
     * the suite stops writing into vendor/.
     */
    public static function applicationBasePath()
    {
        return FixtureApplication::basePath();
    }

    protected function getApplicationBasePath()
    {
        return FixtureApplication::basePath();
    }

    protected function resolveApplicationBasePath()
    {
        return $this->getApplicationBasePath();
    }

    protected function getComposerFileContents()
    {
        $basePath = $this->resolveApplicationBasePath();

        return file_get_contents($basePath.'/composer.json');
    }

    protected function getComposerFileAsArray()
    {
        return json_decode($this->getComposerFileContents(), true);
    }

    protected function updateComposerFileFromArray(array $data)
    {
        file_put_contents(base_path('composer.json'), json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $this;
    }

    protected function updateComposer($set = [], $forget = [])
    {
        $data = $this->getComposerFileAsArray();

        foreach ($forget as $key) {
            Arr::forget($data, $key);
        }

        foreach ($set as $pair) {
            [$key, $value] = $pair;
            data_set($data, $key, $value);
        }

        $this->updateComposerFileFromArray($data);

        return $this;
    }

    protected function forgetComposerValues($keys)
    {
        $composerFile = base_path('composer.json');
        $data = json_decode(file_get_contents($composerFile), true);

        foreach ($keys as $key) {
            Arr::forget($data, $key);
        }

        file_put_contents($composerFile, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $this;
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelDDDServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }

    protected function composerReload()
    {
        $command = ['composer', 'dump-autoload'];

        (new Process($command, base_path(), ['COMPOSER_MEMORY_LIMIT' => '-1']))
            ->setTimeout(null)
            ->run(function ($type, $output) {});

        return $this;
    }

    protected function cleanSlate()
    {
        $basePath = $this->resolveApplicationBasePath();

        File::delete($basePath.'/config/ddd.php');

        File::cleanDirectory($basePath.'/app/Models');
        File::cleanDirectory($basePath.'/database/factories');
        File::cleanDirectory($basePath.'/bootstrap/cache');
        File::cleanDirectory($basePath.'/bootstrap/cache/ddd');

        File::deleteDirectory($basePath.'/src');
        File::deleteDirectory($basePath.'/resources/stubs/ddd');
        File::deleteDirectory($basePath.'/stubs');
        File::deleteDirectory($basePath.'/Custom');
        File::deleteDirectory($basePath.'/Other');
        File::deleteDirectory($basePath.'/app/Policies');
        File::deleteDirectory($basePath.'/app/Modules');

        // File::copy(__DIR__.'/.skeleton/composer.json', $basePath.'/composer.json');

        return $this;
    }

    protected function cleanStubs()
    {
        File::cleanDirectory(base_path('stubs'));

        return $this;
    }

    protected function setupTestApplication()
    {
        $this->cleanSlate();

        $basePath = $this->resolveApplicationBasePath();

        File::ensureDirectoryExists(app_path());
        File::ensureDirectoryExists(app_path('Models'));
        File::ensureDirectoryExists(database_path('factories'));
        File::ensureDirectoryExists($basePath.'/bootstrap/cache/ddd');
        File::ensureDirectoryExists(storage_path('framework/cache/data'));

        $skeletonAppFolders = glob(__DIR__.'/.skeleton/app/*', GLOB_ONLYDIR);

        foreach ($skeletonAppFolders as $folder) {
            File::copyDirectory($folder, app_path(basename($folder)));
        }

        File::ensureDirectoryExists(app_path('Http/Controllers'));
        File::copy(__DIR__.'/.skeleton/app/Http/Controllers/Controller.php', app_path('Http/Controllers/Controller.php'));

        File::copyDirectory(__DIR__.'/.skeleton/database', base_path('database'));
        File::copyDirectory(__DIR__.'/.skeleton/src', base_path('src'));
        File::copy(__DIR__.'/.skeleton/bootstrap/providers.php', base_path('bootstrap/providers.php'));
        File::copy(__DIR__.'/.skeleton/config/ddd.php', config_path('ddd.php'));
        File::copy(__DIR__.'/.skeleton/composer.json', $basePath.'/composer.json');

        $this->composerReload();

        // $this->setAutoloadPathInComposer('Domain', 'src/Domain');
        // $this->setAutoloadPathInComposer('Application', 'src/Application');
        // $this->setAutoloadPathInComposer('Infrastructure', 'src/Infrastructure');

        DomainCache::clear();

        config()->set('data.structure_caching.enabled', false);

        return $this;
    }

    protected function setAutoloadPathInComposer($namespace, $path, bool $reload = true)
    {
        $this->updateComposer(
            set: [
                [['autoload', 'psr-4', $namespace.'\\'], $path],
            ],
        );

        if ($reload) {
            $this->composerReload();
        }

        return $this;
    }
}
