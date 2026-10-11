<?php

namespace Tey\LaravelDDD\Support;

use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Exceptions\InvalidDiscoveryCache;
use Tey\Mod\Layout\Layout;

class DomainMigration
{
    public static function domainMigrationFolder(): string
    {
        return Path::normalize(config('ddd.namespaces.migration', 'Database/Migrations'));
    }

    public static function cachePaths(): void
    {
        static::discovery()->cacheInventory();
    }

    public static function clearCache(): void
    {
        DomainCache::forget('migration-paths');
    }

    public static function paths(): array
    {
        if (config('ddd.autoload.migrations', true) === false) {
            return [];
        }

        return static::cachedPaths() ?? static::discoverPaths();
    }

    protected static function filterDirectories($path): array
    {
        return collect($path)
            ->filter(fn ($path) => is_dir($path))
            ->toArray();
    }

    /**
     * Migration directories below the domain root: every {domain+}/<migration folder>
     * directory holding at least one file, in sorted order (mod directory discovery).
     */
    public static function discoverPaths(): array
    {
        $configValue = config('ddd.autoload.migrations', true);

        if ($configValue === false) {
            return [];
        }

        if (static::filterDirectories([app()->basePath(DomainResolver::domainPath())]) === []) {
            return [];
        }

        return static::absolute(array_values(static::discovery()->scan()->directories(static::fileTypeId())));
    }

    /**
     * The cached directories, or null when the cache is absent, foreign or stale (then discovery scans).
     */
    protected static function cachedPaths(): ?array
    {
        if (! is_file(DomainCache::path('migration-paths'))) {
            return null;
        }

        $discovery = static::discovery();

        try {
            $inventory = $discovery->readCache();
        } catch (InvalidDiscoveryCache) {
            return null;
        }

        return static::absolute(array_values($inventory->directories(static::fileTypeId())));
    }

    /**
     * Directory discovery of the domain root's migration folders.
     *
     * Migrations need only the domain path and the migration folder, so this
     * compiles its own one-kind layout (a class-less files root) instead of the
     * full ddd layout: no namespace configuration is involved.
     */
    protected static function discovery()
    {
        $folder = trim(str_replace('\\', '/', static::domainMigrationFolder()), '/');

        $compiled = Layout::fresh('ddd-migrations')
            ->mounts('domain', null, trim(str_replace('\\', '/', DomainResolver::domainPath()), '/'))
            ->generates(static::fileTypeId(), in: 'domain:{domain+}/'.$folder, timestamped: true, command: false)
            ->withoutCommands()
            ->compiled();

        $options = DiscoveryOptions::fromConfig([
            'file_types' => [static::fileTypeId() => 'directory'],
            'cache' => DomainCache::path('migration-paths'),
            'on_stale_cache' => 'scan',
        ]);

        return new Discovery(layout: $compiled, options: $options, basePath: app()->basePath());
    }

    /**
     * The migration directory kind of the private migrations layout.
     */
    protected static function fileTypeId(): string
    {
        return 'domain-migration';
    }

    /**
     * @param  list<string>  $directories  base-relative, '/'-separated
     * @return list<string>
     */
    protected static function absolute(array $directories): array
    {
        return array_map(fn (string $directory) => Path::normalize(app()->basePath($directory)), $directories);
    }
}
