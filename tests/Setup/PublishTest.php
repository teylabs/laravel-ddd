<?php

use Illuminate\Support\Facades\Artisan;

it('can publish config file', function () {
    $expectedPath = base_path('config/ddd.php');

    if (file_exists($expectedPath)) {
        unlink($expectedPath);
    }

    expect(file_exists($expectedPath))->toBeFalse();

    Artisan::call('vendor:publish', [
        '--tag' => 'ddd-config',
    ]);

    expect(file_exists($expectedPath))->toBeTrue();

    // Delete it
    unlink($expectedPath);
});
