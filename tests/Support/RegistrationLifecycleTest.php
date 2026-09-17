<?php

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Tey\LaravelDDD\Support\AutoloadManager;

class LifecycleRegistrationProvider extends ServiceProvider
{
    public function register()
    {
        app('registration.trace')[] = 'provider';
    }
}

class LifecycleRegistrationSubscriber
{
    public function subscribe(Dispatcher $events)
    {
        app('registration.trace')[] = 'subscribe';
        $events->listen('registration.event', fn () => app('registration.trace')[] = 'subscriber');
    }
}

class LifecycleFailingSubscriber
{
    public function subscribe(Dispatcher $events)
    {
        throw new RuntimeException('Subscription failed');
    }
}

class LifecycleFirstCommand extends Command
{
    protected $signature = 'registration:first';
}

class LifecycleSecondCommand extends Command
{
    protected $signature = 'registration:second';
}

class LifecycleConsole extends ConsoleApplication
{
    public static function bootstrapperCount(): int
    {
        return count(static::$bootstrappers);
    }
}

beforeEach(function () {
    app()->instance('registration.trace', new ArrayObject);
    config([
        'ddd.domain_namespace' => 'Domain',
        'ddd.domain_path' => 'src/Domain',
        'ddd.autoload' => ['providers' => true, 'commands' => true, 'listeners' => true, 'policies' => true, 'factories' => true],
    ]);

    $this->manager = new class extends AutoloadManager
    {
        public array $commands = [LifecycleFirstCommand::class];

        public array $subscribers = [LifecycleRegistrationSubscriber::class];

        public function discoverProviders(): array
        {
            return [LifecycleRegistrationProvider::class];
        }

        public function discoverCommands(): array
        {
            return $this->commands;
        }

        public function discoverListeners(): array
        {
            return [
                'listeners' => ['registration.event' => [fn () => app('registration.trace')[] = 'listener']],
                'subscribers' => $this->subscribers,
            ];
        }
    };
});

it('keeps boot-time resolvers separate from deferred provider and event registration', function () {
    expect($this->manager->boot())->toBe($this->manager)
        ->and($this->manager->isBooted())->toBeTrue()
        ->and($this->manager->hasRun())->toBeFalse()
        ->and($this->manager->isConsoleBooted())->toBeFalse()
        ->and(app('registration.trace')->getArrayCopy())->toBe([]);

    Gate::getPolicyFor('Domain\\Lifecycle\\Models\\Widget');
    expect($this->manager->getResolvedPolicies())->toBe([
        'Domain\\Lifecycle\\Models\\Widget' => 'Domain\\Lifecycle\\Policies\\WidgetPolicy',
    ]);
    expect(Factory::resolveFactoryName('Domain\\Lifecycle\\Models\\Widget'))
        ->toBe('Database\\Factories\\Lifecycle\\WidgetFactory');

    expect($this->manager->run())->toBe($this->manager)
        ->and($this->manager->hasRun())->toBeTrue()
        ->and($this->manager->isConsoleBooted())->toBeTrue()
        ->and(app('registration.trace')->getArrayCopy())->toBe(['provider', 'subscribe']);

    Event::dispatch('registration.event');
    expect(app('registration.trace')->getArrayCopy())->toBe(['provider', 'subscribe', 'listener', 'subscriber']);
});

it('preserves repeated registration and inventory-only reboot semantics', function () {
    $this->manager->run()->run();
    Event::dispatch('registration.event');

    expect(app('registration.trace')->getArrayCopy())->toBe([
        'provider', 'subscribe', 'subscribe', 'listener', 'subscriber', 'listener', 'subscriber',
    ]);

    config(['ddd.autoload.listeners' => false, 'ddd.autoload.providers' => false]);
    $this->manager->boot();
    app('registration.trace')->exchangeArray([]);
    Event::dispatch('registration.event');

    expect($this->manager->getRegisteredListeners())->toBe([])
        ->and($this->manager->getRegisteredProviders())->toBe([])
        ->and($this->manager->hasRun())->toBeTrue()
        ->and($this->manager->isConsoleBooted())->toBeTrue()
        ->and(app('registration.trace')->getArrayCopy())->toBe(['listener', 'subscriber', 'listener', 'subscriber']);
});

it('registers one artisan callback per manager and reads the live command inventory', function () {
    $before = LifecycleConsole::bootstrapperCount();
    $this->manager->run()->run();
    expect(LifecycleConsole::bootstrapperCount())->toBe($before + 1);

    $other = new ($this->manager::class);
    $other->commands = [LifecycleSecondCommand::class];
    $other->run();

    expect(LifecycleConsole::bootstrapperCount())->toBe($before + 2)
        ->and($this->manager->getRegisteredCommands())->toBe([
            LifecycleSecondCommand::class => LifecycleSecondCommand::class,
        ]);

    $artisan = new LifecycleConsole(app(), app('events'), 'testing');
    expect($artisan->has('registration:second'))->toBeTrue()
        ->and($artisan->has('registration:first'))->toBeFalse();
});

it('preserves partial registration and lifecycle flags when a subscriber throws', function () {
    $this->manager->subscribers = [LifecycleFailingSubscriber::class];
    expect(fn () => $this->manager->run())->toThrow(RuntimeException::class, 'Subscription failed');

    expect($this->manager->isBooted())->toBeTrue()
        ->and($this->manager->hasRun())->toBeFalse()
        ->and($this->manager->isConsoleBooted())->toBeFalse();

    Event::dispatch('registration.event');
    expect(app('registration.trace')->getArrayCopy())->toBe(['provider', 'listener']);
});
