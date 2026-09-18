<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tey\LaravelDDD\ConfigManager;
use Tey\LaravelDDD\Facades\DDD;

beforeEach(function () {
    $this->cleanSlate();

    $this->latestConfig = require DDD::packagePath('config/ddd.php');
});

afterEach(function () {
    $this->setupTestApplication();
    Artisan::call('optimize:clear');
});

it('can update and merge current config file with latest copy from package', function () {
    $path = __DIR__.'/resources/config.sparse.php';

    File::copy($path, config_path('ddd.php'));

    expect(file_exists($path))->toBeTrue();

    $originalContents = file_get_contents($path);

    expect(file_get_contents(config_path('ddd.php')))->toEqual($originalContents);

    $original = include $path;

    $config = DDD::config();

    $config->syncWithLatest()->save();

    $updatedContents = file_get_contents(config_path('ddd.php'));

    expect($updatedContents)->not->toEqual($originalContents);

    $updatedConfig = include config_path('ddd.php');

    // Expect original values to be retained
    foreach ($original as $key => $value) {
        if (is_array($value)) {
            // We won't worry about arrays for now
            continue;
        }

        expect($updatedConfig[$key])->toEqual($value);
    }

    // Expect the updated config to have all top-level keys from the latest config
    expect($updatedConfig)->toHaveKeys(array_keys($this->latestConfig));

    unlink(config_path('ddd.php'));
});

/**
 * Write a consumer's config file and return a manager reading it.
 */
function consumerConfig(array $config): ConfigManager
{
    $path = config_path('ddd.php');

    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, '<?php return '.var_export($config, true).';');

    return new ConfigManager($path);
}

// Syncing brings in whatever the package has added since the config was
// published. What it must not do is edit what the consumer already decided —
// and the sync used to walk the PACKAGE's config, so any key the package did not
// also define was simply dropped, and any list was rebuilt by numeric position.

it('keeps a list the consumer supplied instead of merging defaults into it by position', function () {
    // The consumer kept one entry. Merging by index used to hand back whichever
    // defaults sat at the remaining positions, quietly re-enabling generators
    // they had removed.
    $config = consumerConfig(['application_objects' => ['keepthis']])->syncWithLatest()->get();

    expect($config['application_objects'])->toBe(['keepthis']);
});

it('keeps a list the consumer emptied', function () {
    // An explicitly empty list is a decision, not an absence.
    $config = consumerConfig(['autoload_ignore' => []])->syncWithLatest()->get();

    expect($config['autoload_ignore'])->toBe([]);
});

it('fills in a list the consumer never mentioned', function () {
    $latest = require DDD::packagePath('config/ddd.php');

    $config = consumerConfig(['domain_path' => 'src/Domain'])->syncWithLatest()->get();

    expect($config['autoload_ignore'])->toBe($latest['autoload_ignore'])
        ->and($config['application_objects'])->toBe($latest['application_objects']);
});

it('keeps custom layers rather than replacing them with the package default', function () {
    // layers is documented as the consumer's own "additional top-level
    // namespaces and paths"; Infrastructure ships as an example. Merging
    // defaults in would restore a layer they had deleted.
    $config = consumerConfig(['layers' => ['Support' => 'src/Support']])->syncWithLatest()->get();

    expect($config['layers'])->toBe(['Support' => 'src/Support']);
});

it('adds missing keys to a map the package owns without disturbing the rest', function () {
    // autoload and namespaces are different: the package defines the key set, so
    // a newly supported option has to appear. The consumer's values stay put.
    $latest = require DDD::packagePath('config/ddd.php');

    $config = consumerConfig([
        'autoload' => ['migrations' => false],
        'namespaces' => ['model' => 'CustomModels'],
    ])->syncWithLatest()->get();

    expect($config['autoload']['migrations'])->toBeFalse()
        ->and($config['autoload'])->toHaveKeys(array_keys($latest['autoload']))
        ->and($config['namespaces']['model'])->toBe('CustomModels')
        ->and($config['namespaces'])->toHaveKeys(array_keys($latest['namespaces']));
});

it('preserves explicit null and false values', function () {
    $config = consumerConfig([
        'base_action' => null,
        'base_dto' => false,
        'autoload' => ['providers' => false],
    ])->syncWithLatest()->get();

    expect(array_key_exists('base_action', $config))->toBeTrue()
        ->and($config['base_action'])->toBeNull()
        ->and($config['base_dto'])->toBeFalse()
        ->and($config['autoload']['providers'])->toBeFalse();
});

it('retains an unknown top-level key through the merge', function () {
    // Sync no longer drops a key just because the package does not define it.
    // Rendering it is a separate matter — see the save() test below.
    $config = consumerConfig(['some_extension_key' => ['a' => 1]])->syncWithLatest()->get();

    expect($config['some_extension_key'])->toBe(['a' => 1]);
});

it('survives a save and reload, and a second sync changes nothing', function () {
    $path = config_path('ddd.php');

    consumerConfig([
        'application_objects' => ['keepthis'],
        'autoload_ignore' => [],
        'layers' => ['Support' => 'src/Support'],
        'autoload' => ['migrations' => false],
        'base_action' => null,
    ])->syncWithLatest()->save();

    $reloaded = include $path;

    expect($reloaded['application_objects'])->toBe(['keepthis'])
        ->and($reloaded['autoload_ignore'])->toBe([])
        ->and($reloaded['layers'])->toBe(['Support' => 'src/Support'])
        ->and($reloaded['autoload']['migrations'])->toBeFalse()
        ->and(array_key_exists('base_action', $reloaded))->toBeTrue()
        ->and($reloaded['base_action'])->toBeNull();

    // Running it again on its own output must be a no-op.
    (new ConfigManager($path))->syncWithLatest()->save();

    expect(include $path)->toBe($reloaded);

    unlink($path);
});

it('does not render an unknown top-level key when saving', function () {
    // A BOUNDED LIMIT, asserted so it is visible rather than assumed. save()
    // fills {{key}} placeholders in a fixed stub, and there is no placeholder
    // for a key the package does not know about — so an extension key survives
    // the merge in memory but is not written back. Rendering arbitrary keys
    // would mean reworking how the file is produced, which is deliberately not
    // part of this change.
    $path = config_path('ddd.php');

    consumerConfig(['some_extension_key' => ['a' => 1]])->syncWithLatest()->save();

    expect(array_key_exists('some_extension_key', include $path))->toBeFalse();

    unlink($path);
});
