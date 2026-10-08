<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

it('can generate view models', function ($domainPath, $domainRoot) {
    Config::set('ddd.domain_path', $domainPath);
    Config::set('ddd.domain_namespace', $domainRoot);
    Config::set('ddd.base_view_model', 'Domain\Shared\ViewModels\MyBaseViewModel');

    $viewModelName = Str::studly(fake()->word().'ViewModel');
    $domain = Str::studly(fake()->word());

    $relativePath = implode('/', [
        $domainPath,
        $domain,
        config('ddd.namespaces.view_model'),
        "{$viewModelName}.php",
    ]);

    $expectedPath = base_path($relativePath);

    if (file_exists($expectedPath)) {
        unlink($expectedPath);
    }

    expect(file_exists($expectedPath))->toBeFalse();

    Artisan::call("ddd:view-model {$domain}:{$viewModelName}");

    expect(Artisan::output())->toContainFilepath($relativePath);

    expect(file_exists($expectedPath))->toBeTrue();

    $expectedNamespace = implode('\\', [
        $domainRoot,
        $domain,
        config('ddd.namespaces.view_model'),
    ]);

    $fileContent = file_get_contents($expectedPath);

    expect($fileContent)
        ->toContain(
            "namespace {$expectedNamespace};",
            "use Domain\Shared\ViewModels\MyBaseViewModel;",
            "class {$viewModelName} extends MyBaseViewModel",
        );
})->with('domainPaths');

it('recognizes command aliases', function ($commandName) {
    $this->artisan($commandName, [
        'name' => 'ShowInvoiceViewModel',
        '--domain' => 'Invoicing',
    ])->assertExitCode(0);
})->with([
    'ddd:view-model',
    'ddd:viewmodel',
]);

it('normalizes generated view model to pascal case', function ($given, $normalized) {
    $domain = Str::studly(fake()->word());

    $expectedPath = base_path(implode('/', [
        config('ddd.domain_path'),
        $domain,
        config('ddd.namespaces.view_model'),
        "{$normalized}.php",
    ]));

    Artisan::call("ddd:view-model {$domain}:{$given}");

    expect(file_exists($expectedPath))->toBeTrue();
})->with('makeViewModelInputs');

it('generates the base view model if needed', function ($baseViewModel, $baseViewModelPath) {
    $className = 'ShowInvoiceViewModel';
    $domain = 'Invoicing';

    Config::set('ddd.base_view_model', $baseViewModel);

    $expectedPath = base_path(implode('/', [
        config('ddd.domain_path'),
        $domain,
        config('ddd.namespaces.view_model'),
        "{$className}.php",
    ]));

    if (file_exists($expectedPath)) {
        unlink($expectedPath);
    }

    expect(file_exists($expectedPath))->toBeFalse();

    $expectedBaseViewModelPath = app()->basePath($baseViewModelPath);

    if (file_exists($expectedBaseViewModelPath)) {
        unlink($expectedBaseViewModelPath);
    }

    expect(file_exists($expectedBaseViewModelPath))->toBeFalse();

    Artisan::call("ddd:view-model {$domain}:{$className}");

    $output = Artisan::output();

    expect($output)->toContain("Base view model {$baseViewModel} doesn't exist, generating");

    expect(file_exists($expectedBaseViewModelPath))->toBeTrue();

    // Subsequent calls should not attempt to generate a base view model again
    Artisan::call("ddd:view-model {$domain}:EditInvoiceViewModel");

    expect(Artisan::output())->not->toContain("Base view model {$baseViewModel} doesn't exist, generating");
})->with([
    "Domain\Shared\ViewModels\ViewModel" => ["Domain\Shared\ViewModels\ViewModel", 'src/Domain/Shared/ViewModels/ViewModel.php'],
    "Domain\SomewhereElse\ViewModels\BaseViewModel" => ["Domain\SomewhereElse\ViewModels\BaseViewModel", 'src/Domain/SomewhereElse/ViewModels/BaseViewModel.php'],
]);

it('does not attempt to generate base view models outside the domain layer', function ($baseViewModel) {
    $className = 'ShowInvoiceViewModel';
    $domain = 'Invoicing';

    Config::set('ddd.base_view_model', $baseViewModel);

    $expectedPath = base_path(implode('/', [
        config('ddd.domain_path'),
        $domain,
        config('ddd.namespaces.view_model'),
        "{$className}.php",
    ]));

    if (file_exists($expectedPath)) {
        unlink($expectedPath);
    }

    Artisan::call("ddd:view-model {$domain}:{$className}");

    expect(Artisan::output())->not->toContain("Base view model {$baseViewModel} doesn't exist, generating");
})->with([
    "Vendor\External\ViewModels\ViewModel" => ["Vendor\External\ViewModels\ViewModel"],
    "Illuminate\Support\Str" => ["Illuminate\Support\Str"],
]);

it('generates view models without a base class when ddd.base_view_model is null', function () {
    Config::set('ddd.base_view_model', null);

    $this->artisan('ddd:view-model Invoicing:ShowInvoiceViewModel')
        ->assertSuccessful()
        ->execute();

    $contents = file_get_contents(base_path('src/Domain/Invoicing/ViewModels/ShowInvoiceViewModel.php'));

    expect($contents)
        ->toContain('class ShowInvoiceViewModel')
        ->not->toContain('extends')
        ->not->toContain('use ');
});
