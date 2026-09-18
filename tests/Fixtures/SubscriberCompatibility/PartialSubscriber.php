<?php

namespace Tey\LaravelDDD\Tests\Fixtures\SubscriberCompatibility;

use Illuminate\Events\Dispatcher;
use Tey\LaravelDDD\Tests\Fixtures\Events\TrackedEvent;

class PartialSubscriber
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
