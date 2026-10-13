<?php

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Tey\LaravelDDD\Enums\LayerType;
use Tey\LaravelDDD\Support\Domain;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\LaravelDDD\Support\Layer;
use Tey\LaravelDDD\Support\Layout\DddLayout;
use Tey\LaravelDDD\Support\Layout\DddReverse;
use Tey\LaravelDDD\Support\Layout\InvalidLayoutUse;
use Tey\LaravelDDD\ValueObjects\ObjectSchema;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\TemplateRule;

/*
 * Compile config/ddd.php compiled into one mod layout, and the reverse
 * mapping of existing classes through it.
 */

function placeDdd(string $type, string $domain, string $name): array
{
    $layout = DddLayout::current();
    $parsed = new Domain($domain);
    $layer = DomainResolver::resolveLayer($parsed->domainWithSubdomain, $type);
    $artifact = $layout->compiled()->place(
        $layout->kindIdFor($type, $layer),
        $name,
        $layout->placementFor($parsed, $layer),
    );

    return [$artifact->fqcn(), $artifact->path()];
}

beforeEach(function () {
    // Other tests leave TestCase::$configValues behind; pin the configuration this seam test reads.
    config()->set([
        'ddd.domain_path' => 'src/Domain', 'ddd.domain_namespace' => 'Domain',
        'ddd.application_path' => 'app/Modules', 'ddd.application_namespace' => 'App\Modules',
        'ddd.application_objects' => ['controller', 'request'],
        'ddd.layers' => ['Infrastructure' => 'src/Infrastructure'],
        'ddd.autoload_ignore' => ['Tests', 'Database/Migrations'],
    ]);
    config()->set('ddd.namespaces', array_merge(config('ddd.namespaces', []), ['model' => 'Models', 'policy' => 'Policies', 'factory' => 'Database\Factories']));
    app()->forgetInstance(DddLayout::class);
    app()->forgetInstance(DddReverse::class);
});

it('compiles the default configuration into domain, application and layer roots', function () {
    $compiled = DddLayout::current()->compiled();

    // mod's ddd layout also declares a tests root; no ddd:* command places anything there.
    expect(array_keys($compiled->roots()))->toBe(['domain', 'application', 'tests', 'layer-1'])
        ->and($compiled->roots()['domain']->namespace)->toBe('Domain\\')
        ->and($compiled->roots()['domain']->path)->toBe('src/Domain')
        ->and($compiled->roots()['application']->namespace)->toBe('App\\Modules\\')
        ->and($compiled->roots()['layer-1']->namespace)->toBe('Infrastructure\\')
        ->and($compiled->dimensionNames())->toBe(['domain'])
        ->and($compiled->dimensions()[0]->multi)->toBeTrue()
        ->and($compiled->commandsEnabled())->toBeFalse()
        ->and($compiled->hasFileType('model'))->toBeTrue()
        ->and($compiled->hasFileType('controller'))->toBeTrue()
        ->and($compiled->hasFileType('layer-1-model'))->toBeTrue()
        ->and($compiled->fileType('model')->command)->toBe('ddd:model')
        ->and($compiled->fileType('application-model')->command)->toBeNull()
        ->and($compiled->fileType('layer-1-model')->command)->toBeNull()
        ->and($compiled->fileType('controller')->command)->toBe('ddd:controller')
        ->and($compiled->fileType('domain-controller')->command)->toBeNull()
        ->and($compiled->fileType('domain-root')->command)->toBe('ddd:class')
        ->and($compiled->fileType('class')->command)->toBe('ddd:class')
        ->and($compiled->fileType('dto')->command)->toBe('ddd:dto')
        ->and($compiled->fileType('job-middleware')->command)->toBe('mod:job-middleware')
        ->and($compiled->fileType('domain-subscriber')->command)->toBeNull()
        ->and(DddLayout::current()->commandFor('value_object'))->toBe('ddd:value')
        ->and(DddLayout::current()->commandFor('subscriber'))->toBeNull();
});

