<?php

namespace Tey\LaravelDDD\Support;

use Illuminate\Support\Str;
use Lorisleiva\Lody\Lody;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class DomainMigration
{
    public static function domainMigrationFolder(): string
    {
        return Path::normalize(config('ddd.namespaces.migration', 'Database/Migrations'));
    }

    public static function cachePaths(): void
    {
        DomainCache::set('domain-migration-paths', static::discoverPaths());
    }

    public static function clearCache(): void
    {
        DomainCache::forget('domain-migration-paths');
    }

    public static function paths(): array
    {
        if (config('ddd.autoload.migrations', true) === false) {
            return [];
        }

        return DomainCache::has('domain-migration-paths')
            ? DomainCache::get('domain-migration-paths')
            : static::discoverPaths();
    }

    protected static function filterDirectories($path): array
    {
        return collect($path)
            ->filter(fn ($path) => is_dir($path))
            ->toArray();
    }

    public static function discoverPaths(): array
    {
        $configValue = config('ddd.autoload.migrations', true);

        if ($configValue === false) {
            return [];
        }

        $paths = static::filterDirectories([
            app()->basePath(DomainResolver::domainPath()),
        ]);

        if (empty($paths)) {
            return [];
        }

        $finder = static::finder($paths);

        return Lody::filesFromFinder($finder)
            ->map(fn ($file) => Path::normalize($file->getPath()))
            ->unique()
            ->values()
            ->toArray();
    }

    protected static function finder(array $paths)
    {
        $filter = function (SplFileInfo $file) {
            $configuredMigrationFolder = static::domainMigrationFolder();

            $relativePath = Path::normalize($file->getRelativePath());

            return Str::endsWith($relativePath, $configuredMigrationFolder);
        };

        return Finder::create()
            ->files()
            ->in($paths)
            ->filter($filter);
    }
}
