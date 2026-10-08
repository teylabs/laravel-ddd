<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tey\LaravelDDD\Commands\Migration\DomainMigrateMakeCommand;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Support\DomainMigration;
use Tey\LaravelDDD\Support\Path;
use Tey\LaravelDDD\Tests\Fixtures\Enums\Feature;

beforeEach(function () {
    config([
        'ddd.autoload.migrations' => true,
    ]);

    DomainCache::clear();
});

it('can generate domain migrations', function ($domainPath, $domainRoot) {
    Config::set('ddd.domain_path', $domainPath);
    Config::set('ddd.domain_namespace', $domainRoot);

    $domain = 'Invoicing';

    $relativePath = implode('/', [
        $domainPath,
        $domain,
        config('ddd.namespaces.migration'),
    ]);

    $migrationFolder = base_path(Path::normalize($relativePath));

    $filesBefore = glob("{$migrationFolder}/*");

    expect(count($filesBefore))->toBe(0);

    Artisan::call("ddd:migration {$domain}:CreateInvoicesTable");

    expect($output = Artisan::output())->when(
        Feature::IncludeFilepathInGeneratorCommandOutput->exists(),
        fn ($output) => $output
            ->toContainFilepath($relativePath)
            ->toContain('_create_invoices_table.php'),
    );

    $filesAfter = glob("{$migrationFolder}/*");

    $createdMigrationFile = Arr::last($filesAfter);

    expect($createdMigrationFile)->toEndWith('_create_invoices_table.php');

    expect(file_get_contents($createdMigrationFile))
        ->toContain('return new class extends Migration');
})->with('domainPaths');

it('discovers domain migration folders', function ($domainPath, $domainRoot) {
    Config::set('ddd.domain_path', $domainPath);
    Config::set('ddd.domain_namespace', $domainRoot);

    $discoveredPaths = DomainMigration::discoverPaths();

    expect($discoveredPaths)->toHaveCount(0);

    Artisan::call('ddd:migration Invoicing:'.uniqid('migration'));
    Artisan::call('ddd:migration Shared:'.uniqid('migration'));
    Artisan::call('ddd:migration Reporting:'.uniqid('migration'));
    Artisan::call('ddd:migration Reporting:'.uniqid('migration'));
    Artisan::call('ddd:migration Reporting:'.uniqid('migration'));

    $discoveredPaths = DomainMigration::discoverPaths();

    expect($discoveredPaths)->toHaveCount(3);

    $expectedFolderPatterns = [
        Path::normalize('Invoicing/Database/Migrations'),
        Path::normalize('Shared/Database/Migrations'),
        Path::normalize('Reporting/Database/Migrations'),
    ];

    foreach ($discoveredPaths as $path) {
        expect(str($path)->contains($expectedFolderPatterns))
            ->toBeTrue('Expecting path to contain one of the expected folder patterns');
    }
})->with('domainPaths');

it('requires a migration name', function () {
    $argument = $this->app->make(DomainMigrateMakeCommand::class)
        ->getDefinition()
        ->getArgument('name');

    expect($argument->isRequired())->toBeTrue();

    $migrationFolder = base_path(Path::normalize('src/Domain/Invoicing/'.config('ddd.namespaces.migration')));

    $this->artisan('ddd:migration', ['--domain' => 'Invoicing'])
        ->expectsQuestion('What should the migration be named?', 'create_probes_table')
        ->assertSuccessful()
        ->execute();

    expect(glob("{$migrationFolder}/*_create_probes_table.php"))->toHaveCount(1)
        ->and(glob("{$migrationFolder}/*_.php"))->toHaveCount(0);
});

it('honours --path and --realpath like make:migration', function (bool $realpath) {
    $relative = 'database/custom-migrations';
    $target = base_path($relative);

    File::deleteDirectory($target);

    $this->artisan('ddd:migration', [
        'name' => 'Invoicing:create_custom_probes_table',
        '--path' => $realpath ? $target : $relative,
        '--realpath' => $realpath,
    ])->assertSuccessful()->execute();

    expect(glob("{$target}/*_create_custom_probes_table.php"))->toHaveCount(1);

    expect(glob(base_path(Path::normalize('src/Domain/Invoicing/'.config('ddd.namespaces.migration'))).'/*_create_custom_probes_table.php'))
        ->toHaveCount(0);

    File::deleteDirectory($target);
})->with([
    'relative path' => [false],
    'real path' => [true],
]);
