<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

afterEach(function () {
    File::deleteDirectory(base_path('stubs/ddd'));
    File::deleteDirectory(base_path('src/Domain/Invoicing'));
});

it('requires the ddd:migration name argument', function () {
    expect(app(Kernel::class)->all()['ddd:migration']->getDefinition()->getArgument('name')->isRequired())->toBeTrue();

    $this->artisan('ddd:migration', ['--domain' => 'Invoicing'])
        ->expectsQuestion('What should the migration be named?', 'create_invoices_table')
        ->assertSuccessful();

    expect(glob(base_path('src/Domain/Invoicing/Database/Migrations/*_create_invoices_table.php')))->toHaveCount(1);
});

it('extends the configured base_dto', function (?string $baseDto, string $expected) {
    Config::set('ddd.base_dto', $baseDto);

    Artisan::call('ddd:dto', ['name' => 'Invoicing:InvoiceData']);

    expect(file_get_contents(base_path('src/Domain/Invoicing/Data/InvoiceData.php')))->toBe($expected);
})->with([
    'default' => ['Spatie\LaravelData\Data', <<<'PHP'
        <?php

        namespace Domain\Invoicing\Data;

        use Spatie\LaravelData\Data;

        class InvoiceData extends Data
        {
            public function __construct()
            {
                //
            }
        }

        PHP],
    'custom' => ['Domain\Shared\Data\BaseData', <<<'PHP'
        <?php

        namespace Domain\Invoicing\Data;

        use Domain\Shared\Data\BaseData;

        class InvoiceData extends BaseData
        {
            public function __construct()
            {
                //
            }
        }

        PHP],
    'none' => [null, <<<'PHP'
        <?php

        namespace Domain\Invoicing\Data;

        class InvoiceData
        {
            public function __construct()
            {
                //
            }
        }

        PHP],
]);

it('fills compact placeholders without leaving braces', function () {
    Config::set('ddd.base_view_model', 'Domain\Shared\ViewModels\ViewModel');
    File::ensureDirectoryExists(base_path('stubs/ddd'));
    File::put(base_path('stubs/ddd/view-model.stub'), "<?php\n\nnamespace {{namespace}};\n\n{{baseClassImport}}\n\nclass {{class}}{{extends}}\n{\n}\n");

    Artisan::call('ddd:view-model', ['name' => 'Invoicing:ShowInvoiceViewModel']);

    expect(file_get_contents(base_path('src/Domain/Invoicing/ViewModels/ShowInvoiceViewModel.php')))
        ->toContain('namespace Domain\Invoicing\ViewModels;')
        ->toContain("\nuse Domain\Shared\ViewModels\ViewModel;\n")
        ->toContain("class ShowInvoiceViewModel extends ViewModel\n");
});

it('generates a view model without a base when base_view_model is null', function () {
    Config::set('ddd.base_view_model', null);

    expect(Artisan::call('ddd:view-model', ['name' => 'Invoicing:ShowInvoiceViewModel']))->toBe(0)
        ->and(file_get_contents(base_path('src/Domain/Invoicing/ViewModels/ShowInvoiceViewModel.php')))
        ->toContain('class ShowInvoiceViewModel'.PHP_EOL);
});
