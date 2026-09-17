<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

// Framework compatibility contract for command definitions (audit F19 #1, #5).
//
// RegistrationTest already pins the registered names and the presence of
// --domain. What it cannot see is a definition that is present but wrong:
// laravel/framework#60926 changed how generator definitions are declared, and
// the same class of change can silently alter an inherited option's value mode
// or default without renaming anything.
//
// So rather than restating a hand-written list of options (which would go stale
// the moment upstream adds one), this derives the expectation from the framework
// command the ddd generator extends, as it is registered in this application.
// Upstream adds an option, the ddd generator inherits it, the test keeps passing.
// Upstream changes one out from under the package and the test fails.

/**
 * The closest framework ancestor of a package command, or null when the command
 * is not an adapter over a framework generator at all.
 */
function frameworkAncestorOf(object $command): ?string
{
    for ($class = get_parent_class($command); $class !== false; $class = get_parent_class($class)) {
        if (str_starts_with($class, 'Illuminate\\')) {
            // These are base classes rather than registered commands; there is
            // no native counterpart to compare a definition against.
            if (in_array($class, ['Illuminate\Console\Command', 'Illuminate\Console\GeneratorCommand'], true)) {
                return null;
            }

            return $class;
        }
    }

    return null;
}

/**
 * Pair every ddd:* adapter with the natively registered command it inherits
 * from, so the comparison is against a real registered definition.
 */
function domainAdaptersWithNativeCounterparts(): array
{
    $commands = Artisan::all();

    $natives = [];

    foreach ($commands as $name => $command) {
        $natives[get_class($command)] ??= [$name, $command];
    }

    $pairs = [];

    foreach ($commands as $name => $command) {
        if (! str_starts_with($name, 'ddd:')) {
            continue;
        }

        $ancestor = frameworkAncestorOf($command);

        if ($ancestor === null || ! isset($natives[$ancestor])) {
            continue;
        }

        [$nativeName, $native] = $natives[$ancestor];

        $pairs[$name] = [$command, $nativeName, $native];
    }

    return $pairs;
}

it('covers every ddd generator that adapts a framework command', function () {
    $pairs = domainAdaptersWithNativeCounterparts();

    // Guards the derivation itself: if the pairing silently stopped matching
    // anything, every contract below would vacuously pass.
    expect($pairs)->toHaveCount(26)
        ->and($pairs)->toHaveKeys(['ddd:model', 'ddd:controller', 'ddd:factory', 'ddd:policy', 'ddd:migration']);
});

it('inherits each framework option and argument without altering its contract', function () {
    // Deliberate divergences, kept as an explicit ledger rather than an
    // exemption. BaseMigrateMakeCommand nulls $signature and re-declares the
    // option set by hand, so it freezes an older contract instead of inheriting
    // a newer one. That is an intentional design choice, but it means this is
    // the one place where upstream can move without the package following, so
    // any change to this list has to be a decision rather than a surprise.
    $documentedDivergences = [
        'ddd:migration argument name: required=false, make:migration=true',
        'ddd:migration option create: valueOptional=false, make:migration=true',
        'ddd:migration option create: valueRequired=true, make:migration=false',
        'ddd:migration option path: valueOptional=false, make:migration=true',
        'ddd:migration option path: valueRequired=true, make:migration=false',
        'ddd:migration option table: valueOptional=false, make:migration=true',
        'ddd:migration option table: valueRequired=true, make:migration=false',
    ];

    $divergences = [];

    foreach (domainAdaptersWithNativeCounterparts() as $name => [$command, $nativeName, $native]) {
        $definition = $command->getDefinition();
        $nativeDefinition = $native->getDefinition();

        foreach ($nativeDefinition->getArguments() as $argument) {
            $argumentName = $argument->getName();

            if (! $definition->hasArgument($argumentName)) {
                $divergences[] = "{$name} argument {$argumentName}: missing, declared by {$nativeName}";

                continue;
            }

            $inherited = $definition->getArgument($argumentName);

            foreach (['required' => 'isRequired', 'array' => 'isArray'] as $label => $accessor) {
                if ($inherited->{$accessor}() !== $argument->{$accessor}()) {
                    $divergences[] = sprintf(
                        '%s argument %s: %s=%s, %s=%s',
                        $name, $argumentName, $label,
                        var_export($inherited->{$accessor}(), true),
                        $nativeName,
                        var_export($argument->{$accessor}(), true),
                    );
                }
            }
        }

        foreach ($nativeDefinition->getOptions() as $option) {
            $optionName = $option->getName();

            if (! $definition->hasOption($optionName)) {
                $divergences[] = "{$name} option {$optionName}: missing, declared by {$nativeName}";

                continue;
            }

            $inherited = $definition->getOption($optionName);

            $accessors = [
                'valueRequired' => 'isValueRequired',
                'valueOptional' => 'isValueOptional',
                'array' => 'isArray',
            ];

            foreach ($accessors as $label => $accessor) {
                if ($inherited->{$accessor}() !== $option->{$accessor}()) {
                    $divergences[] = sprintf(
                        '%s option %s: %s=%s, %s=%s',
                        $name, $optionName, $label,
                        var_export($inherited->{$accessor}(), true),
                        $nativeName,
                        var_export($option->{$accessor}(), true),
                    );
                }
            }

            if ($inherited->getDefault() !== $option->getDefault()) {
                $divergences[] = sprintf(
                    '%s option %s: default=%s, %s=%s',
                    $name, $optionName,
                    var_export($inherited->getDefault(), true),
                    $nativeName,
                    var_export($option->getDefault(), true),
                );
            }

            if ($inherited->getShortcut() !== $option->getShortcut()) {
                $divergences[] = sprintf(
                    '%s option %s: shortcut=%s, %s=%s',
                    $name, $optionName,
                    var_export($inherited->getShortcut(), true),
                    $nativeName,
                    var_export($option->getShortcut(), true),
                );
            }
        }
    }

    sort($divergences);

    expect($divergences)->toBe($documentedDivergences);
});

