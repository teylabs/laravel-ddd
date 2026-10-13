# Upgrading

## From 3.x to 4.0.0

v4 rebuilds Laravel-DDD's internals on [tey/mod](https://github.com/teylabs/mod). Your `ddd:*` commands, folders and `config/ddd.php` stay the same. The user-visible
changes are listed below.

- Laravel-DDD 4.0 requires PHP 8.3+ and Laravel 12 or 13. Laravel 11 applications stay on the 3.x line.
- tey/laravel-ddd 4.0 depends on tey/mod ^0.7, which Composer installs automatically. Laravel-DDD registers no mod:* commands and does not register mod's service provider (it is listed under `extra.laravel.dont-discover`).
- tey/laravel-ddd 4.0 no longer requires lorisleiva/lody. If your application used Lody only through this package, require it yourself.
- Discovered providers, commands and listeners register in sorted file-path order within each discovery path (3.x used raw filesystem order).
- Run `php artisan ddd:optimize` (or `optimize`) after upgrading: discovery is now cached in `ddd-discovery.php` and `ddd-migration-paths.php`, the 3.x `ddd-domain-*.php` cache files are ignored, and `ddd:optimize` reports two tasks; rolling back to 3.x needs `ddd:clear` then `ddd:optimize`. Mod uses discovery cache schema 2; rebuild any earlier v4 caches with `ddd:clear` then `ddd:optimize`.
- The internal classes `Tey\LaravelDDD\Support\DomainDiscovery` and `DomainRegistration` are removed; discovery runs on tey/mod (Lody is no longer used).
- Laravel-DDD generator commands now extend tey/mod command adapters and implement Tey\Mod\Generation\GeneratorAdapter; subclasses keep working unchanged.
- Republish ddd stubs (dto.stub) to get base_dto support; previously published dto stubs keep extending Data.
- ddd:stub now lists and publishes base-model.stub.
- ddd:* generators now exit 0 when the class already exists or the name is reserved by PHP, like make:*; they still print the error and leave the file untouched.
- ddd:upgrade gains --to=mod (with --force and --composer) to move an application to plain tey/mod.
- ddd:* generators gain --dry-run to preview files and --json to print the preview as JSON. Migration previews require layout placement and the native framework creator; `--path`, `--realpath` and application creator subclasses refuse `--dry-run` before writing.
- ddd:* generators announce a domain they create ("Created new domain Billing (existing: Invoicing)."), and a domain typed in the wrong case reuses the existing folder and says so.

### Moving from Laravel-DDD to tey/mod

tey/mod's built-in `ddd` layout places files where Laravel-DDD's defaults do. To move an
application to plain tey/mod, run the upgrade command. It shows the plan and a diff of every
file first, and writes nothing until you add `--force`:

```bash
php artisan ddd:upgrade --to=mod            # dry run: the plan and the diffs
php artisan ddd:upgrade --to=mod --force    # write the changes
php artisan ddd:upgrade --to=mod --force --composer   # also require tey/mod and remove tey/laravel-ddd
```

It writes `config/mod.php` with `'layout' => 'ddd'`, and, when your configuration differs from
the defaults, a `ModLayoutServiceProvider` that extends the layout with `Mod::layout('ddd')`. It
also replaces `DomainModel`, `HasDomainFactory` and `DomainFactory` in your code with Laravel's
`Model`, `HasFactory` and `Factory`, copies published `stubs/ddd/*.stub` files to mod's stub
names, and removes the Laravel-DDD discovery caches. Anything it can't carry over without
changing what your application does at runtime is listed as a blocker, and `--force` then writes
nothing. Examples are discovery paths listed in `ddd.autoload.*` and a
`DDD::filterAutoloadPathsUsing()` filter.

The commands keep their names under the `mod:` prefix (`ddd:model Invoicing:Invoice` becomes
`mod:model Invoicing:Invoice`), except `ddd:value` → `mod:value-object` (`mod:value` stays as an
alias) and `ddd:optimize` / `ddd:clear` → `mod:cache` / `mod:clear`. `ddd:list`, `ddd:install`,
`ddd:config`, `ddd:stub`, `ddd:publish` and `ddd:upgrade` have no mod equivalent.

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
