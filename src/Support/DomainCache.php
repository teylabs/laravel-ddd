<?php

namespace Tey\LaravelDDD\Support;

use Illuminate\Support\Facades\File;

class DomainCache
{
    public static function set($key, $value)
    {
        $cacheDirectory = static::directory();

        File::ensureDirectoryExists(base_path($cacheDirectory));

        $cacheFilePath = base_path("{$cacheDirectory}/ddd-{$key}.php");

        file_put_contents(
            $cacheFilePath,
            '<?php '.PHP_EOL.'return '.var_export($value, true).';'
        );

        return $value;
    }

    public static function get($key)
    {
        $cacheDirectory = static::directory();

        $cacheFilePath = base_path("{$cacheDirectory}/ddd-{$key}.php");

        return file_exists($cacheFilePath) ? include $cacheFilePath : null;
    }

    public static function has($key)
    {
        $cacheDirectory = static::directory();

        $cacheFilePath = base_path("{$cacheDirectory}/ddd-{$key}.php");

        return file_exists($cacheFilePath);
    }

    public static function remember($key, callable $callback)
    {
        return static::has($key)
            ? static::get($key)
            : static::set($key, call_user_func($callback));
    }

    public static function forget($key)
    {
        $cacheDirectory = static::directory();

        $cacheFilePath = base_path("{$cacheDirectory}/ddd-{$key}.php");

        File::delete($cacheFilePath);
    }

    public static function clear()
    {
        $files = glob(base_path(static::directory().'/ddd-*.php'));

        File::delete($files);
    }

    /**
     * The configured cache directory; null (or empty) falls back to the default.
     */
    protected static function directory(): string
    {
        $directory = config('ddd.cache_directory');

        return is_string($directory) && $directory !== '' ? $directory : 'bootstrap/cache/ddd';
    }
}