it('declares --domain identically on every domain generator', function () {
    foreach (domainAdaptersWithNativeCounterparts() as $name => [$command, $nativeName, $native]) {
        expect($command->getDefinition()->hasOption('domain'))
            ->toBeTrue("[{$name}] should declare --domain");

        $domain = $command->getDefinition()->getOption('domain');

        expect($domain->isValueOptional())->toBeTrue("[{$name}] --domain should accept an optional value")
            ->and($domain->isArray())->toBeFalse("[{$name}] --domain should not be an array option")
            ->and($domain->getShortcut())->toBeNull("[{$name}] --domain should not claim a shortcut")
            ->and($domain->getDefault())->toBeNull("[{$name}] --domain should not carry a default");

        // The option belongs to the package, not the framework: if a native
        // command ever grows its own --domain, the override above is no longer
        // additive and the interaction has to be reconsidered.
        expect($native->getDefinition()->hasOption('domain'))
            ->toBeFalse("[{$nativeName}] unexpectedly declares its own --domain");
    }
});

it('keeps the ddd name as the registered identity after configure runs', function () {
    // #116 territory: the inherited $signature parses a name of its own, and
    // configure() has to win that race. Re-running configure() is what a fresh
    // application boot does, so it must be idempotent.
    foreach (domainAdaptersWithNativeCounterparts() as $name => [$command, $nativeName, $native]) {
        expect($command->getName())->toBe($name);

        $reflection = new ReflectionMethod($command, 'configure');
        $reflection->setAccessible(true);
        $reflection->invoke($command);

        expect($command->getName())->toBe($name, "[{$name}] lost its name when configure() ran again")
            ->and($command->getDefinition()->hasOption('domain'))
            ->toBeTrue("[{$name}] lost --domain when configure() ran again");

        // Re-running configure() must not have duplicated the option either.
        $domainOptions = array_filter(
            array_keys($command->getDefinition()->getOptions()),
            fn (string $option) => $option === 'domain',
        );

        expect($domainOptions)->toHaveCount(1, "[{$name}] declared --domain more than once");
    }
});

it('leaves the native framework generators untouched', function () {
    // The package adds commands; it must never mutate the definition of the
    // framework command it inherits from.
    foreach (domainAdaptersWithNativeCounterparts() as $name => [$command, $nativeName, $native]) {
        expect($native)->not->toBeInstanceOf(get_class($command));
        expect($native->getName())->toBe($nativeName);
        expect($native)->toBeInstanceOf(SymfonyCommand::class);
    }
});
