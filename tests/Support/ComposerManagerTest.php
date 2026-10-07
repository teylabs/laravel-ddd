<?php

use Illuminate\Support\Facades\File;
use Tey\LaravelDDD\ComposerManager;

beforeEach(function () {
    $this->directory = storage_path('framework/testing/composer-manager');

    File::ensureDirectoryExists($this->directory);

    $this->composerFile = "{$this->directory}/composer.json";
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

function writeComposerFixture(string $path, string $json): void
{
    file_put_contents($path, $json);
}

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
