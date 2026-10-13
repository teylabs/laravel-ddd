<?php

use Illuminate\Console\Command;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Console\Migrations\MigrateMakeCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\CastMakeCommand;
use Illuminate\Foundation\Console\ChannelMakeCommand;
use Illuminate\Foundation\Console\ClassMakeCommand;
use Illuminate\Foundation\Console\EnumMakeCommand;
use Illuminate\Foundation\Console\ExceptionMakeCommand;
use Illuminate\Foundation\Console\InterfaceMakeCommand;
use Illuminate\Foundation\Console\JobMakeCommand;
use Illuminate\Foundation\Console\MailMakeCommand;
use Illuminate\Foundation\Console\NotificationMakeCommand;
use Illuminate\Foundation\Console\ObserverMakeCommand;
use Illuminate\Foundation\Console\ResourceMakeCommand;
use Illuminate\Foundation\Console\RuleMakeCommand;
use Illuminate\Foundation\Console\ScopeMakeCommand;
use Illuminate\Foundation\Console\TraitMakeCommand;
use Illuminate\Routing\Console\MiddlewareMakeCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tey\LaravelDDD\Support\Domain;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\LaravelDDD\Support\GeneratorBlueprint;
use Tey\LaravelDDD\Support\Layout\DddLayout;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Commands\GenericClassCommand;
use Tey\Mod\Commands\MigrationCommand;
use Tey\Mod\Generation\GeneratorAdapter;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Generation\ModMigrationCreator;

/*
 * Every ddd:* generator is built on the matching
 * mod:* adapter, so `ddd:X <Domain>:<Name>` and `mod:X <Name> --in=<Domain>`
 * on the compiled ddd layout write the same files.
 *
 * The mod side is the adapter mod itself would use: GeneratorRegistry's
 * default for the kind's role; for a native make:* command mod has no
 * adapter class for, an adapter made the way mod makes them (native command
 * + PlacesGeneratedClass); GenericClassCommand otherwise.
 *
 * Paths are always identical. Bytes are identical except where Laravel-DDD
 * renders its own stub or replacements on top of the native output; those
 * kinds are listed with the reason and compared by namespace and class.
 */

beforeEach(function () {
    // Base classes ddd:* creates on the side are not part of the kind compared here.
    config(['ddd.base_model' => null, 'ddd.base_view_model' => null]);
});

/**
 * Files below the application base path, relative path => sha1.
 *
 * @return array<string, string>
 */
function equivalenceSnapshot(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(base_path())->exclude(['vendor', 'storage', 'bootstrap', 'node_modules'])->ignoreDotFiles(false) as $file) {
        $files[str_replace('\\', '/', $file->getRelativePathname())] = sha1_file($file->getPathname());
    }

    return $files;
}

/**
 * Run a command and return the files it wrote, relative path => contents, then remove them.
 *
 * @return array<string, string>
 */
function writtenBy(string $command, array $arguments): array
{
    $before = equivalenceSnapshot();

    expect(Artisan::call($command, $arguments))->toBe(0, Artisan::output());

    $written = [];

    foreach (array_diff_assoc(equivalenceSnapshot(), $before) as $path => $hash) {
        // Migrations carry the native clock; compare them without it.
        $key = preg_replace('/\d{4}_\d{2}_\d{2}_\d{6}_/', 'TIMESTAMP_', $path);
        $written[$key] = file_get_contents(base_path($path));
        File::delete(base_path($path));
    }

    ksort($written);

    return $written;
}

function modAdapterFor(string $command): Command
{
    $files = app(Filesystem::class);

    $native = [
        'cast' => CastMakeCommand::class,
        'channel' => ChannelMakeCommand::class,
        'class' => ClassMakeCommand::class,
        'enum' => EnumMakeCommand::class,
        'exception' => ExceptionMakeCommand::class,
        'interface' => InterfaceMakeCommand::class,
        'job' => JobMakeCommand::class,
        'mail' => MailMakeCommand::class,
        'middleware' => MiddlewareMakeCommand::class,
        'notification' => NotificationMakeCommand::class,
        'observer' => ObserverMakeCommand::class,
        'resource' => ResourceMakeCommand::class,
        'rule' => RuleMakeCommand::class,
        'scope' => ScopeMakeCommand::class,
        'trait' => TraitMakeCommand::class,
    ];

    if ($command === 'migration') {
        // mod's provider is not registered (dont-discover); make its creator as it would.
        return new MigrationCommand(new ModMigrationCreator($files, base_path('stubs')), app('composer'));
    }

    if (isset(GeneratorRegistry::DEFAULTS[$command])) {
        return app(GeneratorRegistry::DEFAULTS[$command]);
    }

    return match ($native[$command] ?? null) {
        null => app(GenericClassCommand::class),
        CastMakeCommand::class => new class($files) extends CastMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        ChannelMakeCommand::class => new class($files) extends ChannelMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        ClassMakeCommand::class => new class($files) extends ClassMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        EnumMakeCommand::class => new class($files) extends EnumMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        ExceptionMakeCommand::class => new class($files) extends ExceptionMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        InterfaceMakeCommand::class => new class($files) extends InterfaceMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        JobMakeCommand::class => new class($files) extends JobMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        MailMakeCommand::class => new class($files) extends MailMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        MiddlewareMakeCommand::class => new class($files) extends MiddlewareMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        NotificationMakeCommand::class => new class($files) extends NotificationMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        ObserverMakeCommand::class => new class($files) extends ObserverMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        ResourceMakeCommand::class => new class($files) extends ResourceMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        RuleMakeCommand::class => new class($files) extends RuleMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        ScopeMakeCommand::class => new class($files) extends ScopeMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
        TraitMakeCommand::class => new class($files) extends TraitMakeCommand implements GeneratorAdapter
        {
            use PlacesGeneratedClass;
        },
    };
}

