<?php

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Lorisleiva\Lody\Lody;
use Tey\LaravelDDD\Support\AutoloadManager;
use Tey\LaravelDDD\Support\DomainCache;
use Tey\LaravelDDD\Support\DomainDiscovery;
use Tey\LaravelDDD\Tests\Fixtures\Events\OrdinaryListener;
use Tey\LaravelDDD\Tests\Fixtures\Events\Subscriber;
use Tey\LaravelDDD\Tests\Fixtures\Events\TrackedEvent;

function configureEventInventory(array $inventory): void
{
    config(['ddd.autoload' => ['listeners' => true]]);
    DomainCache::set('domain-listeners', $inventory);
}

beforeEach(function () {
    // Other tests rewrite Composer's on-disk map to the application skeleton.
    // These package-local fixtures have their own explicit namespace mapping.
    Lody::resolveClassnameUsing(fn (SplFileInfo $file) => 'Tey\\LaravelDDD\\Tests\\Fixtures\\Events\\'.pathinfo($file->getFilename(), PATHINFO_FILENAME));
    $this->inventory = (new DomainDiscovery)->listeners([__DIR__.'/../Fixtures/Events'], base_path());
    configureEventInventory($this->inventory);
});

it('lets subscribers own their discovered handler registrations', function () {
    (new AutoloadManager)->run();
    Event::dispatch($event = new TrackedEvent);

    expect($event->calls)->toEqualCanonicalizing(['listener', 'subscriber'])
        ->and($this->inventory['subscribers'])->toBe([Subscriber::class]);
});

it('registers each discovered handler once across repeated runs and managers', function () {
    $manager = new AutoloadManager;
    $manager->run()->run()->boot()->run();
    (new AutoloadManager)->run();
    Event::dispatch($event = new TrackedEvent);

    expect($event->calls)->toEqualCanonicalizing(['listener', 'subscriber']);
});

it('normalizes subscriber handlers from previously cached manifests', function () {
    configureEventInventory([
        'listeners' => [TrackedEvent::class => [OrdinaryListener::class, [Subscriber::class, 'handleTracked']]],
        'subscribers' => [Subscriber::class],
    ]);
    (new AutoloadManager)->run();
    Event::dispatch($event = new TrackedEvent);

    expect($event->calls)->toEqualCanonicalizing(['listener', 'subscriber']);
});

it('can add new handlers without duplicating old ones or removing manual registrations', function () {
    Event::listen(TrackedEvent::class, fn ($event) => $event->calls[] = 'manual');
    $manager = new AutoloadManager;
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

    expect($event->calls)->toEqualCanonicalizing(['manual', 'listener', 'subscriber', 'new']);
});

it('registers again when the event dispatcher is replaced', function () {
    $manager = new AutoloadManager;
    $manager->run();
    Event::swap(new Dispatcher(app()));
    $manager->run();
    Event::dispatch($event = new TrackedEvent);

    expect($event->calls)->toEqualCanonicalizing(['listener', 'subscriber']);
});

it('registers normally after the application is refreshed', function () {
    (new AutoloadManager)->run();
    $inventory = $this->inventory;
    $this->refreshApplication();
    configureEventInventory($inventory);
    (new AutoloadManager)->run();
    Event::dispatch($event = new TrackedEvent);

    expect($event->calls)->toEqualCanonicalizing(['listener', 'subscriber']);
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
    $manager = new AutoloadManager;
    expect(fn () => $manager->run())->toThrow(RuntimeException::class, 'Try again');

    config(['test.fail_subscription' => false]);
    $manager->run()->run();
    Event::dispatch($event = new TrackedEvent);

    expect($event->calls)->toEqualCanonicalizing(['listener', 'retried']);
});

it('does not retain discarded dispatchers in the registration registry', function () {
    Event::swap($dispatcher = new Dispatcher(app()));
    (new AutoloadManager)->run();
    $reference = WeakReference::create($dispatcher);

    Event::swap(new Dispatcher(app()));
    unset($dispatcher);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});
