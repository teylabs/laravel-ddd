<?php

namespace Tey\LaravelDDD\Tests\Support;

use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Support\DomainMigration;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\Mod\Discovery\DiscoveredArtifact;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\Inventory;

/**
 * Writes a discovery cache (ddd-discovery.php) holding exactly the given
 * inventory, with the fingerprints the current configuration expects, so a
 * booting AutoloadManager trusts it the way it trusts a real `ddd:optimize`.
 *
 * Families use Laravel-DDD's reported shapes: providers/commands are lists of
 * classes; listeners are ['listeners' => [event => ['Class' | 'Class@method'
 * | [Class, method]]], 'subscribers' => [...]]. Subscribers are derived from
 * listener classes in v4, so a subscriber only survives with a listener binding.
 */
final class DiscoveryCacheFixture
{
    /**
     * @return string the written cache body
     */
    public static function write(array $families, ?AutoloadManager $manager = null): string
    {
        $manager ??= new AutoloadManager;
        $discovery = (fn () => $this->discovery($this->families()))->call($manager);
        $entries = [];
        $index = 0;

        foreach (['providers' => DiscoveryType::Provider, 'commands' => DiscoveryType::Command] as $family => $type) {
            foreach ($families[$family] ?? [] as $class) {
                $entries[] = new DiscoveredArtifact(substr($family, 0, -1), $type, $class, self::path($index++));
            }
        }

        $bindings = [];

        foreach ($families['listeners']['listeners'] ?? [] as $event => $listeners) {
            foreach ($listeners as $listener) {
                [$class, $method] = is_array($listener) ? $listener : array_pad(explode('@', $listener, 2), 2, 'handle');
                $bindings[$class][] = ['event' => $event, 'method' => $method];
            }
        }

        foreach ($bindings as $class => $events) {
            $entries[] = new DiscoveredArtifact('listener', DiscoveryType::Listener, $class, self::path($index++), [], $events);
        }

        $discovery->cache()->write(new Inventory($entries), $discovery->presetFingerprint(), $discovery->definitionsFingerprint());

        return (string) file_get_contents(DomainCache::path('discovery'));
    }

    /**
     * Writes ddd-migration-paths.php holding exactly these base-relative directories.
     *
     * @return string the written cache body
     */
    public static function writeMigrationPaths(array $directories): string
    {
        $discovery = (static fn () => self::discovery())->bindTo(null, DomainMigration::class)();
        $kindId = (static fn () => self::fileTypeId())->bindTo(null, DomainMigration::class)();
        $entries = array_map(fn (string $directory) => new DiscoveredArtifact($kindId, DiscoveryType::Directory, '', $directory), $directories);

        $discovery->cache()->write(new Inventory($entries), $discovery->presetFingerprint(), $discovery->definitionsFingerprint());

        return (string) file_get_contents(DomainCache::path('migration-paths'));
    }

    private static function path(int $index): string
    {
        return trim(str_replace('\\', '/', DomainResolver::domainPath()), '/').'/Cached/Entry'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).'.php';
    }
}
