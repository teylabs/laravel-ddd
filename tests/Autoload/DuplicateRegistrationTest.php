<?php

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Tests\Fixtures\Events\OrdinaryListener;
use Tey\LaravelDDD\Tests\Fixtures\Events\Subscriber;
use Tey\LaravelDDD\Tests\Fixtures\Events\TrackedEvent;
use Tey\LaravelDDD\Tests\Fixtures\SubscriberCompatibility\PartialSubscriber;

/**
 * The listener inventory the next eventManager() boots with (registration is
 * what these tests pin; discovery of the inventory is covered elsewhere).
 */
function configureEventInventory(array $inventory): void
{
    config(['ddd.autoload' => ['listeners' => true], 'test.event_inventory' => $inventory]);
}

function eventManager(): AutoloadManager
{
    return new class extends AutoloadManager
    {
        public function discoverListeners(): array
        {
            return config('test.event_inventory');
        }
    };
}

beforeEach(function () {
    // The discovered inventory of tests/Fixtures/Events, in Laravel-DDD's reported shape.
    $this->inventory = [
        'listeners' => [TrackedEvent::class => [OrdinaryListener::class, [Subscriber::class, 'handleTracked']]],
        'subscribers' => [Subscriber::class],
    ];
    configureEventInventory($this->inventory);
});

it('preserves discovery and explicit subscriber registrations', function () {
    eventManager()->run();
    Event::dispatch($event = new TrackedEvent);

    expect($event->calls)->toEqualCanonicalizing(['listener', 'subscriber', 'subscriber'])
        ->and($this->inventory['subscribers'])->toBe([Subscriber::class]);
});

it('does not add further copies across repeated runs and managers', function () {
    $manager = eventManager();
    $manager->run()->run()->boot()->run();
    eventManager()->run();
    Event::dispatch($event = new TrackedEvent);

    expect($event->calls)->toEqualCanonicalizing(['listener', 'subscriber', 'subscriber']);
});

it('preserves subscriber handlers from previously cached manifests', function () {
    configureEventInventory([
        'listeners' => [TrackedEvent::class => [OrdinaryListener::class, [Subscriber::class, 'handleTracked']]],
        'subscribers' => [Subscriber::class],
    ]);
    eventManager()->run();
    Event::dispatch($event = new TrackedEvent);

    expect($event->calls)->toEqualCanonicalizing(['listener', 'subscriber', 'subscriber']);
});

it('can add new handlers without duplicating old ones or removing manual registrations', function () {
    Event::listen(TrackedEvent::class, fn ($event) => $event->calls[] = 'manual');
    $manager = eventManager();
    $manager->run();

    // Closures are supported at registration time, but are not cacheable.
    $manager = new class($this->inventory) extends AutoloadManager
    {
        public function __construct(private array $inventory)
        {
            parent::__construct();
        }

        public function discoverListeners(): array
        {
            $this->inventory['listeners'][TrackedEvent::class][] = fn ($event) => $event->calls[] = 'new';

            return $this->inventory;
        }
    };
    DomainCache::clear();
    $manager->run()->run();
    Event::dispatch($event = new TrackedEvent);

    expect($event->calls)->toEqualCanonicalizing(['manual', 'listener', 'subscriber', 'subscriber', 'new']);
});

it('registers again when the event dispatcher is replaced', function () {
    $manager = eventManager();
    $manager->run();
    Event::swap(new Dispatcher(app()));
    $manager->run();
    Event::dispatch($event = new TrackedEvent);

    expect($event->calls)->toEqualCanonicalizing(['listener', 'subscriber', 'subscriber']);
});

it('registers normally after the application is refreshed', function () {
    eventManager()->run();
    $inventory = $this->inventory;
    $this->refreshApplication();
    configureEventInventory($inventory);
    eventManager()->run();
    Event::dispatch($event = new TrackedEvent);

    expect($event->calls)->toEqualCanonicalizing(['listener', 'subscriber', 'subscriber']);
});

class RetryableEventSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        if (config('test.fail_subscription')) {
            throw new RuntimeException('Try again');
        }

        $events->listen(TrackedEvent::class, fn ($event) => $event->calls[] = 'retried');
    }
}

it('retries a failed subscription without duplicating completed listener registrations', function () {
    configureEventInventory([
        'listeners' => [TrackedEvent::class => [OrdinaryListener::class]],
        'subscribers' => [RetryableEventSubscriber::class],
    ]);
    config(['test.fail_subscription' => true]);
    $manager = eventManager();
    expect(fn () => $manager->run())->toThrow(RuntimeException::class, 'Try again');

    config(['test.fail_subscription' => false]);
    $manager->run()->run();
    Event::dispatch($event = new TrackedEvent);

    expect($event->calls)->toEqualCanonicalizing(['listener', 'retried']);
});

it('does not retain discarded dispatchers in the registration registry', function () {
    Event::swap($dispatcher = new Dispatcher(app()));
    eventManager()->run();
    $reference = WeakReference::create($dispatcher);

    Event::swap(new Dispatcher(app()));
    unset($dispatcher);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});

class PartialEventSubscriber
{
    public function handleTracked(TrackedEvent $event): void
    {
        $event->calls[] = 'discovered-only';
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen('subscriber.explicit', fn () => null);
    }
}

it('preserves discovered-only subscriber handlers from existing manifests', function () {
    configureEventInventory([
        'listeners' => [TrackedEvent::class => [[PartialEventSubscriber::class, 'handleTracked']]],
        'subscribers' => [PartialEventSubscriber::class],
    ]);
    $manager = eventManager();
    $manager->run()->run();
    Event::dispatch($event = new TrackedEvent);
    expect($event->calls)->toBe(['discovered-only']);
});

it('retains partial subscriber handlers in fresh discovery and cache writes', function () {
    $class = PartialSubscriber::class;
    $inventory = ['listeners' => [TrackedEvent::class => [[$class, 'handleTracked']]], 'subscribers' => [$class]];

    configureEventInventory($inventory);
    eventManager()->run()->run();
    Event::dispatch($event = new TrackedEvent);
    expect($event->calls)->toBe(['discovered-only']);
});

it('registers discovered handlers before invoking subscribe', function () {
    configureEventInventory([
        'listeners' => [TrackedEvent::class => [[SubscribeDispatchesEvent::class, 'handleTracked']]],
        'subscribers' => [SubscribeDispatchesEvent::class],
    ]);
    app()->instance('subscription-event', new TrackedEvent);
    eventManager()->run();
    expect(app('subscription-event')->calls)->toBe(['during-subscribe']);
});

class SubscribeDispatchesEvent
{
    public function handleTracked(TrackedEvent $event): void
    {
        $event->calls[] = 'during-subscribe';
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->dispatch(app('subscription-event'));
    }
}
