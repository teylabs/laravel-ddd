<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tey\LaravelDDD\Support\Domain;
use Tey\LaravelDDD\Tests\Fixtures\Enums\Feature;

beforeEach(function () {
    $this->cleanSlate();
    $this->setupTestApplication();
});

it('can generate domain models', function ($domainPath, $domainRoot) {
    Config::set('ddd.domain_path', $domainPath);
    Config::set('ddd.domain_namespace', $domainRoot);

    $modelName = 'Record';
    $domain = 'World';

    $relativePath = implode('/', [
        $domainPath,
        $domain,
        config('ddd.namespaces.model'),
        "{$modelName}.php",
    ]);

    $expectedModelPath = base_path($relativePath);

    if (file_exists($expectedModelPath)) {
        unlink($expectedModelPath);
    }

    expect(file_exists($expectedModelPath))->toBeFalse();

    Artisan::call("ddd:model {$domain}:{$modelName}");

    expect(Artisan::output())->when(
        Feature::IncludeFilepathInGeneratorCommandOutput->exists(),
        fn ($output) => $output->toContainFilepath($relativePath),
    );

    expect(file_exists($expectedModelPath))->toBeTrue();

    $expectedNamespace = implode('\\', [
        $domainRoot,
        $domain,
        config('ddd.namespaces.model'),
    ]);

    expect(file_get_contents($expectedModelPath))
        ->toContain("namespace {$expectedNamespace};");
})->with('domainPaths');

it('can generate a domain model with factory', function ($domainPath, $domainRoot, $domainName, $subdomain) {
    Config::set('ddd.domain_path', $domainPath);

    $modelName = 'Record';

    $domain = new Domain($domainName, $subdomain);

    $factoryName = "{$modelName}Factory";

    $domainModel = $domain->model($modelName);

    $domainFactory = $domain->factory($factoryName);

    $expectedModelPath = base_path($domainModel->path);

    if (file_exists($expectedModelPath)) {
        unlink($expectedModelPath);
    }

    $expectedFactoryPath = base_path($domainFactory->path);

    if (file_exists($expectedFactoryPath)) {
        unlink($expectedFactoryPath);
    }

    Artisan::call('ddd:model', [
        'name' => $modelName,
        '--domain' => $domain->dotName,
        '--factory' => true,
    ]);

    $output = Artisan::output();

    expect($output)->toContainFilepath($domainModel->path);

    expect(file_exists($expectedModelPath))->toBeTrue("Expecting model file to be generated at {$expectedModelPath}");
    expect(file_exists($expectedFactoryPath))->toBeTrue("Expecting factory file to be generated at {$expectedFactoryPath}");

    expect(file_get_contents($expectedFactoryPath))
        ->toContain("use {$domainModel->fullyQualifiedName};")
        ->toContain("protected \$model = {$modelName}::class;");
})->with('domainPaths')->with('domainSubdomain');

it('normalizes generated model to pascal case', function ($given, $normalized) {
    $domain = 'World';

    $expectedModelPath = base_path(implode('/', [
        config('ddd.domain_path'),
        $domain,
        config('ddd.namespaces.model'),
        "{$normalized}.php",
    ]));

    Artisan::call("ddd:model {$domain}:{$given}");

    expect(file_exists($expectedModelPath))->toBeTrue();
})->with('makeModelInputs');

it('generates the base model when possible', function ($baseModelClass, $baseModelPath) {
    $modelName = 'Record';
    $domain = 'World';

    Config::set('ddd.base_model', $baseModelClass);

    $expectedModelPath = base_path(implode('/', [
        config('ddd.domain_path'),
        $domain,
        config('ddd.namespaces.model'),
        "{$modelName}.php",
    ]));

    $expectedModelClass = implode('\\', [
        basename(config('ddd.domain_path')),
        $domain,
        config('ddd.namespaces.model'),
        $modelName,
    ]);

    if (file_exists($expectedModelPath)) {
        unlink($expectedModelPath);
    }

    expect(file_exists($expectedModelPath))->toBeFalse();

    $expectedBaseModelPath = base_path($baseModelPath);

    if (file_exists($expectedBaseModelPath)) {
        unlink($expectedBaseModelPath);
    }

    expect(class_exists($baseModelClass))->toBeFalse();

    expect(file_exists($expectedBaseModelPath))->toBeFalse("{$baseModelPath} expected not to exist.");

    Artisan::call("ddd:model {$domain}:{$modelName}");

    expect(file_exists($expectedBaseModelPath))->toBeTrue("Expecting base model file to be generated at {$baseModelPath}");

    // Not able to properly assert the following class_exists checks under the testing environment
    // expect(class_exists($expectedModelClass))->toBeTrue("Expecting model class {$expectedModelClass} to exist");
    // expect(class_exists($baseModelClass))->toBeTrue("Expecting base model class {$baseModelClass} to exist");
})->with([
    ['Domain\Shared\Models\CustomBaseModel', 'src/Domain/Shared/Models/CustomBaseModel.php'],
    ['Domain\Core\Models\CustomBaseModel', 'src/Domain/Core/Models/CustomBaseModel.php'],
    ['Domain\Core\BaseModels\CustomBaseModel', 'src/Domain/Core/BaseModels/CustomBaseModel.php'],
]);

