<?php

namespace Tey\LaravelDDD\Tests\Fixtures\Events;

use Illuminate\Events\Dispatcher;

class Subscriber
{
    public function handleTracked(TrackedEvent $event): void
    {
        $event->calls[] = 'subscriber';
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(TrackedEvent::class, [self::class, 'handleTracked']);
    }
}
