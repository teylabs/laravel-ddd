<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domain Layer
    |--------------------------------------------------------------------------
    |
    | The path and namespace of the domain layer.
    |
    */
    'domain_path' => 'src/Domain',
    'domain_namespace' => 'Domain',

    /*
    |--------------------------------------------------------------------------
    | Application Layer
    |--------------------------------------------------------------------------
    |
    | The path and namespace of the application layer, and the objects
    | that should be recognized as part of the application layer.
    |
    */
    'application_path' => 'app/Modules',
    'application_namespace' => 'App\Modules',
    'application_objects' => [
        'controller',
        'request',
        'middleware',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Layers
    |--------------------------------------------------------------------------
    |
    | Additional top-level namespaces and paths that should be recognized as
    | layers when generating ddd:* objects.
    |
    | e.g., 'Infrastructure' => 'src/Infrastructure',
    |
    */
    'layers' => [
        'Infrastructure' => 'src/Infrastructure',
        // 'Integrations' => 'src/Integrations',
        // 'Support' => 'src/Support',
    ],

    /*
    |--------------------------------------------------------------------------
    | Object Namespaces
    |--------------------------------------------------------------------------
    |
    | This value contains the default namespaces of ddd:* generated
    | objects relative to the layer of which the object belongs to.
    |
    */
    'namespaces' => [
        'model' => 'Models',
        'data_transfer_object' => 'Data',
        'view_model' => 'ViewModels',
        'value_object' => 'ValueObjects',
        'action' => 'Actions',
        'cast' => 'Casts',
        'class' => '',
        'channel' => 'Channels',
        'command' => 'Commands',
        'controller' => 'Controllers',
        'enum' => 'Enums',
        'event' => 'Events',
        'exception' => 'Exceptions',
        'factory' => 'Database\Factories',
        'interface' => '',
        'job' => 'Jobs',
        'listener' => 'Listeners',
        'mail' => 'Mail',
        'middleware' => 'Middleware',
        'migration' => 'Database\Migrations',
        'notification' => 'Notifications',
        'observer' => 'Observers',
        'policy' => 'Policies',
        'provider' => 'Providers',
        'resource' => 'Resources',
        'request' => 'Requests',
        'rule' => 'Rules',
        'scope' => 'Scopes',
        'seeder' => 'Database\Seeders',
        'trait' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Base Model
    |--------------------------------------------------------------------------
    |
    | The base model class which generated domain models should extend. If
    | set to null, the generated models will extend Laravel's default.
    |
    */
    'base_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Base DTO
    |--------------------------------------------------------------------------
    |
    | The base class which generated data transfer objects should extend. By
    | default, generated DTOs will extend `Spatie\LaravelData\Data` from
    | Spatie's Laravel-data package, a highly recommended data object
    | package to work with.
    |
    */
    'base_dto' => 'Spatie\LaravelData\Data',

    /*
    |--------------------------------------------------------------------------
    | Base ViewModel
    |--------------------------------------------------------------------------
    |
    | The base class which generated view models should extend. By default,
    | generated view models will extend `Domain\Shared\ViewModels\ViewModel`,
    | which will be created if it doesn't already exist.
    |
    */
    'base_view_model' => 'Domain\Shared\ViewModels\ViewModel',

    /*
    |--------------------------------------------------------------------------
    | Base Action
    |--------------------------------------------------------------------------
    |
    | The base class which generated action objects should extend. By default,
    | generated actions are based on the `lorisleiva/laravel-actions` package
    | and do not extend anything.
    |
    */
    'base_action' => null,

    /*
    |--------------------------------------------------------------------------
    | Autoloading
    |--------------------------------------------------------------------------
    |
    | Configure whether domain providers, commands, policies, factories,
    | and migrations should be auto-discovered and registered.
    |
    */
    'autoload' => [
        'providers' => true,
        'commands' => true,
        'policies' => true,
        'factories' => true,
        'migrations' => true,
        'listeners' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | PSR-4 Autoload Ignore Folders
    |--------------------------------------------------------------------------
    |
    | Folders that should be excluded from PSR-4 class scanning
    | (auto-discovery of providers, commands, and listeners),
    | relative to the root of each domain.
    |
    | This does not affect migration path discovery, which is controlled
    | separately by the autoload.migrations option above.
    |
    | e.g., src/Domain/Invoicing/<folder-to-ignore>
    |
    | If more advanced filtering is needed, a callback can be registered
    | using `DDD::filterAutoloadPathsUsing(callback $filter)` in
    | the AppServiceProvider's boot method.
    |
    */
    'autoload_ignore' => [
        'Tests',
        'Database/Migrations',
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | The folder where the domain cache files will be stored. Used for domain
    | autoloading.
    |
    */
    'cache_directory' => 'bootstrap/cache/ddd',
];
