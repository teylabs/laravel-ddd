<?php

use Illuminate\Support\Facades\File;
use Tey\LaravelDDD\ComposerManager;
use Tey\LaravelDDD\Tests\BootsTestApplication;

uses(BootsTestApplication::class);

beforeEach(function () {
    $this->setupTestApplication();
    $this->originalComposerContents = file_get_contents(base_path('composer.json'));
});

afterEach(function () {
    file_put_contents(base_path('composer.json'), $this->originalComposerContents);
    File::delete(base_path('alternate.json'));
});

function writeComposerJson(string $file, array|string $data): string
{
    file_put_contents($file, is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $file;
}

it('unsets one psr-4 namespace and keeps the rest of autoload', function () {
    $file = writeComposerJson(base_path('alternate.json'), [
        'name' => 'acme/app',
        'autoload' => [
            'psr-4' => ['App\\' => 'app/', 'Domain\\' => 'src/Domain/'],
            'files' => ['helpers.php'],
        ],
    ]);

    $composer = ComposerManager::make($file)->unsetPsr4Autoload('Domain');

    expect($composer->get('autoload'))->toBe([
        'psr-4' => ['App\\' => 'app/'],
        'files' => ['helpers.php'],
    ]);

    expect($composer->unsetPsr4Autoload('Missing')->get('autoload.psr-4'))->toBe(['App\\' => 'app/']);
});

it('saves an emptied json object as {} and keeps lists as lists', function () {
    $file = writeComposerJson(base_path('alternate.json'), <<<'JSON'
{
    "name": "acme/app",
    "require-dev": {},
    "repositories": [],
    "autoload": {
        "psr-4": {
            "Domain\\": "src/Domain/"
        }
    }
}
JSON);

    $composer = ComposerManager::make($file)->unsetPsr4Autoload('Domain');

    expect($composer->toArray())->toBe([
        'name' => 'acme/app',
        'require-dev' => [],
        'repositories' => [],
        'autoload' => ['psr-4' => []],
    ]);

    $composer->save();

    expect(json_decode(file_get_contents($file)))
        ->toEqual(json_decode('{"name":"acme/app","require-dev":{},"repositories":[],"autoload":{"psr-4":{}}}'));
});

it('saves to the composer.json file it read, as Composer writes it', function () {
    $file = writeComposerJson(base_path('alternate.json'), ['name' => 'acme/alternate', 'description' => 'Ünïcode/slashes']);

    ComposerManager::make($file)->fill('version', '1.0.0')->save();

    expect(file_get_contents($file))->toBe(json_encode(
        ['name' => 'acme/alternate', 'description' => 'Ünïcode/slashes', 'version' => '1.0.0'],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ));
    expect(file_get_contents(base_path('composer.json')))->toBe($this->originalComposerContents);
});

it('names the file when composer.json is not a json object', function (string $contents, string $reason) {
    $file = writeComposerJson(base_path('alternate.json'), $contents);

    expect(fn () => ComposerManager::make($file))
        ->toThrow(RuntimeException::class, "{$file} {$reason}");
})->with([
    'syntax error' => ['{"name": ', 'is not valid JSON: Syntax error'],
    'scalar root' => ['"acme/app"', 'is not a JSON object.'],
]);

it('guesses psr-4 paths relative to the application base path', function () {
    $composer = ComposerManager::make(writeComposerJson(base_path('alternate.json'), ['name' => 'acme/app']));
    $cwd = getcwd();

    chdir(sys_get_temp_dir());

    try {
        // Neither src/Lib/Payments nor Lib/Payments exists below the base path: the src/ default.
        expect($composer->registerPsr4Autoload('Lib\\Payments', null)->getAutoloadPath('Lib\\Payments'))->toBe('src/Lib/Payments');

        File::ensureDirectoryExists(base_path('Billing'));
        expect($composer->registerPsr4Autoload('Billing', null)->getAutoloadPath('Billing'))->toBe('Billing');
    } finally {
        chdir($cwd);
        File::deleteDirectory(base_path('Billing'));
    }
});

function writeComposerFixture(string $path, string $json): void
{
    file_put_contents($path, $json);
}

// The 3.2.1 regression tests, on fixture files of their own below the storage path.
describe('3.2.1 composer.json fixes', function () {
    beforeEach(function () {
        $this->directory = storage_path('framework/testing/composer-manager');

        File::ensureDirectoryExists($this->directory);

        $this->composerFile = "{$this->directory}/composer.json";
    });

    afterEach(function () {
        File::deleteDirectory($this->directory);
    });

    it('keeps the rest of autoload when unsetting a psr-4 namespace', function ($namespace) {
        writeComposerFixture($this->composerFile, <<<'JSON'
        {
            "autoload": {
                "psr-4": {
                    "App\\": "app/",
                    "Domain\\": "src/Domain/"
                },
                "classmap": ["database/seeds"],
                "files": ["app/helpers.php"]
            }
        }
        JSON);

        $manager = ComposerManager::make($this->composerFile)->unsetPsr4Autoload($namespace);

        expect($manager->get('autoload'))->toEqual([
            'psr-4' => ['App\\' => 'app/'],
            'classmap' => ['database/seeds'],
            'files' => ['app/helpers.php'],
        ]);
    })->with([
        'without trailing backslash' => ['Domain'],
        'with trailing backslash' => ['Domain\\'],
    ]);

    it('keeps empty objects as objects on save', function () {
        writeComposerFixture($this->composerFile, <<<'JSON'
        {
            "autoload": {
                "psr-4": {}
            },
            "extra": {
                "laravel": {
                    "dont-discover": []
                }
            },
            "config": {},
            "scripts": {}
        }
        JSON);

        ComposerManager::make($this->composerFile)
            ->registerPsr4Autoload('Domain', 'src/Domain')
            ->save();

        $contents = file_get_contents($this->composerFile);

        expect($contents)
            ->toContain('"config": {}')
            ->toContain('"scripts": {}')
            ->toContain('"dont-discover": []');

        $data = json_decode($contents);

        expect($data->config)->toBeInstanceOf(stdClass::class)
            ->and($data->scripts)->toBeInstanceOf(stdClass::class)
            ->and($data->extra->laravel->{'dont-discover'})->toBe([])
            ->and($data->autoload->{'psr-4'}->{'Domain\\'})->toBe('src/Domain');
    });

    it('saves an empty psr-4 object after removing the last namespace', function () {
        writeComposerFixture($this->composerFile, <<<'JSON'
        {
            "autoload": {
                "psr-4": {
                    "Domain\\": "src/Domain/"
                }
            }
        }
        JSON);

        ComposerManager::make($this->composerFile)
            ->unsetPsr4Autoload('Domain')
            ->save();

        $data = json_decode(file_get_contents($this->composerFile));

        expect($data->autoload->{'psr-4'})->toEqual(new stdClass);
    });

    it('saves to the composer file it was made from', function () {
        $baseComposerContents = file_get_contents(base_path('composer.json'));

        $alternateFile = "{$this->directory}/alternate.json";

        writeComposerFixture($alternateFile, <<<'JSON'
        {
            "name": "acme/alternate"
        }
        JSON);

        try {
            ComposerManager::make($alternateFile)
                ->registerPsr4Autoload('Domain', 'src/Domain')
                ->save();

            expect(json_decode(file_get_contents($alternateFile), true))->toEqual([
                'name' => 'acme/alternate',
                'autoload' => ['psr-4' => ['Domain\\' => 'src/Domain']],
            ]);

            expect(file_get_contents(base_path('composer.json')))->toBe($baseComposerContents);
        } finally {
            file_put_contents(base_path('composer.json'), $baseComposerContents);
        }
    });
});

it('rejects a composer.json that is not a JSON object', function (?string $json, string $reason) {
    $file = base_path('alternate.json');
    File::delete($file);

    if ($json !== null) {
        writeComposerFixture($file, $json);
    }

    expect(fn () => ComposerManager::make($file))
        ->toThrow(RuntimeException::class, $file)
        ->toThrow(RuntimeException::class, $reason);
})->with([
    'invalid json' => ['{"autoload": ', 'Syntax error'],
    'a scalar' => ['123', 'not a JSON object'],
    'missing' => [null, 'could not be read'],
]);

it('guesses a root psr-4 path while the working directory is the package', function () {
    $folder = 'ComposerGuessProbe'.getmypid();

    File::ensureDirectoryExists(base_path($folder));

    try {
        expect(getcwd())->not->toBe(base_path());

        $manager = ComposerManager::make(base_path('composer.json'))
            ->registerPsr4Autoload($folder, null);

        expect($manager->getAutoloadPath($folder))->toBe($folder);
    } finally {
        File::deleteDirectory(base_path($folder));
    }
});
