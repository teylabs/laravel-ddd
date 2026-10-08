# Upgrading

## From 2.x to 3.0.0

> [!NOTE]
> v2 (`lunarstorm/laravel-ddd`) is end of life and does not support Laravel 13.24 or later ([#115](https://github.com/teylabs/laravel-ddd/issues/115)). The `ddd:upgrade` command itself is unaffected on those Laravel versions, so the upgrade path below works regardless.

### Automated Upgrade (Recommended)
As of v2.1.2, the `ddd:upgrade` command automates the entire upgrade. First update to v2.1.2, then run it:

```bash
composer require lunarstorm/laravel-ddd:"^2.1.2"
php artisan ddd:upgrade
```

The command will install `tey/laravel-ddd:^3.0`, migrate `Lunarstorm\LaravelDDD` namespace references in your config and application code, remove `lunarstorm/laravel-ddd`, and finish by running the new package's config migration.

If you are on v2.1.1 or earlier and cannot update, or prefer to upgrade by hand, follow the manual steps below.

### Manual Upgrade

#### Package Rename
The package has moved from `lunarstorm/laravel-ddd` to `tey/laravel-ddd`.

```bash
composer remove lunarstorm/laravel-ddd
composer require tey/laravel-ddd:^3.0
```

### PHP Namespace Change
The root PHP namespace has changed from `Lunarstorm\LaravelDDD` to `Tey\LaravelDDD`.

Find and replace throughout your application:
```
Lunarstorm\LaravelDDD  →  Tey\LaravelDDD
```

### Re-publish Config (if previously published)
```bash
php artisan vendor:publish --tag=ddd-config --force
```

Review any local customizations after re-publishing.

---

## From 1.2.x to 2.0.x
- Minimum required Laravel version is 11.44.
- Minimum PHP version is now 8.2.
- Drop support for Laravel 10.
- Nothing major on the surface; but the package will no longer require various workarounds for Laravel 10 behind the scenes.

## From 1.1.x to 1.2.x
### Breaking
- Stubs are now published to `base_path('stubs/ddd')` instead of `resource_path('stubs/ddd')`. In other words, they are now co-located alongside the framework's published stubs, within a ddd subfolder.
- Published stubs now use `.stub` extension instead of `.php.stub` (following Laravel's convention).
- If you are using published stubs from pre 1.2, you will need to refactor your stubs accordingly.

### Update Config
- Support for Application Layer and Custom Layers was added, introducing changes to the config file.
- Run `php artisan ddd:config update` to rebuild your application's published `ddd.php` config to align with the package's latest copy.
- The update utility will attempt to respect your existing customizations, but you should still review and verify manually.

### Publishing Stubs
- Old way (removed): `php artisan vendor:publish --tag="ddd-stubs"`
- New way: `php artisan ddd:stub` (see [Customizing Stubs](README.md#customizing-stubs) in README for more details).

## From 0.x to 1.x
- Minimum required Laravel version is 10.25.
- The ddd generator [command syntax](README.md#usage) in 1.x. Generator commands no longer receive a domain argument. For example, instead of `ddd:action Invoicing CreateInvoice`, one of the following would be used:
    - Using the --domain option: ddd:action CreateInvoice --domain=Invoicing (this takes precedence).
    - Shorthand syntax: ddd:action Invoicing:CreateInvoice.
    - Or simply ddd:action CreateInvoice to be prompted for the domain afterwards.
- The [config file](config/ddd.php) was refactored. A helper command `ddd:upgrade` is available to assist with this, but it is strongly recommended that you simply wipe out the old config and re-publish via `php artisan vendor:publish --tag="ddd-config"` and re-configure accordingly.
- If applicable, stubs should also be re-published via `php artisan vendor:publish --tag="ddd-stubs"` and re-customized as needed.
- In production, `ddd:cache` should be run during the deployment process. See the [Autoloading in Production](README.md#autoloading-in-production) section for more details.
