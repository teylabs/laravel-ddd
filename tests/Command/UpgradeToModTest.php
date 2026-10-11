<?php

use Illuminate\Support\Facades\File;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\LaravelDDD\Support\Layout\DddLayout;
use Tey\LaravelDDD\Support\Upgrade\ModUpgrade;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Placement\PlacementContext;

/*
 * ddd:upgrade --to=mod: moves an application from Laravel-DDD to plain tey/mod.
 * A dry run by default (the plan and a diff of every file), --force to write.
 */

beforeEach(function () {
    $this->setupTestApplication();
    // The shipped configuration, whatever earlier tests left behind.
    config()->set('ddd', require dirname(__DIR__, 2).'/config/ddd.php');
    $this->composerBefore = file_get_contents(base_path('composer.json'));
    $this->providersBefore = is_file(base_path('bootstrap/providers.php')) ? file_get_contents(base_path('bootstrap/providers.php')) : null;
});

afterEach(function () {
    File::delete(config_path('mod.php'));
    File::delete(app_path('Providers/ModLayoutServiceProvider.php'));
    File::delete(app_path('Providers/DomainHooks.php'));
    file_put_contents(base_path('composer.json'), $this->composerBefore);

    if ($this->providersBefore !== null) {
        file_put_contents(base_path('bootstrap/providers.php'), $this->providersBefore);
    }
});

function publishDddConfig(array $overrides = []): void
{
    $config = array_replace(require dirname(__DIR__, 2).'/config/ddd.php', $overrides);
    file_put_contents(config_path('ddd.php'), '<?php return '.var_export($config, true).';');
    config()->set('ddd', $config);
}

function putAppFile(string $relative, string $contents): string
{
    File::ensureDirectoryExists(dirname(base_path($relative)));
    file_put_contents(base_path($relative), $contents);

    return base_path($relative);
}

it('refuses an unknown target', function () {
    $this->artisan('ddd:upgrade', ['--to' => 'other'])
        ->expectsOutputToContain('Unknown upgrade target [other]; the only target is mod.')
        ->assertExitCode(1);
});

it('shows the plan without writing anything by default', function () {
    $this->artisan('ddd:upgrade', ['--to' => 'mod'])
        ->expectsOutputToContain('Dry run: nothing was written.')
        ->expectsOutputToContain('config/mod.php')
        ->expectsOutputToContain("+    'layout' => 'ddd',")
        ->expectsOutputToContain('composer require tey/mod')
        ->expectsOutputToContain('composer remove tey/laravel-ddd')
        ->assertExitCode(0);

    expect(file_exists(config_path('mod.php')))->toBeFalse();
});

it('writes config/mod.php with the ddd layout for the default configuration', function () {
    File::deleteDirectory(base_path('src/Infrastructure')); // a fresh application has no layer folder yet

    $this->artisan('ddd:upgrade', ['--to' => 'mod', '--force' => true])->assertExitCode(0);

    $config = require config_path('mod.php');

    expect($config['layout'])->toBe('ddd')
        // v3 discovers listeners only when ddd.autoload.listeners is on (off by default); mod discovers them by default.
        ->and($config['discovery']['file_types'])->toBe(['listener' => false])
        ->and($config['discovery']['factories'])->toBeTrue()
        ->and($config['discovery']['policies'])->toBeTrue()
        // The default configuration needs no layout changes: no provider.
        ->and(file_exists(app_path('Providers/ModLayoutServiceProvider.php')))->toBeFalse();
});

it('reports a custom layer without a folder as dropped', function () {
    File::deleteDirectory(base_path('src/Infrastructure'));

    $this->artisan('ddd:upgrade', ['--to' => 'mod'])
        ->expectsOutputToContain('Layer [Infrastructure] has no folder (src/Infrastructure) and is dropped.')
        ->assertExitCode(0);
});

it('extends the ddd layout in a provider for a customised configuration', function () {
    publishDddConfig([
        'domain_path' => 'src/Domains',
        'domain_namespace' => 'Domains',
        'application_objects' => ['controller', 'request'],
        'namespaces' => array_replace(config('ddd.namespaces'), ['data_transfer_object' => 'DataObjects']),
    ]);

    $this->artisan('ddd:upgrade', ['--to' => 'mod', '--force' => true])->assertExitCode(0);

    $provider = file_get_contents(app_path('Providers/ModLayoutServiceProvider.php'));

    expect($provider)
        ->toContain('namespace App\Providers;')
        ->toContain('use Tey\Mod\Facades\Mod;')
        ->toContain("->mounts('domain', 'Domains\\\\', 'src/Domains')")
        ->toContain("->generates('dto', in: 'domain:{domain+}/DataObjects')")
        ->toContain("->generates('middleware', in: 'domain:{domain+}/Middleware')")
        ->and(file_get_contents(base_path('bootstrap/providers.php')))->toContain('App\Providers\ModLayoutServiceProvider::class');
});