it('places every object type where v3 placed it', function (string $type, string $domain, string $name, string $fqcn, string $path) {
    expect(placeDdd($type, $domain, $name))->toBe([$fqcn, $path]);
})->with([
    'model' => ['model', 'Invoicing', 'Invoice', 'Domain\Invoicing\Models\Invoice', 'src/Domain/Invoicing/Models/Invoice.php'],
    'nested model' => ['model', 'Invoicing', 'Archived/Invoice', 'Domain\Invoicing\Models\Archived\Invoice', 'src/Domain/Invoicing/Models/Archived/Invoice.php'],
    'subdomain' => ['model', 'Invoicing.Billing', 'Invoice', 'Domain\Invoicing\Billing\Models\Invoice', 'src/Domain/Invoicing/Billing/Models/Invoice.php'],
    'dto' => ['data_transfer_object', 'Invoicing', 'InvoiceData', 'Domain\Invoicing\Data\InvoiceData', 'src/Domain/Invoicing/Data/InvoiceData.php'],
    'factory folder with backslash' => ['factory', 'Invoicing', 'InvoiceFactory', 'Domain\Invoicing\Database\Factories\InvoiceFactory', 'src/Domain/Invoicing/Database/Factories/InvoiceFactory.php'],
    'controller goes to the application layer' => ['controller', 'Invoicing', 'InvoiceController', 'App\Modules\Invoicing\Controllers\InvoiceController', 'app/Modules/Invoicing/Controllers/InvoiceController.php'],
    'class at the layer root' => ['class', 'Invoicing', 'Probe', 'Domain\Invoicing\Probe', 'src/Domain/Invoicing/Probe.php'],
    'interface shares the root kind' => ['interface', 'Invoicing', 'Payable', 'Domain\Invoicing\Payable', 'src/Domain/Invoicing/Payable.php'],
    'custom layer has no domain folder' => ['model', 'Infrastructure', 'Gateway', 'Infrastructure\Models\Gateway', 'src/Infrastructure/Models/Gateway.php'],
]);

it('gives folder-less types mod\'s kinds below the root kind that reverse mapping finds', function () {
    $layout = DddLayout::current();
    $domain = new Layer('Domain\Invoicing', 'src/Domain/Invoicing', LayerType::Domain);

    expect($layout->kindIdFor('class', $domain))->toBe('class')
        ->and($layout->kindIdFor('trait', $domain))->toBe('trait')
        ->and($layout->rootKindIdFor($domain))->toBe('domain-root')
        ->and($layout->rootKindIdFor(new Layer('App\Modules\Invoicing', 'app/Modules/Invoicing', LayerType::Application)))->toBe('application-root')
        ->and($layout->typeOf('model'))->toBe('model')
        ->and($layout->typeOf('domain-root'))->toBe('class')
        ->and($layout->typeOf('job-middleware'))->toBeNull()
        ->and($layout->rootOf('model'))->toBe('domain')
        ->and($layout->rootOf('application-model'))->toBe('application')
        ->and($layout->rootOf('domain-root'))->toBe('domain')
        // v3: a class at the domain root has no type, though ddd:class put it there.
        ->and(DddReverse::current()->objectFromClass('Domain\Invoicing\Probe')?->type)->toBeNull()
        // mod's inherited job-middleware kind ranks below job.
        ->and(DddReverse::current()->objectFromClass('Domain\Invoicing\Jobs\Middleware\Throttle')?->type)->toBe('job');
});

it('recompiles when the configuration changes and when an unseen type is asked for', function () {
    $layout = DddLayout::current();
    $before = $layout->compiled();

    config()->set('ddd.namespaces.model', 'Entities');
    expect(placeDdd('model', 'Invoicing', 'Invoice')[0])->toBe('Domain\Invoicing\Entities\Invoice')
        ->and($layout->compiled())->not->toBe($before);

    expect($layout->kindIds('lifecycle'))->toBe(['domain-lifecycle', 'application-lifecycle', 'layer-1-lifecycle'])
        ->and(placeDdd('lifecycle', 'Billing', 'ProbeClass')[1])->toBe('src/Domain/Billing/Lifecycles/ProbeClass.php');
});

