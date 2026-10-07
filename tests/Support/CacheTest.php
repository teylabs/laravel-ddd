<?php

use Illuminate\Support\Facades\File;
use Tey\LaravelDDD\Support\DomainCache;

beforeEach(function () {
    $cacheDirectory = config('ddd.cache_directory', 'bootstrap/cache/ddd');
    File::delete(glob(base_path("{$cacheDirectory}/ddd-*.php")));
});

it('can cache', function ($key, $value) {
    expect(DomainCache::has($key))->toBeFalse();

    DomainCache::set($key, $value);

    expect(DomainCache::has($key))->toBeTrue();

    expect(DomainCache::get($key))->toEqual($value);
})->with([
    ['value', 'ddd'],
    ['number', 123],
    ['array', [12, 23, 34]],
]);

it('can clear cache', function () {
    DomainCache::set('one', [12, 23, 34]);
    DomainCache::set('two', [45, 56, 67]);
    DomainCache::set('three', [45, 56, 67]);

    expect(DomainCache::has('one'))->toBeTrue();
    expect(DomainCache::has('two'))->toBeTrue();
    expect(DomainCache::has('three'))->toBeTrue();

    DomainCache::clear();

    expect(DomainCache::has('one'))->toBeFalse();
    expect(DomainCache::has('two'))->toBeFalse();
    expect(DomainCache::has('three'))->toBeFalse();
});

describe('with a null cache directory', function () {
    beforeEach(function () {
        config(['ddd.cache_directory' => null]);
    });

    afterEach(function () {
        File::delete(glob(base_path('ddd-*.php')));
        File::delete(glob(base_path('bootstrap/cache/ddd/ddd-*.php')));
    });

    it('uses the default cache directory', function () {
        DomainCache::set('one', [12, 23, 34]);

        expect(file_exists(base_path('bootstrap/cache/ddd/ddd-one.php')))->toBeTrue();
        expect(glob(base_path('ddd-*.php')))->toBeEmpty();

        expect(DomainCache::has('one'))->toBeTrue();
        expect(DomainCache::get('one'))->toEqual([12, 23, 34]);

        DomainCache::forget('one');

        expect(file_exists(base_path('bootstrap/cache/ddd/ddd-one.php')))->toBeFalse();
    });

    it('leaves files in the project root alone when clearing', function () {
        file_put_contents(base_path('ddd-decoy.php'), '<?php return [];');

        DomainCache::set('one', [12, 23, 34]);

        DomainCache::clear();

        expect(DomainCache::has('one'))->toBeFalse();
        expect(file_exists(base_path('ddd-decoy.php')))->toBeTrue();
    });
});
