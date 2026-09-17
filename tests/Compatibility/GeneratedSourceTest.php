<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tey\LaravelDDD\Models\DomainModel;

// Framework compatibility contract for generated source (audit F19 #2, #3, #6).
//
// The generators reach into upstream stub text: they replace literal `extends
// Model` lines, literal namespace lines and `{{ factory }}`-style placeholder
// keys, and they rewrite the names of the related classes they forward to. None
// of that is protected by an API. Upstream can reformat a stub, rename a
// placeholder or change what a generated class extends without breaking a single
// Artisan signature, and the generator will keep exiting 0 while writing a file
// that is subtly — or completely — wrong.
//
// The existing generator tests assert that the expected paths exist. These
// assert what is inside them, and that nothing was written outside the domain.

beforeEach(function () {
    $this->cleanSlate();
    $this->setupTestApplication();

    Config::set([
        'ddd.domain_path' => 'src/Domain',
        'ddd.domain_namespace' => 'Domain',
        'ddd.application_path' => 'app/Modules',
        'ddd.application_namespace' => 'App\Modules',
        'ddd.application_objects' => ['controller', 'request'],
        'ddd.base_model' => DomainModel::class,
    ]);
});

/**
 * Every PHP file under the given base-relative directories, keyed by path.
 */
function phpFilesWithin(array $directories): array
{
    $files = [];

    foreach ($directories as $directory) {
        $path = base_path($directory);

        if (! is_dir($path)) {
            continue;
        }

        foreach (File::allFiles($path) as $file) {
            if ($file->getExtension() === 'php') {
                $files[str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())] = true;
            }
        }
    }

    ksort($files);

    return array_keys($files);
}

