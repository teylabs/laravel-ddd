<?php

use Illuminate\Database\Migrations\MigrationCreator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tey\LaravelDDD\Commands\Migration\DomainMigrateMakeCommand;

beforeEach(function () {
    $this->setupTestApplication();
});

it('previews domain generators and their related files without writing', function (string $command, array $options, array $paths) {
    $exit = Artisan::call($command, ['name' => 'Preview:Probe', '--dry-run' => true, '--json' => true, ...$options]);

    expect($exit)->toBe(0);
    $plan = json_decode($output = Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($plan)->toBeArray();
    foreach ($paths as $path) {
        expect($output)->toContain($path)
            ->and(File::exists(base_path($path)))->toBeFalse();
    }
    expect(File::exists(base_path('src/Domain/Preview')))->toBeFalse();
})->with([
    'class' => ['ddd:class', [], ['src/Domain/Preview/Probe.php']],
    'model with factory' => ['ddd:model', ['--factory' => true], ['src/Domain/Preview/Models/Probe.php', 'src/Domain/Preview/Database/Factories/ProbeFactory.php']],
]);

it('previews a domain migration without writing', function () {
    expect(Artisan::call('ddd:migration', ['name' => 'Preview:create_probes_table', '--dry-run' => true, '--json' => true]))->toBe(0);
    $plan = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($plan)->toBeArray()
        ->and(File::exists(base_path('src/Domain/Preview')))->toBeFalse();
});

it('refuses migration previews that require native creator dispatch without writing', function (bool $customCreator) {
    if ($customCreator) {
        $creator = new class(app('files'), base_path('stubs')) extends MigrationCreator
        {
            public function create($name, $path, $table = null, $create = false)
            {
                throw new RuntimeException('A preview must never invoke the application creator.');
            }
        };
        Artisan::registerCommand(new DomainMigrateMakeCommand($creator, app('composer')));
    }

    $options = $customCreator ? [] : ['--path' => 'database/preview'];
    expect(Artisan::call('ddd:migration', ['name' => 'Preview:create_probes_table', '--dry-run' => true, ...$options]))->toBe(1)
        ->and(Artisan::output())->toContain('Native migration creators and --path/--realpath do not support --dry-run.')
        ->and(File::exists(base_path('database/preview')))->toBeFalse()
        ->and(File::exists(base_path('src/Domain/Preview')))->toBeFalse();
})->with(['native path' => false, 'application creator' => true]);