it('places every shared file type as Laravel-DDD does once the layout is extended', function (array $overrides) {
    publishDddConfig($overrides);
    $layout = Layout::fresh(DddLayout::EXTENDS);
    ModUpgrade::for(app())->plan()->applyLayout($layout);
    $mod = $layout->compiled();
    $ddd = DddLayout::current();

    foreach (DddLayout::TYPES as $type) {
        $kind = $ddd->kindIdFor($type, DomainResolver::resolveLayer('Invoicing', $type));

        if (! $mod->hasFileType($kind)) {
            continue;
        }

        // After the move, mod's naming applies (mod:controller Probe writes ProbeController); compare with names that already carry it.
        $suffixes = ['factory' => 'Factory', 'policy' => 'Policy', 'seeder' => 'Seeder', 'controller' => 'Controller', 'request' => 'Request'];
        $name = $kind === 'migration' ? 'create_probes_table' : 'Probe'.($suffixes[$kind] ?? '');
        $attributes = $kind === 'migration' ? ['timestamp' => '2026_01_01_000000'] : [];
        $context = PlacementContext::of(['domain' => 'Invoicing/Billing']);
        $expected = $ddd->compiled()->place($kind, $name, $context, $attributes);
        $actual = $mod->place($kind, $name, $context, $attributes);

        expect([$actual->fqcn(), $actual->path()])->toBe([$expected->fqcn(), $expected->path()], "kind [{$kind}]");
    }
})->with([
    'defaults' => [[]],
    'custom domain root' => [['domain_path' => 'src/Domains', 'domain_namespace' => 'Domains']],
    'custom application root' => [['application_path' => 'src/App', 'application_namespace' => 'Application']],
    'fewer application objects' => [['application_objects' => ['controller']]],
    'more application objects' => [['application_objects' => ['controller', 'request', 'middleware', 'policy']]],
    'custom folders' => [['namespaces' => ['model' => 'Entities', 'data_transfer_object' => 'DataObjects', 'factory' => 'Factories']]],
]);

it('keeps discovering the providers and commands of a custom layer', function () {
    File::deleteDirectory(base_path('src/Infrastructure'));
    putAppFile('src/Infrastructure/Providers/UpgradeInfrastructureProvider.php', "<?php\n\nnamespace Infrastructure\\Providers;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass UpgradeInfrastructureProvider extends ServiceProvider\n{\n}\n");

    $this->artisan('ddd:upgrade', ['--to' => 'mod', '--force' => true])
        ->expectsOutputToContain('Layer [Infrastructure] (src/Infrastructure): mod:* commands do not generate into it; its discovered classes stay discovered.')
        ->assertExitCode(0);

    expect(file_get_contents(app_path('Providers/ModLayoutServiceProvider.php')))
        ->toContain("->mounts('infrastructure', 'Infrastructure\\\\', 'src/Infrastructure')")
        ->toContain("->generates('infrastructure-provider', in: 'infrastructure:Providers', command: false, discover: 'anywhere', discoverExcept: ['Tests', 'Database/Migrations'])")
        ->and((require config_path('mod.php'))['discovery']['file_types'])->toBe(['listener' => false, 'infrastructure-provider' => 'provider']);
});

it('keeps discovering a provider Laravel-DDD found outside its folder, minus autoload_ignore', function () {
    File::deleteDirectory(base_path('src')); // only the classes this test writes
    publishDddConfig(['autoload' => ['providers' => true, 'commands' => true, 'listeners' => true, 'policies' => true, 'factories' => true, 'migrations' => true]]);
    putAppFile('src/Domain/Billing/Support/UpgradeBillingProvider.php', "<?php\n\nnamespace Domain\\Billing\\Support;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass UpgradeBillingProvider extends ServiceProvider\n{\n}\n");
    putAppFile('src/Domain/Billing/Providers/UpgradeInvoiceProvider.php', "<?php\n\nnamespace Domain\\Billing\\Providers;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass UpgradeInvoiceProvider extends ServiceProvider\n{\n}\n");

    $this->artisan('ddd:upgrade', ['--to' => 'mod', '--force' => true])->assertExitCode(0);

    $provider = file_get_contents(app_path('Providers/ModLayoutServiceProvider.php'));

    // mod's ddd layout discovers providers in Providers/ only; Laravel-DDD found one in Support/.
    expect($provider)->toContain("->generates('provider', discover: 'anywhere', discoverExcept: ['Tests', 'Database/Migrations'])")
        ->not->toContain("->generates('command', discover:")
        ->not->toContain("->generates('listener', discover:")
        ->and((require config_path('mod.php'))['discovery']['file_types'])->toBe([]);
});

