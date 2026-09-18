<?php

use Illuminate\Support\Arr;
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

it('still fills a package-owned map the consumer emptied', function () {
    // An empty array is a list as far as PHP is concerned, so classifying from
    // the consumer's value would call `autoload => []` consumer-owned and leave
    // it empty forever — no newly supported option would ever appear. The
    // decision is made from the package's default instead.
    $latest = require DDD::packagePath('config/ddd.php');

    $config = consumerConfig([
        'autoload' => [],
        'namespaces' => [],
    ])->syncWithLatest()->get();

    expect($config['autoload'])->toBe($latest['autoload'])
        ->and($config['namespaces'])->toBe($latest['namespaces']);
});

it('leaves an emptied consumer-owned collection empty', function () {
    // The counterpart: lists and layers are the consumer's, so emptying them is
    // a decision that survives.
    $config = consumerConfig([
        'autoload_ignore' => [],
        'application_objects' => [],
        'layers' => [],
    ])->syncWithLatest()->get();

    expect($config['autoload_ignore'])->toBe([])
        ->and($config['application_objects'])->toBe([])
        ->and($config['layers'])->toBe([]);
});

it('keeps a package-owned option the consumer replaced with a non-array', function () {
    $config = consumerConfig(['autoload' => false])->syncWithLatest()->get();

    expect($config['autoload'])->toBeFalse();
});

// The merge dispatches through resolve() for scalars and mergeArray() for array
// options. Both were reachable from a subclass before this change, so both are
// still called rather than being bypassed by a private rewrite.

it('lets a subclass transform a scalar option through resolve', function () {
    $manager = new class(config_path('ddd.php')) extends ConfigManager
    {
        public array $resolved = [];

        public function resolve($path, $value)
        {
            $this->resolved[] = implode('.', Arr::wrap($path));

            $resolved = parent::resolve($path, $value);

            return $path === 'domain_path' ? 'src/RewrittenBySubclass' : $resolved;
        }
    };

    $config = $manager->syncWithLatest()->get();

    expect($manager->resolved)->toContain('domain_path')
        ->and($manager->resolved)->toContain('base_model')
        // Scalars nested inside a package-owned map are dispatched too.
        ->and($manager->resolved)->toContain('autoload.providers')
        ->and($manager->resolved)->toContain('namespaces.model')
        // The override actually changes the result, not just observes it.
        ->and($config['domain_path'])->toBe('src/RewrittenBySubclass');
});

it('lets a subclass transform any array option through mergeArray', function () {
    file_put_contents(config_path('ddd.php'), '<?php return '.var_export([
        'application_objects' => ['controller'],
        'autoload_ignore' => ['Tests'],
        'layers' => ['Support' => 'src/Support'],
    ], true).';');

    $manager = new class(config_path('ddd.php')) extends ConfigManager
    {
        public array $merged = [];

        public array $pathTypes = [];

        protected function mergeArray($path, $array)
        {
            $key = implode('.', Arr::wrap($path));

            $this->merged[] = $key;
            $this->pathTypes[$key] = get_debug_type($path);

            $merged = parent::mergeArray($path, $array);

            // Comparing against a bare string also proves the top-level path is
            // still dispatched in its original shape.
            return $path === 'application_objects' ? array_map('strtoupper', $merged) : $merged;
        }
    };

    $config = $manager->syncWithLatest()->get();

    // Every array option goes through the hook, consumer-owned collections
    // included — those used to be resolved wholesale without ever reaching it.
    expect($manager->merged)->toContain('application_objects')
        ->and($manager->merged)->toContain('autoload_ignore')
        ->and($manager->merged)->toContain('layers')
        ->and($manager->merged)->toContain('autoload')
        ->and($manager->merged)->toContain('namespaces')
        ->and($manager->pathTypes['application_objects'])->toBe('string')
        ->and($manager->pathTypes['layers'])->toBe('string')
        // The override reaches the result for a consumer-owned list.
        ->and($config['application_objects'])->toBe(['CONTROLLER'])
        // ...while the options it left alone are untouched.
        ->and($config['autoload_ignore'])->toBe(['Tests'])
        ->and($config['layers'])->toBe(['Support' => 'src/Support']);
});

it('keeps a custom namespace key alongside overridden and default ones', function () {
    // An extra key inside a package-owned map: the package does not define
    // `custom_object`, so filling missing defaults must not drop it.
    $latest = require DDD::packagePath('config/ddd.php');

    $config = consumerConfig([
        'namespaces' => [
            'model' => 'CustomModels',
            'custom_object' => 'CustomObjects',
        ],
    ])->syncWithLatest()->get();

    expect($config['namespaces']['custom_object'])->toBe('CustomObjects')
        ->and($config['namespaces']['model'])->toBe('CustomModels')
        ->and($config['namespaces']['factory'])->toBe($latest['namespaces']['factory'])
        ->and($config['namespaces'])->toHaveKeys(array_keys($latest['namespaces']));
});

it('round trips namespaces and layers containing backslashes', function () {
    // save() swaps backslashes for a placeholder before exporting and restores
    // them afterwards. Values carrying namespace separators are the ones that
    // breaks, so they are written, re-read and synced again here.
    $path = config_path('ddd.php');

    consumerConfig([
        'namespaces' => [
            'model' => 'Custom\Models',
            'custom_object' => 'Custom\Deeply\Nested',
        ],
        'layers' => [
            // A nested layer namespace, so this case carries a backslash on both
            // sides of the map rather than only in the namespaces option.
            'Support\\Internal' => 'src/Support/Internal',
        ],
        'base_model' => 'Domain\Shared\Models\CustomBaseModel',
        'base_action' => null,
        'base_dto' => false,
        'application_objects' => ['keepthis'],
    ])->syncWithLatest()->save();

    $reloaded = include $path;

    expect($reloaded['namespaces']['model'])->toBe('Custom\Models')
        ->and($reloaded['namespaces']['custom_object'])->toBe('Custom\Deeply\Nested')
        ->and($reloaded['layers'])->toBe(['Support\\Internal' => 'src/Support/Internal'])
        ->and($reloaded['base_model'])->toBe('Domain\Shared\Models\CustomBaseModel')
        ->and($reloaded['application_objects'])->toBe(['keepthis'])
        ->and(array_key_exists('base_action', $reloaded))->toBeTrue()
        ->and($reloaded['base_action'])->toBeNull()
        ->and($reloaded['base_dto'])->toBeFalse();

    // Syncing the saved file again must not disturb any of it.
    (new ConfigManager($path))->syncWithLatest()->save();

    expect(include $path)->toBe($reloaded);

    unlink($path);
});
