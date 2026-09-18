---
name: laravel-ddd-development
description: "Generate and organize objects using tey/laravel-ddd, including ddd:* Artisan commands, configured domain and application layers, custom stubs, and discovery. Use when working with this package or config/ddd.php; not for general DDD architecture discussions in applications without the package."
license: MIT
metadata:
  author: Jasper Tey / Teylabs
---

# Laravel-DDD development

Use the application's configured structure and existing conventions. This package supplies generators and discovery; it does not require repositories, aggregates, CQRS, or a particular business-logic pattern.

## Establish the layout

Read the application's `config/ddd.php` when present, its Composer PSR-4 mappings, and nearby classes. Missing configuration may inherit the installed package's `config/ddd.php` defaults.

- `domain_path` and `domain_namespace` identify the domain root.
- `application_path`, `application_namespace`, and `application_objects` route selected object types to the application layer.
- `layers` maps additional top-level namespaces to directories.
- `namespaces` assigns each object type its relative namespace.
- `base_model`, `base_dto`, `base_view_model`, and `base_action` affect generated inheritance.

Do not relocate existing code or rewrite these settings merely to match an example. After an intentional layer-mapping change, `php artisan ddd:config composer` synchronizes Composer mappings; inspect the resulting diff.

## Generate objects

Use an explicit domain to avoid domain-selection prompts. Check `php artisan help ddd:controller` (or the relevant command) for options in the installed version. Most generators wrap Laravel commands, whose available options vary by framework version.

```bash
php artisan ddd:model Invoicing:Invoice
php artisan ddd:controller Invoicing:InvoiceController --model=Invoice --requests --api
```

With package defaults, the model is under `src/Domain/Invoicing/Models`; controllers and requests are under `app/Modules/Invoicing`. These are defaults, not fixed paths.

- `ddd:model Invoice --domain=Invoicing` is equivalent to the shorthand `Invoicing:Invoice`.
- `Invoicing:Payment/Transaction` nests an object within its configured type namespace.
- `Reporting.Internal:Report` selects a nested domain.
- `Invoicing:/Support/InvoiceBuilder` starts at the selected layer/domain root, bypassing the configured type namespace; it is not an absolute filesystem path.
- `ddd:class`, `ddd:interface`, and `ddd:trait` have empty type namespaces by default, so include desired folders in the name.

Inspect generated namespaces, imports, inheritance, and related request/factory/model files. Run tests relevant to the requested application behaviour. Check existing files before using `--force`; it overwrites generated output.

DTO and action defaults reference `spatie/laravel-data` and `lorisleiva/laravel-actions`. Check those dependencies or the application's custom stubs before generating classes that reference them. Do not install optional packages solely because they appear in an example.

## Customize generation

Inspect published stubs before assuming framework defaults. Stub precedence is `stubs/ddd`, then shared `stubs`, then package/framework defaults. Use `php artisan ddd:stub --list` to inspect choices; publish only the stubs needed for the requested change.

`DDD::resolveObjectSchemaUsing()` can return an `ObjectSchema` or `null` to retain default resolution. Its callback receives domain name, name input, object type, and `CommandContext`. Consult the installed package's example/signature before implementing it. Keep namespace, fully qualified class name, and path consistent with Composer mappings. A custom primary-object resolver does not guarantee that all related-object references or reverse discovery follow arbitrary relocation: verify those combinations explicitly.

Existing generator types can be configured; do not invent commands such as `ddd:repository` or assume a preset API exists. Check the registered Artisan commands before selecting a generator.

## Discovery and caches

Providers and console commands are discovered across configured domain, application, and custom layers. Listener discovery is opt-in. Use public typed `handle*` or `__invoke` methods for Laravel event discovery. Subscriber candidates must first be discoverable; a `subscribe()` method alone does not guarantee discovery.

Policy and factory naming use resolver callbacks, not the same class-scanning mechanism. Applications with their own naming callbacks should inspect the respective `ddd.autoload` switches before replacing them. Migration discovery is rooted in the domain layer's configured migration folders.

Set individual `ddd.autoload` switches to `false` to disable them. Deleting the block can restore package defaults. `autoload_ignore` filters class scanning, not migration paths or policy/factory naming callbacks. A custom `DDD::filterAutoloadPathsUsing()` callback replaces the default ignore filter; preserve needed exclusions.

After intentional configuration or discovery changes, rebuild affected application caches through the project's deployment workflow. `ddd:optimize` and `ddd:clear` manage package manifests and integrate with Laravel's `optimize` / `optimize:clear`. Do not run broad cache-clearing commands against an unrelated environment just to generate a file.

For exact behaviour, use the installed package source and README rather than assuming the latest upstream documentation matches the installed release. Application instructions and the user's chosen scope take precedence over these examples.