it('switches off the discovery Laravel-DDD had switched off', function () {
    File::deleteDirectory(base_path('src/Infrastructure'));
    publishDddConfig(['autoload' => ['providers' => false, 'commands' => true, 'listeners' => false, 'policies' => false, 'factories' => true, 'migrations' => false]]);

    $this->artisan('ddd:upgrade', ['--to' => 'mod', '--force' => true])->assertExitCode(0);
    $config = require config_path('mod.php');

    expect($config['discovery']['file_types'])->toBe(['provider' => false, 'listener' => false, 'migration' => false])
        ->and($config['discovery']['policies'])->toBeFalse()
        ->and($config['discovery']['factories'])->toBeTrue();
});

it('carries configured bases over to mod.bases', function () {
    publishDddConfig(['base_dto' => 'App\Support\Data', 'base_view_model' => 'Domain\Shared\ViewModels\ViewModel', 'base_action' => 'App\Support\Action']);

    $this->artisan('ddd:upgrade', ['--to' => 'mod', '--force' => true])->assertExitCode(0);
    $config = require config_path('mod.php');

    // The default base view model is the one mod's ddd layout generates itself.
    expect($config['bases'])->toBe(['dto' => 'App\Support\Data', 'view-model' => null, 'value-object' => null, 'action' => 'App\Support\Action']);
});

it('rewrites Laravel-DDD classes in application code to Laravel\'s own', function () {
    $base = putAppFile('src/Domain/Shared/Models/BaseModel.php', <<<'PHP'
        <?php

        namespace Domain\Shared\Models;

        use Tey\LaravelDDD\Factories\HasDomainFactory;
        use Illuminate\Database\Eloquent\Model;

        abstract class BaseModel extends Model
        {
            use HasDomainFactory;

            protected $guarded = [];
        }
        PHP);
    $aliased = putAppFile('src/Domain/Billing/Models/UpgradeInvoice.php', <<<'PHP'
        <?php

        namespace Domain\Billing\Models;

        use Illuminate\Database\Eloquent\Model;
        use Tey\LaravelDDD\Factories\HasDomainFactory as HasFactory;

        class UpgradeInvoice extends Model
        {
            use HasFactory;
        }
        PHP);
    $domainModel = putAppFile('src/Domain/Billing/Models/UpgradePayment.php', <<<'PHP'
        <?php

        namespace Domain\Billing\Models;

        use Tey\LaravelDDD\Models\DomainModel;

        class UpgradePayment extends DomainModel
        {
        }
        PHP);
    $factory = putAppFile('src/Domain/Billing/Database/Factories/UpgradePaymentFactory.php', <<<'PHP'
        <?php

        namespace Domain\Billing\Database\Factories;

        use Tey\LaravelDDD\Factories\DomainFactory;

        class UpgradePaymentFactory extends DomainFactory
        {
            public function definition(): array
            {
                return [];
            }
        }
        PHP);

    $this->artisan('ddd:upgrade', ['--to' => 'mod'])
        ->expectsOutputToContain('-use Tey\LaravelDDD\Factories\HasDomainFactory;')
        ->expectsOutputToContain('+use Illuminate\Database\Eloquent\Factories\HasFactory;')
        ->assertExitCode(0);

    expect(file_get_contents($base))->toContain('use Tey\LaravelDDD\Factories\HasDomainFactory;');

    $this->artisan('ddd:upgrade', ['--to' => 'mod', '--force' => true])->assertExitCode(0);

    expect(file_get_contents($base))
        ->toContain("use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n")
        ->toContain("    use HasFactory;\n")
        ->not->toContain('LaravelDDD')
        ->and(file_get_contents($aliased))
        ->toContain("use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n")
        ->toContain("    use HasFactory;\n")
        ->not->toContain('LaravelDDD')
        ->and(file_get_contents($domainModel))->toBe(<<<'PHP'
            <?php

            namespace Domain\Billing\Models;

            use Illuminate\Database\Eloquent\Factories\HasFactory;
            use Illuminate\Database\Eloquent\Model;

            class UpgradePayment extends Model
            {
                use HasFactory;

                protected $guarded = [];
            }
            PHP)
        ->and(file_get_contents($factory))
        ->toContain("use Illuminate\\Database\\Eloquent\\Factories\\Factory;\n")
        ->toContain('class UpgradePaymentFactory extends Factory')
        ->not->toContain('LaravelDDD');
});

