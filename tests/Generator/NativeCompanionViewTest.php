<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->setupTestApplication();
});

afterEach(fn () => File::delete(resource_path('views/mail/probe.blade.php')));

it('retains native companion view paths and existing-file behaviour', function (string $command, string $option) {
    $path = resource_path('views/mail/probe.blade.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, 'existing view sentinel');

    expect(Artisan::call($command, ['name' => 'Billing:Probe', $option => 'mail.probe', '--force' => true]))->toBe(0)
        ->and(File::isFile($path))->toBeTrue()
        ->and(File::isFile(base_path('src/Domain/Billing/'.($command === 'ddd:mail' ? 'Mail' : 'Notifications').'/Probe.php')))->toBeTrue();

    if ($command === 'ddd:mail') {
        expect(File::get($path))->toBe('existing view sentinel');
    } else {
        expect(File::get($path))->not->toBe('existing view sentinel');
    }
})->with([
    'mail markdown' => ['ddd:mail', '--markdown'],
    'mail view' => ['ddd:mail', '--view'],
    'notification markdown' => ['ddd:notification', '--markdown'],
]);
