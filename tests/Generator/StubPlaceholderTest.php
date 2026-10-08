<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

afterEach(function () {
    File::deleteDirectory(base_path('stubs/ddd'));
});

it('fills compact and spaced placeholders in published stubs', function (string $import, string $extends) {
    Config::set('ddd.base_view_model', 'Domain\Shared\ViewModels\MyBaseViewModel');

    File::ensureDirectoryExists(base_path('stubs/ddd'));

    file_put_contents(base_path('stubs/ddd/view-model.stub'), <<<STUB
<?php

namespace {{ namespace }};

{$import}

class {{ class }}{$extends}
{
}
STUB);

    Artisan::call('ddd:view-model Invoicing:ShowProbeViewModel');

    $contents = file_get_contents(base_path('src/Domain/Invoicing/ViewModels/ShowProbeViewModel.php'));

    expect($contents)
        ->toContain('use Domain\Shared\ViewModels\MyBaseViewModel;')
        ->toContain('class ShowProbeViewModel extends MyBaseViewModel')
        ->not->toContain('{baseClassImport}')
        ->not->toContain('{extends}')
        ->not->toContain('{{');
})->with([
    'compact' => ['{{baseClassImport}}', '{{extends}}'],
    'spaced' => ['{{ baseClassImport }}', '{{ extends }}'],
]);