function assertParses(string $relativePath): void
{
    $process = new Process([PHP_BINARY, '-l', base_path($relativePath)]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue(
        "[{$relativePath}] is not valid PHP: ".trim($process->getOutput().$process->getErrorOutput())
    );
}

it('generates every related object into the domain and nothing outside it', function () {
    // A model plus its whole forwarded family in one run. ForwardsToDomainCommands
    // rewrites the nested make:* calls the framework makes internally; if upstream
    // changes how a child generator is dispatched, the forwarding stops applying
    // and the framework writes the file into the default application locations
    // instead. That shows up here as a stray file, not as a failure.
    $before = phpFilesWithin(['app', 'database', 'src']);

    $this->artisan('ddd:model', [
        'name' => 'Ledger',
        '--domain' => 'Invoicing',
        '--factory' => true,
        '--migration' => true,
        '--policy' => true,
        '--seed' => true,
    ])->assertSuccessful()->execute();

    $created = array_values(array_diff(phpFilesWithin(['app', 'database', 'src']), $before));

    $migration = collect($created)->first(
        fn (string $path) => str_contains($path, 'create_ledgers_table')
    );

    expect($migration)->not->toBeNull('Expecting a migration to be generated');

    sort($created);

    $expected = [
        'src/Domain/Invoicing/Database/Factories/LedgerFactory.php',
        $migration,
        'src/Domain/Invoicing/Database/Seeders/LedgerSeeder.php',
        'src/Domain/Invoicing/Models/Ledger.php',
        'src/Domain/Invoicing/Policies/LedgerPolicy.php',
    ];

    sort($expected);

    // Exact equality, not "contains": a stray app/Models/Ledger.php or
    // database/factories/LedgerFactory.php is precisely the regression this
    // guards, and a containment assertion would not see it.
    expect($created)->toBe(array_map(
        fn (string $path) => str_replace('/', DIRECTORY_SEPARATOR, $path),
        $expected,
    ));

    expect($migration)->toStartWith(str_replace('/', DIRECTORY_SEPARATOR, 'src/Domain/Invoicing/Database/Migrations/'));
});

it('links the generated model, factory and policy to each other', function () {
    $this->artisan('ddd:model', [
        'name' => 'Ledger',
        '--domain' => 'Invoicing',
        '--factory' => true,
        '--policy' => true,
    ])->assertSuccessful()->execute();

    $model = 'src/Domain/Invoicing/Models/Ledger.php';
    $factory = 'src/Domain/Invoicing/Database/Factories/LedgerFactory.php';
    $policy = 'src/Domain/Invoicing/Policies/LedgerPolicy.php';

    foreach ([$model, $factory, $policy] as $path) {
        assertParses($path);
    }

    // The base model swap is a literal `extends Model` / `use ...\Model;` text
    // replacement against the upstream stub.
    expect(file_get_contents(base_path($model)))
        ->toContain('namespace Domain\Invoicing\Models;')
        ->toContain('class Ledger extends DomainModel')
        ->toContain('use '.DomainModel::class.';')
        ->not->toContain('extends Model')
        ->not->toContain('use Illuminate\Database\Eloquent\Model;');

    // The HasFactory generic annotation is built from a placeholder key that
    // upstream owns; an unreplaced placeholder would still be a valid-looking file.
    expect(file_get_contents(base_path($model)))
        ->toContain('use Tey\LaravelDDD\Factories\HasDomainFactory as HasFactory;')
        ->toContain('@use HasFactory<\Domain\Invoicing\Database\Factories\LedgerFactory>')
        ->not->toContain('{{')
        ->not->toContain('}}');

    expect(file_get_contents(base_path($factory)))
        ->toContain('namespace Domain\Invoicing\Database\Factories;')
        ->toContain('use Domain\Invoicing\Models\Ledger;')
        ->toContain('protected $model = Ledger::class;');

    expect(file_get_contents(base_path($policy)))
        ->toContain('namespace Domain\Invoicing\Policies;')
        ->toContain('use Domain\Invoicing\Models\Ledger;')
        ->toContain('Ledger $ledger');
});

it('writes a published stub verbatim instead of rewriting its base class', function () {
    // The base-model swap is a blind string replacement. When a consumer has
    // published their own stub, the generator must not apply it: their stub is
    // the contract, and upstream's `extends Model` text is not in it.
    $customStub = <<<'STUB'
<?php

namespace {{ namespace }};

use Illuminate\Database\Eloquent\Model;

class {{ class }} extends Model
{
    public bool $publishedStub = true;
}
STUB;

    File::ensureDirectoryExists(base_path('stubs/ddd'));
    file_put_contents(base_path('stubs/ddd/model.stub'), $customStub);

    $this->artisan('ddd:model', ['name' => 'Ledger', '--domain' => 'Invoicing'])
        ->assertSuccessful()
        ->execute();

    $model = 'src/Domain/Invoicing/Models/Ledger.php';

    assertParses($model);

    expect(file_get_contents(base_path($model)))
        ->toContain('namespace Domain\Invoicing\Models;')
        ->toContain('class Ledger extends Model')
        ->toContain('use Illuminate\Database\Eloquent\Model;')
        ->toContain('public bool $publishedStub = true;')
        ->not->toContain('extends DomainModel')
        ->not->toContain(DomainModel::class);

    $this->cleanStubs();
});

it('does not leak published-stub state into the next generator run', function () {
    // isUsingPublishedStub() is tracked in a static property, so one generator
    // using a published stub must not change how the next one builds its class.
    File::ensureDirectoryExists(base_path('stubs/ddd'));
    file_put_contents(base_path('stubs/ddd/model.stub'), <<<'STUB'
<?php

namespace {{ namespace }};

class {{ class }}
{
}
STUB);

    $this->artisan('ddd:model', ['name' => 'Published', '--domain' => 'Invoicing'])
        ->assertSuccessful()
        ->execute();

    $this->cleanStubs();

    $this->artisan('ddd:model', ['name' => 'Unpublished', '--domain' => 'Invoicing'])
        ->assertSuccessful()
        ->execute();

    // With no published stub in play, the base-model rewrite has to apply again.
    expect(file_get_contents(base_path('src/Domain/Invoicing/Models/Unpublished.php')))
        ->toContain('class Unpublished extends DomainModel')
        ->toContain('use '.DomainModel::class.';');
});
