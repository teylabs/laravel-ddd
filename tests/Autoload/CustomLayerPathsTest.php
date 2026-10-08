<?php

use Tey\LaravelDDD\Facades\Autoload;
use Tey\LaravelDDD\Support\Path;

it('returns the custom layer paths through the Autoload facade', function () {
    config(['ddd.layers' => ['Infrastructure' => 'src/Infrastructure', 'Support' => 'src/Support']]);

    expect(Autoload::getCustomLayerPaths())->toBe([
        Path::normalize(base_path('src/Infrastructure')),
        Path::normalize(base_path('src/Support')),
    ]);
});