it('keeps a DomainModel subclass\'s own $guarded and $fillable', function () {
    $model = putAppFile('src/Domain/Billing/Models/UpgradePayment.php', <<<'PHP'
        <?php

        namespace Domain\Billing\Models;

        use Tey\LaravelDDD\Models\DomainModel;

        class UpgradePayment extends DomainModel
        {
            protected $fillable = ['amount'];
        }
        PHP);

    $this->artisan('ddd:upgrade', ['--to' => 'mod', '--force' => true])->assertExitCode(0);

    expect(file_get_contents($model))->toContain("protected \$fillable = ['amount'];")->not->toContain('$guarded');
});

it('translates published Laravel-DDD stubs to mod\'s stub names and placeholders', function () {
    putAppFile('stubs/ddd/dto.stub', "<?php\n\nnamespace {{ namespace }};\n{{ baseClassImport }}\nclass {{ class }}{{ extendsBaseClass }}\n{\n}\n");

    $this->artisan('ddd:upgrade', ['--to' => 'mod', '--force' => true])->assertExitCode(0);

    expect(file_get_contents(base_path('stubs/mod.dto.stub')))->toBe("<?php\n\nnamespace {{ namespace }};\n{{ baseImport }}\nclass {{ class }}{{ extends }}\n{\n}\n")
        ->and(file_exists(base_path('stubs/ddd/dto.stub')))->toBeTrue();
});

it('removes the Laravel-DDD discovery caches and says to build mod\'s', function () {
    putAppFile('bootstrap/cache/ddd-discovery.php', '<?php return [];');
    putAppFile('bootstrap/cache/ddd-migration-paths.php', '<?php return [];');

    $this->artisan('ddd:upgrade', ['--to' => 'mod', '--force' => true])
        ->expectsOutputToContain('php artisan mod:cache')
        ->assertExitCode(0);

    expect(file_exists(base_path('bootstrap/cache/ddd-discovery.php')))->toBeFalse()
        ->and(file_exists(base_path('bootstrap/cache/ddd-migration-paths.php')))->toBeFalse();
});

it('refuses to write when something cannot move to mod', function (array $overrides, ?string $file, string $reason) {
    publishDddConfig($overrides);

    if ($file !== null) {
        putAppFile('app/Providers/DomainHooks.php', $file);
    }

    $this->artisan('ddd:upgrade', ['--to' => 'mod'])
        ->expectsOutputToContain($reason)
        ->assertExitCode(0);

    $this->artisan('ddd:upgrade', ['--to' => 'mod', '--force' => true])
        ->expectsOutputToContain('Nothing was written: resolve the blockers above first.')
        ->assertExitCode(1);

    expect(file_exists(config_path('mod.php')))->toBeFalse();
})->with([
    'manual autoload paths' => [['autoload' => ['providers' => ['app/Extra/Providers'], 'commands' => true, 'listeners' => false, 'policies' => true, 'factories' => true, 'migrations' => true]], null, 'ddd.autoload.providers lists paths'],
    'autoload path filter' => [[], "<?php\nDDD::filterAutoloadPathsUsing(fn () => true);\n", 'DDD::filterAutoloadPathsUsing'],
    'empty domain namespace' => [['domain_namespace' => ''], null, 'ddd.domain_namespace is empty'],
]);

it('runs the Composer changes when asked, removing Laravel-DDD last', function () {
    $ran = [];
    app()->instance(ModUpgrade::COMPOSER_RUNNER, function (array $command) use (&$ran) {
        $ran[] = implode(' ', $command);

        return 0;
    });

    $this->artisan('ddd:upgrade', ['--to' => 'mod', '--force' => true, '--composer' => true])->assertExitCode(0);

    expect($ran)->toBe(['composer require tey/mod:^0.5', 'composer remove tey/laravel-ddd']);
});

it('runs no Composer command without --composer', function () {
    $ran = [];
    app()->instance(ModUpgrade::COMPOSER_RUNNER, function (array $command) use (&$ran) {
        $ran[] = $command;

        return 0;
    });

    $this->artisan('ddd:upgrade', ['--to' => 'mod', '--force' => true])->assertExitCode(0);

    expect($ran)->toBe([]);
});