it('declares the discovered types anywhere below the domain without an except list', function () {
    $compiled = DddLayout::current()->compiled();
    $rule = $compiled->rule('provider');

    expect($rule)->toBeInstanceOf(TemplateRule::class);
    assert($rule instanceof TemplateRule);

    expect($rule->anywhere())->toBeTrue()
        // No except: the discovery candidate source applies autoload_ignore (and the finder hook may replace it).
        ->and($rule->except())->toBe([])
        ->and($rule->recogniseAnywhere($compiled->kind('provider'), 'src/Domain/Billing/Support/OddProvider.php')?->fqcn())->toBe('Domain\Billing\Support\OddProvider')
        ->and($rule->recogniseAnywhere($compiled->kind('provider'), 'src/Domain/Billing/Tests/FakeProvider.php')?->fqcn())->toBe('Domain\Billing\Tests\FakeProvider')
        ->and(DddLayout::current()->kindIds('subscriber'))->toBe(['domain-subscriber', 'application-subscriber', 'layer-1-subscriber']);

    $layerRule = $compiled->rule('layer-1-command');
    assert($layerRule instanceof TemplateRule);
    expect($layerRule->except())->toBe([]);
});

it('lists discovery roots in v3 order and maps the migration directories', function () {
    $layout = DddLayout::current();

    expect(array_map(fn ($root) => $root->path, $layout->discoveryRoots()))->toBe(['src/Domain', 'app/Modules', 'src/Infrastructure']);

    $rule = $layout->compiled()->rule('migration');
    assert($rule instanceof TemplateRule);
    expect($rule->recogniseDirectory('src/Domain/Invoicing/Database/Migrations')?->toArray())->toBe(['domain' => 'Invoicing'])
        ->and($rule->recogniseDirectory('src/Domain/Invoicing/Billing/Database/Migrations')?->toArray())->toBe(['domain' => 'Invoicing/Billing']);
});

it('compiles relations per root with the target root following application_objects', function () {
    $compiled = DddLayout::current()->compiled();

    expect($compiled->relation('model-factory')->toFileType)->toBe('factory')
        ->and($compiled->relation('model-factory')->name?->derive('Invoice', null))->toBe('InvoiceFactory')
        ->and($compiled->relation('model-policy')->name?->derive('Invoice', null))->toBe('InvoicePolicy')
        ->and($compiled->relation('model-seeder')->name?->derive('Invoice', null))->toBe('InvoiceSeeder')
        ->and($compiled->relation('model-controller')->name?->derive('Invoice', null))->toBe('InvoiceController')
        ->and($compiled->relation('model-controller')->toFileType)->toBe('controller')
        ->and($compiled->relation('application-model-factory')->toFileType)->toBe('factory')
        ->and($compiled->relation('controller-store-request')->fromFileType)->toBe('controller')
        ->and($compiled->relation('controller-store-request')->toFileType)->toBe('request')
        ->and($compiled->relation('controller-update-request')->toFileType)->toBe('request')
        ->and($compiled->relation('layer-1-controller-store-request')->toFileType)->toBe('layer-1-request')
        ->and($compiled->relation('factory-model')->mode->value)->toBe('reference')
        // mod's own relations Laravel-DDD does not declare stay off.
        ->and($compiled->relation('listener-event')->mode->value)->toBe('none')
        ->and($compiled->relation('model-store-request')->mode->value)->toBe('none');

    config()->set('ddd.application_objects', ['controller']);
    // request is no longer an application object: its home is the domain root, under mod's id.
    expect(DddLayout::current()->compiled()->relation('controller-store-request')->toFileType)->toBe('request');

    config()->set('ddd.application_objects', []);
    expect(DddLayout::current()->compiled()->relation('controller-store-request')->fromFileType)->toBe('controller')
        ->and(DddLayout::current()->compiled()->fileType('controller')->command)->toBe('ddd:controller')
        ->and(DddLayout::current()->compiled()->fileType('application-controller')->command)->toBeNull();
});

