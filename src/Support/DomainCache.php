<?php

namespace Tey\LaravelDDD\Support;

use Illuminate\Support\Facades\File;

class DomainCache
{
    public static function set($key, $value)
    {
        $cacheFilePath = static::path($key);

        File::ensureDirectoryExists(dirname($cacheFilePath));

        file_put_contents(
            $cacheFilePath,
            '<?php '.PHP_EOL.'return '.var_export($value, true).';'
        );

        return $value;
    }

    public static function get($key)
    {
        $cacheFilePath = static::path($key);

        return file_exists($cacheFilePath) ? include $cacheFilePath : null;
    }

    public static function has($key)
    {
        $cacheFilePath = static::path($key);

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
        $cacheFilePath = static::path($key);

        File::delete($cacheFilePath);
    }

    public static function clear()
    {
        $files = glob(base_path(static::directory().'/ddd-*.php'));

        File::delete($files);
    }

    /**
     * The absolute path of one cache file (ddd-{$key}.php).
     */
    public static function path(string $key): string
    {
        return base_path(static::directory()."/ddd-{$key}.php");
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
