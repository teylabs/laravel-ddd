<?php

/*
 * Floor smoke check for a consumer-shaped install.
 *
 * The test suite cannot reach the declared floors: pestphp/pest-plugin-laravel
 * requires laravel/framework ^11.45.2|^12.52.0|^13.0, so Pest alone drags the
 * graph above them. That is a limit of the dev toolchain, not of the package —
 * a consumer installing tey/laravel-ddd with --no-dev resolves the declared
 * floors (v11.44.0, v12.0.0, v13.0.0) without complaint.
 *
 * So the floors are checked the way a consumer actually meets them: install the
 * package against the floor release with no dev dependencies, then construct
 * every command adapter. Construction is the meaningful assertion, because
 * Laravel's Command constructor runs configure() and specifyParameters(), which
 * is exactly where an inherited definition from a different framework version
 * would blow up. A missing parent class or a removed constructor signature
 * fails here too.
 *
 * This is not a substitute for the full suite. It answers one question: does
 * the package still load and define its commands against the oldest framework
 * release it claims to support.
 *
 * Usage: php floor-smoke.php <path to vendor/autoload.php>
 */

// Floor-era dependencies emit unrelated deprecations on a current PHP; they are
// noise here and would bury the actual result.
error_reporting(E_ALL & ~E_DEPRECATED);

$autoload = $argv[1] ?? __DIR__.'/../../vendor/autoload.php';

if (! file_exists($autoload)) {
    fwrite(STDERR, "Cannot find autoloader at {$autoload}\n");
    exit(1);
}

require $autoload;

$frameworkVersion = \Composer\InstalledVersions::getPrettyVersion('laravel/framework');

echo "laravel/framework: {$frameworkVersion}\n";
echo 'php: '.PHP_VERSION."\n\n";

// Every adapter over a framework generator, plus the migration adapter that
// re-declares its own definition. These are the classes whose definitions are
// built from framework state at construction time.
$commands = [
    \Tey\LaravelDDD\Commands\DomainCastMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainChannelMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainClassMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainConsoleMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainControllerMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainEnumMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainEventMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainExceptionMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainFactoryMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainInterfaceMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainJobMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainListenerMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainMailMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainMiddlewareMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainModelMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainNotificationMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainObserverMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainPolicyMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainProviderMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainRequestMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainResourceMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainRuleMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainScopeMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainSeederMakeCommand::class,
    \Tey\LaravelDDD\Commands\DomainTraitMakeCommand::class,
];

$files = new \Illuminate\Filesystem\Filesystem;

$failures = [];
$checked = 0;

foreach ($commands as $class) {
    try {
        if (! class_exists($class)) {
            $failures[] = "{$class}: class does not exist";

            continue;
        }

        $parent = get_parent_class($class);

        if ($parent === false || ! class_exists($parent)) {
            $failures[] = "{$class}: parent class {$parent} is missing on this framework version";

            continue;
        }

        // Runs configure() and specifyParameters().
        $command = new $class($files);

        $name = $command->getName();

        if (! is_string($name) || ! str_starts_with($name, 'ddd:')) {
            $failures[] = sprintf('%s: registered name is %s, expected a ddd: name', $class, var_export($name, true));

            continue;
        }

        if (! $command->getDefinition()->hasOption('domain')) {
            $failures[] = "{$class}: --domain is missing from the built definition";

            continue;
        }

        if (! $command->getDefinition()->hasArgument('name')) {
            $failures[] = "{$class}: name argument is missing from the built definition";

            continue;
        }

        $checked++;
    } catch (\Throwable $e) {
        $failures[] = sprintf('%s: %s: %s', $class, get_class($e), $e->getMessage());
    }
}

// The migration adapter takes no Filesystem; it is constructed from a migration
// creator and composer, so it is checked for loadability rather than built.
foreach ([
    \Tey\LaravelDDD\Commands\Migration\DomainMigrateMakeCommand::class,
    \Tey\LaravelDDD\LaravelDDDServiceProvider::class,
] as $class) {
    if (! class_exists($class)) {
        $failures[] = "{$class}: class does not exist";

        continue;
    }

    $parent = get_parent_class($class);

    if ($parent !== false && ! class_exists($parent)) {
        $failures[] = "{$class}: parent class {$parent} is missing on this framework version";

        continue;
    }

    $checked++;
}

echo "Constructed and verified {$checked} classes against laravel/framework {$frameworkVersion}.\n";

if ($failures !== []) {
    echo "\nFAILURES:\n";

    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }

    exit(1);
}

echo "Floor smoke check passed.\n";