it('leaves out a custom layer that duplicates a root and says so', function () {
    config()->set('ddd.layers', ['Zed' => 'src/Infrastructure', 'DomainAgain' => 'src/Domain', 'Missing' => 'src/Missing']);
    $layout = DddLayout::current();

    expect(array_keys($layout->compiled()->roots()))->toBe(['domain', 'application', 'tests', 'layer-1', 'layer-3'])
        ->and($layout->issues())->toHaveCount(1)
        ->and($layout->issues()[0])->toContain('[DomainAgain]')->toContain('duplicates root [domain]')
        ->and(fn () => $layout->kindIdFor('model', new Layer('DomainAgain', 'src/Domain', LayerType::Custom)))->toThrow(InvalidLayoutUse::class, 'not part of the layout');
});

it('leaves out a root whose namespace is empty and refuses objects that need it with one message', function () {
    config()->set('ddd.domain_namespace', '');
    $layout = DddLayout::current();

    // The application root still works, as in v3.
    expect(array_keys($layout->compiled()->roots()))->toBe(['application', 'layer-1', 'domain-migrations'])
        ->and($layout->compiled()->roots()['domain-migrations']->isClassRoot())->toBeFalse()
        ->and($layout->issues())->toContain('The ddd.domain_namespace configuration is empty; set it to a namespace such as Domain.')
        ->and(placeDdd('controller', 'Billing', 'ProbeController')[0])->toBe('App\Modules\Billing\Controllers\ProbeController')
        ->and(fn () => placeDdd('class', 'Billing', 'ProbeClass'))
        ->toThrow(InvalidLayoutUse::class, 'The ddd.domain_namespace configuration is empty; set it to a namespace such as Domain.');

    config()->set('ddd.domain_namespace', 'Domain');
    config()->set('ddd.application_namespace', '');
    app()->forgetInstance(DddLayout::class);

    expect(fn () => placeDdd('controller', 'Billing', 'ProbeController'))->toThrow(InvalidLayoutUse::class, 'ddd.application_namespace configuration is empty');
});

it('adds discovery-only roots for manual autoload paths that Composer maps', function () {
    $base = app()->basePath();
    @mkdir($base.'/manual-probe/Deep', 0777, true);
    file_put_contents($base.'/manual-probe/Deep/Thing.php', "<?php\nnamespace ManualProbe\\Deep;\nclass Thing {}\n");
    $loader = new ClassLoader($base.'/vendor'); // Composer lists only loaders that know their vendor dir
    $loader->setPsr4('ManualProbe\\', [$base.'/manual-probe']);
    $loader->register();

    try {
        $layout = DddLayout::current();
        $compiled = $layout->withDiscoveryPaths([$base.'/manual-probe/Deep', 'manual-probe/Deep', $base.'/nowhere']);

        expect(array_keys($compiled->roots()))->toContain('path-1')
            ->and($compiled->roots()['path-1']->namespace)->toBe('ManualProbe\Deep\\')
            ->and($compiled->roots()['path-1']->path)->toBe('manual-probe/Deep')
            ->and($compiled->hasFileType('path-1-root'))->toBeTrue()
            ->and($compiled->hasFileType('path-2-root'))->toBeFalse()
            ->and($layout->kindIds('provider'))->toBe(['provider', 'application-provider', 'layer-1-provider', 'path-1-root'])
            ->and($layout->kindIds('subscriber'))->toContain('path-1-root')
            ->and($layout->typeOf('path-1-root'))->toBe('provider')
            ->and($layout->unmappedDiscoveryPaths([$base.'/nowhere', 'manual-probe']))->toBe([$base.'/nowhere']);
    } finally {
        $loader->unregister();
        unlink($base.'/manual-probe/Deep/Thing.php');
        rmdir($base.'/manual-probe/Deep');
        rmdir($base.'/manual-probe');
    }
});

