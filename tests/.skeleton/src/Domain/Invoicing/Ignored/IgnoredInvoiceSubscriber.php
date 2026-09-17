<?php

namespace Domain\Invoicing\Ignored;

use Domain\Invoicing\Events\InvoiceTracked;
use Illuminate\Events\Dispatcher;

class IgnoredInvoiceSubscriber
{
    public function handleTracked(InvoiceTracked $event): void
    {
        $event->calls[] = 'ignored-subscriber';
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(InvoiceTracked::class, [self::class, 'handleTracked']);
    }
}
