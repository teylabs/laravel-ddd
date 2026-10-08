<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tey\LaravelDDD\Tests\Fixtures\Enums\Feature;

it('can generate data transfer objects', function ($domainPath, $domainRoot) {
    Config::set('ddd.domain_path', $domainPath);
    Config::set('ddd.domain_namespace', $domainRoot);

    $dtoName = Str::studly(fake()->word());
    $domain = Str::studly(fake()->word());

    $relativePath = implode('/', [
        $domainPath,
        $domain,
        config('ddd.namespaces.data_transfer_object'),
        "{$dtoName}.php",
    ]);

    $expectedPath = base_path($relativePath);

    if (file_exists($expectedPath)) {
        unlink($expectedPath);
    }

    expect(file_exists($expectedPath))->toBeFalse();

    Artisan::call("ddd:dto {$domain}:{$dtoName}");

    expect(Artisan::output())->when(
        Feature::IncludeFilepathInGeneratorCommandOutput->exists(),
        fn ($output) => $output->toContainFilepath($relativePath),
    );

    expect(file_exists($expectedPath))->toBeTrue();

    $expectedNamespace = implode('\\', [
        $domainRoot,
        $domain,
        config('ddd.namespaces.data_transfer_object'),
    ]);

    expect(file_get_contents($expectedPath))->toContain("namespace {$expectedNamespace};");
})->with('domainPaths');

it('recognizes command aliases', function ($commandName) {
    $this->artisan($commandName, [
        'name' => 'InvoicePayload',
        '--domain' => 'Invoicing',
    ])->assertExitCode(0);
})->with([
    'ddd:dto',
    'ddd:data-transfer-object',
    'ddd:datatransferobject',
    'ddd:data',
]);

it('normalizes generated data transfer object to pascal case', function ($given, $normalized) {
    $domain = Str::studly(fake()->word());

    $expectedPath = base_path(implode('/', [
        config('ddd.domain_path'),
        $domain,
        config('ddd.namespaces.data_transfer_object'),
        "{$normalized}.php",
    ]));

    Artisan::call("ddd:dto {$domain}:{$given}");

    expect(file_exists($expectedPath))->toBeTrue();
})->with('makeDtoInputs');

it('extends the configured base data transfer object', function (?string $baseDto, string $expected) {
    Config::set('ddd.base_dto', $baseDto);

    Artisan::call('ddd:dto Invoicing:InvoicePayload');

    $path = base_path(implode('/', [
        config('ddd.domain_path'),
        'Invoicing',
        config('ddd.namespaces.data_transfer_object'),
        'InvoicePayload.php',
    ]));

    $namespace = implode('\\', [
        config('ddd.domain_namespace'),
        'Invoicing',
        config('ddd.namespaces.data_transfer_object'),
    ]);

    expect(str_replace("\r\n", "\n", file_get_contents($path)))->toBe(str_replace('{{ namespace }}', $namespace, $expected));
})->with([
    'default' => ['Spatie\LaravelData\Data', <<<'PHP'
<?php

namespace {{ namespace }};

use Spatie\LaravelData\Data;

class InvoicePayload extends Data
{
    public function __construct()
    {
        //
    }
}

PHP],
    'custom' => ['Domain\Shared\Data\BaseData', <<<'PHP'
<?php

namespace {{ namespace }};

use Domain\Shared\Data\BaseData;

class InvoicePayload extends BaseData
{
    public function __construct()
    {
        //
    }
}

PHP],
    'none' => [null, <<<'PHP'
<?php

namespace {{ namespace }};

class InvoicePayload
{
    public function __construct()
    {
        //
    }
}

PHP],
]);