/**
 * Register mod:<type> bound to the ddd layout kind of the given type in the given domain.
 */
function registerModEquivalent(string $dddCommand, string $domain): string
{
    $type = (new GeneratorBlueprint($dddCommand, 'Probe', $domain))->type;
    $layout = DddLayout::current();
    $layer = DomainResolver::resolveLayer((new Domain($domain))->domainWithSubdomain, $type);
    $compiled = $layout->compiled();
    $kind = $compiled->kind($layout->kindIdFor($type, $layer));

    /** @var GeneratorAdapter&Command $command */
    $command = modAdapterFor(Str::after($dddCommand, ':'));
    $command->forKind($compiled, $kind);
    $command->setName($name = 'mod:'.Str::after($dddCommand, ':'));

    Artisan::registerCommand($command);

    return $name;
}

/**
 * Kinds whose bytes come from Laravel-DDD's own stub or replacements.
 */
const DDD_RENDERED = [
    'dto' => 'stubs/dto.stub',
    'value' => 'stubs/value-object.stub',
    'view-model' => 'stubs/view-model.stub (+ base view model)',
    'action' => 'stubs/action.stub',
    'base-model' => 'stubs/base-model.stub',
    'base-view-model' => 'stubs/base-view-model.stub',
    'factory' => 'stubs/factory.stub names its model itself',
    'controller' => 'extends the application base controller when it exists; sorted imports',
];

it('writes the same files as the mod adapter on the ddd layout', function (string $dddCommand, string $domain, string $name) {
    $ddd = writtenBy($dddCommand, ['name' => "{$domain}:{$name}"]);
    $type = Str::after($dddCommand, ':');

    $modCommand = registerModEquivalent($dddCommand, $domain);
    $mod = writtenBy($modCommand, ['name' => $name, '--in' => $domain]);

    expect(array_keys($ddd))->toBe(array_keys($mod))->not->toBeEmpty();

    if (! array_key_exists($type, DDD_RENDERED)) {
        expect($ddd)->toBe($mod);

        return;
    }

    foreach ($ddd as $path => $contents) {
        // The class name, whatever modifiers the DDD stub declares.
        foreach (['/^namespace ([^;]+);\r?$/m', '/^(?:final |abstract |readonly )*(?:class|trait|interface|enum) (\w+)/m'] as $declaration) {
            preg_match($declaration, $contents, $dddMatch);
            preg_match($declaration, $mod[$path], $modMatch);

            expect($dddMatch[1] ?? null)->not->toBeNull()->toBe($modMatch[1] ?? null);
        }
    }
})->with(function () {
    $cases = [
        ['ddd:model', 'Invoice'],
        ['ddd:controller', 'InvoiceController'],
        ['ddd:request', 'StoreInvoiceRequest'],
        ['ddd:factory', 'InvoiceFactory'],
        ['ddd:policy', 'InvoicePolicy'],
        ['ddd:provider', 'InvoiceServiceProvider'],
        ['ddd:command', 'SendInvoices'],
        ['ddd:event', 'InvoicePaid'],
        ['ddd:listener', 'SendReceipt'],
        ['ddd:seeder', 'InvoiceSeeder'],
        ['ddd:migration', 'create_invoices_table'],
        ['ddd:cast', 'Money'],
        ['ddd:channel', 'InvoiceChannel'],
        ['ddd:class', 'InvoiceNumber'],
        ['ddd:enum', 'InvoiceStatus'],
        ['ddd:exception', 'InvoiceException'],
        ['ddd:interface', 'Payable'],
        ['ddd:job', 'SendInvoice'],
        ['ddd:mail', 'InvoiceMail'],
        ['ddd:middleware', 'EnsureInvoice'],
        ['ddd:notification', 'InvoicePaidNotification'],
        ['ddd:observer', 'InvoiceObserver'],
        ['ddd:resource', 'InvoiceResource'],
        ['ddd:rule', 'ValidInvoice'],
        ['ddd:scope', 'PaidScope'],
        ['ddd:trait', 'HasInvoices'],
        ['ddd:dto', 'InvoiceData'],
        ['ddd:value', 'InvoiceTotal'],
        ['ddd:view-model', 'ShowInvoiceViewModel'],
        ['ddd:action', 'PayInvoice'],
        ['ddd:base-model', 'BaseModel'],
        ['ddd:base-view-model', 'ViewModel'],
    ];

    foreach ($cases as [$command, $name]) {
        foreach (['Billing', 'Invoicing.Billing'] as $domain) {
            yield "{$command} {$domain}" => [$command, $domain, $name];
        }
    }
});

it('writes the same nested class as the mod adapter', function (string $dddCommand) {
    $ddd = writtenBy($dddCommand, ['name' => 'Billing:Archived/Invoice']);

    $mod = writtenBy(registerModEquivalent($dddCommand, 'Billing'), ['name' => 'Archived/Invoice', '--in' => 'Billing']);

    expect($ddd)->toBe($mod)->not->toBeEmpty();
})->with(['ddd:model', 'ddd:class', 'ddd:job']);

it('generates through the matching mod adapter', function () {
    foreach (app(Kernel::class)->all() as $name => $command) {
        if (! str_starts_with($name, 'ddd:') || ! $command instanceof GeneratorCommand && ! $command instanceof MigrateMakeCommand) {
            continue;
        }

        expect($command)->toBeInstanceOf(GeneratorAdapter::class);
    }
});
