## Laravel-DDD

This application uses `tey/laravel-ddd` for generators and discovery across configured layers. Before placing domain objects, inspect `config/ddd.php`, Composer PSR-4 mappings, and nearby application code; the package defaults are not necessarily this application's layout. Use the `laravel-ddd-development` skill when generating objects or changing package configuration, stubs, or discovery behavior. Prefer the matching `ddd:*` generator for configured domain objects and consult its installed Artisan help for available options.
