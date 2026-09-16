<?php

namespace Tey\LaravelDDD\Tests\Fixtures\Events;

class OrdinaryListener
{
    public function handle(TrackedEvent $event): void
    {
        $event->calls[] = 'listener';
    }
}