it('will not generate a base model if the configured base model is out of scope', function ($baseModel) {
    Config::set('ddd.base_model', $baseModel);

    expect(class_exists($baseModel))->toBeFalse();

    Artisan::call('ddd:model Fruits:Lemon');

    expect(Artisan::output())->not->toContain("Configured base model {$baseModel} doesn't exist, generating...");

    expect(class_exists($baseModel))->toBeFalse();
})->with([
    ['Illuminate\Database\Eloquent\NonExistentModel'],
    ['OtherVendor\OtherPackage\Models\NonExistentModel'],
]);

it('skips base model creation if configured base model already exists', function ($baseModel) {
    Config::set('ddd.base_model', $baseModel);

    expect(class_exists($baseModel))->toBeTrue();

    Artisan::call('ddd:model Fruits:Lemon');

    expect(Artisan::output())->not->toContain("Configured base model {$baseModel} doesn't exist, generating...");
})->with([
    ['Illuminate\Database\Eloquent\Model'],
    ['Tey\LaravelDDD\Models\DomainModel'],
]);

it('extends custom base models when applicable', function ($baseModelClass, $baseModelName) {
    Config::set('ddd.base_model', $baseModelClass);

    $domain = 'Fruits';
    $modelName = 'Lemon';

    $expectedModelPath = base_path(implode('/', [
        config('ddd.domain_path'),
        $domain,
        config('ddd.namespaces.model'),
        "{$modelName}.php",
    ]));

    if (file_exists($expectedModelPath)) {
        unlink($expectedModelPath);
    }

    Artisan::call("ddd:model {$domain}:{$modelName}");

    expect(file_exists($expectedModelPath))->toBeTrue();

    expect(file_get_contents($expectedModelPath))
        ->toContain("use {$baseModelClass};")
        ->toContain("extends {$baseModelName}");
})->with([
    ['Domain\Shared\Models\BaseModel', 'BaseModel'],
    ['Tey\LaravelDDD\Models\DomainModel', 'DomainModel'],
    ['Illuminate\Database\Eloquent\NonExistentModel', 'NonExistentModel'],
    ['OtherVendor\OtherPackage\Models\NonExistentModel', 'NonExistentModel'],
]);

it('does not attempt to extend custom base models when using custom stubs', function ($baseModelClass, $baseModelName, $stubFolder) {
    Config::set('ddd.base_model', $baseModelClass);

    $domain = 'Fruits';
    $modelName = 'Lemon';

    $expectedModelPath = base_path(implode('/', [
        config('ddd.domain_path'),
        $domain,
        config('ddd.namespaces.model'),
        "{$modelName}.php",
    ]));

    if (file_exists($expectedModelPath)) {
        unlink($expectedModelPath);
    }

    // Publish a custom stub
    $customStub = <<<'STUB'
<?php

namespace {{ namespace }};

class {{ class }}
{
    use CustomModelTrait;
}
STUB;

    File::ensureDirectoryExists(app()->basePath($stubFolder));
    file_put_contents(app()->basePath($stubFolder.'/model.stub'), $customStub);
    expect(file_exists(app()->basePath($stubFolder.'/model.stub')))->toBeTrue();

    Artisan::call("ddd:model {$domain}:{$modelName}");

    expect(file_exists($expectedModelPath))->toBeTrue();

    expect(file_get_contents($expectedModelPath))
        ->toContain('use CustomModelTrait;')
        ->not->toContain("use {$baseModelClass};")
        ->not->toContain("extends {$baseModelName}");

    $this->cleanStubs();
})->with([
    ['Domain\Shared\Models\BaseModel', 'BaseModel'],
])->with([
    'stubs',
    'stubs/ddd',
]);

it('references the domain factory when generating everything with --all', function () {
    $domain = new Domain('Billing');

    $model = $domain->model('Receipt');
    $factory = $domain->factory('ReceiptFactory');

    Artisan::call('ddd:model', [
        'name' => 'Receipt',
        '--domain' => 'Billing',
        '--all' => true,
    ]);

    expect(file_exists(base_path($factory->path)))->toBeTrue();

    expect(file_get_contents(base_path($model->path)))
        ->toContain('use Tey\LaravelDDD\Factories\HasDomainFactory as HasFactory;')
        ->toContain("/** @use HasFactory<\\{$factory->fullyQualifiedName}> */")
        ->not->toContain('HasFactory<\Database\Factories');
});