it('turns a schema callback result into the resolved identity verbatim', function () {
    $layout = DddLayout::current();
    $layer = DomainResolver::resolveLayer('Invoicing', 'model');
    $artifact = $layout->artifactFromSchema(new ObjectSchema('Relocated\\Invoice', 'Elsewhere\\Relocated', 'Elsewhere\\Relocated\\Invoice', 'elsewhere/Relocated/Invoice.php'), 'model', $layer);

    expect($artifact->fqcn())->toBe('Elsewhere\Relocated\Invoice')
        ->and($artifact->path())->toBe('elsewhere/Relocated/Invoice.php')
        ->and($artifact->fileType->id)->toBe('model')
        ->and($artifact->context)->toEqual(PlacementContext::none());
});

it('maps classes back to domain objects with the v3 shape', function () {
    $reverse = DddReverse::current();

    $model = $reverse->objectFromClass('Domain\Invoicing\Models\Archived\Invoice');
    expect($model?->name)->toBe('Archived\Invoice')
        ->and($model?->domain)->toBe('Invoicing')
        ->and($model?->namespace)->toBe('Models')
        ->and($model?->type)->toBe('model')
        ->and($model?->path)->toEqualPath('src/Domain/Invoicing/Models/Archived/Invoice.php');

    expect($reverse->objectFromClass('Domain\Invoicing\Billing\Models\Invoice')?->domain)->toBe('Invoicing\Billing')
        ->and($reverse->objectFromClass('Domain\Invoicing\Models\Invoice', 'policy')?->namespace)->toBe('') // v3: typed miss is a root-level object of that type
        ->and($reverse->objectFromClass('Domain\Invoicing\Models\Invoice', 'policy')?->type)->toBe('policy')
        ->and($reverse->objectFromClass('Domain\Invoicing\Support\Helper')?->type)->toBeNull() // v3: no type folder, no type
        ->and($reverse->objectFromClass('Domain\Invoicing\Support\Helper')?->namespace)->toBe('')
        ->and($reverse->objectFromClass('App\Models\User'))->toBeNull()
        ->and($reverse->objectFromClass('Infrastructure\Models\Gateway'))->toBeNull()
        ->and($reverse->objectFromClass('Infrastructure\Models\Gateway', anyRoot: true)?->domain)->toBe('Infrastructure')
        ->and($reverse->objectFromClass('App\Modules\Invoicing\Controllers\InvoiceController', anyRoot: true)?->type)->toBe('controller');
});

it('resolves factories and policies for domain models', function () {
    $reverse = DddReverse::current();

    expect($reverse->policyFor('Domain\Invoicing\Models\Invoice'))->toBe('Domain\Invoicing\Policies\InvoicePolicy')
        ->and($reverse->policyFor('Domain\Invoicing\Models\Archived\Invoice'))->toBe('Domain\Invoicing\Policies\Archived\InvoicePolicy')
        ->and($reverse->policyFor('App\Models\Post'))->toBeNull()
        ->and($reverse->factoryFor('Domain\Invoicing\Models\Ledger'))->toBe('Database\Factories\Invoicing\LedgerFactory')
        ->and($reverse->factoryFor('Domain\Invoicing\Models\Archived\Ledger'))->toBe('Database\Factories\Invoicing\Archived\LedgerFactory')
        ->and($reverse->factoryFor('App\Models\Post'))->toBeNull();

    config()->set('ddd.namespaces.policy', 'Guards');
    expect($reverse->policyFor('Domain\Invoicing\Models\Invoice'))->toBe('Domain\Invoicing\Guards\InvoicePolicy');

    config()->set('ddd.application_objects', ['controller', 'request', 'policy']);
    expect($reverse->policyFor('Domain\Invoicing\Models\Invoice'))->toBe('App\Modules\Invoicing\Guards\InvoicePolicy');
});

