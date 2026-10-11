<?php

namespace Tey\LaravelDDD\Facades;

use Illuminate\Support\Facades\Facade;
use Tey\LaravelDDD\Support\AutoloadManager;

/**
 * @see AutoloadManager
 *
 * @method static void boot()
 * @method static void run()
 * @method static array getAllLayerPaths()
 * @method static array getCustomLayerPaths()
 * @method static array getRegisteredCommands()
 * @method static array getRegisteredProviders()
 * @method static array getRegisteredListeners()
 * @method static array getRegisteredSubscribers()
 * @method static AutoloadManager cacheCommands()
 * @method static AutoloadManager cacheDiscovery()
 * @method static AutoloadManager cacheProviders()
 * @method static AutoloadManager cacheListeners()
 */
class Autoload extends Facade
{
    protected static function getFacadeAccessor()
    {
        return AutoloadManager::class;
    }
}