it('places every kind it shares with mod\'s ddd layout exactly as mod does with the default configuration', function () {
    // The defaults this guarantee is about: config/ddd.php as shipped, no custom layer.
    config()->set('ddd', require dirname(__DIR__, 2).'/config/ddd.php');
    config()->set('ddd.layers', []);
    $layout = DddLayout::current();
    $ours = $layout->compiled();
    $mod = Layout::fresh(DddLayout::EXTENDS)->compiled();
    $suffixes = ['factory' => 'Factory', 'policy' => 'Policy', 'seeder' => 'Seeder', 'controller' => 'Controller', 'request' => 'Request'];
    $compared = [];

    foreach (DddLayout::TYPES as $type) {
        $kind = $layout->kindIdFor($type, DomainResolver::resolveLayer('Invoicing', $type));

        if (! $mod->hasFileType($kind)) {
            continue; // subscriber: Laravel-DDD's own type
        }

        $name = $kind === 'migration' ? 'create_probes_table' : 'Probe'.($suffixes[$kind] ?? '');
        $context = PlacementContext::of(['domain' => 'Invoicing/Billing']);
        $attributes = $kind === 'migration' ? ['timestamp' => '2026_01_01_000000'] : [];
        $expected = $mod->place($kind, $name, $context, $attributes);
        $actual = $ours->place($kind, $name, $context, $attributes);

        expect([$actual->fqcn(), $actual->path()])->toBe([$expected->fqcn(), $expected->path()], "kind [{$kind}]");
        $compared[] = $kind;
    }

    expect($compared)->toHaveCount(30)
        ->and(array_values(array_diff(array_keys($mod->fileTypes()), $compared)))->toBe(['job-middleware', 'test', 'component']);
});

it('describes a class in a folder another type shares as the requested type, as v3 did', function () {
    config()->set('ddd.namespaces', ['first' => 'Models', 'model' => 'Models']);
    $object = DddReverse::current()->objectFromClass('Domain\Billing\Models\Probe', 'model');

    expect($object?->name)->toBe('Probe')
        ->and($object?->namespace)->toBe('Models')
        ->and($object?->type)->toBe('model')
        ->and(DddLayout::current()->typeOf(DddLayout::current()->kindIdFor('model', DomainResolver::resolveLayer('Billing', 'model'))))->toBe('model');
});

it('keeps inherited components below host object folders', function () {
    config()->set('ddd.namespaces.view_model', 'View\\Components');
    $layout = DddLayout::current();
    $artifact = $layout->compiled()->locate('App\\Modules\\Billing\\View\\Components\\Card');

    expect($artifact?->fileType->id)->toBe('application-view-model')
        ->and($layout->typeOf('component'))->toBeNull();
    expect($layout->compiled()->locate('Domain\\Billing\\View\\Components\\Card')?->fileType->id)->toBe('view-model');
});

it('preserves host reverse mapping when optional MCP generators are installed', function () {
    $original = InstalledVersions::getRawData();
    $withoutMcp = $original;
    unset($withoutMcp['versions']['laravel/mcp']);
    $withMcp = $withoutMcp;
    $withMcp['versions']['laravel/mcp'] = [
        'pretty_version' => 'v1.0.0',
        'version' => '1.0.0.0',
        'aliases' => [],
        'dev_requirement' => false,
    ];

    try {
        InstalledVersions::reload($withoutMcp);
        $before = new DddLayout;
        $beforeReverse = new DddReverse($before);
        $expected = [];

        foreach (['Servers', 'Tools', 'Resources', 'Prompts'] as $folder) {
            $fqcn = "App\\Modules\\Billing\\Mcp\\{$folder}\\Probe";
            $expected[$fqcn] = [
                $before->compiled()->locate($fqcn)?->fileType->id,
                $beforeReverse->objectFromClass($fqcn, anyRoot: true),
            ];
        }

        InstalledVersions::reload($withMcp);
        $after = new DddLayout;
        $afterReverse = new DddReverse($after);

        foreach ($expected as $fqcn => [$owner, $object]) {
            expect($object)->not->toBeNull()
                ->and($after->compiled()->locate($fqcn)?->fileType->id)->toBe($owner)
                ->and($afterReverse->objectFromClass($fqcn, anyRoot: true))->toEqual($object);
        }
    } finally {
        InstalledVersions::reload($original);
    }
});
