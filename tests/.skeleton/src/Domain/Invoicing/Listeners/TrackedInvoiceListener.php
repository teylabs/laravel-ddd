<?php

namespace Domain\Invoicing\Listeners;

use Domain\Invoicing\Events\InvoiceTracked;

class TrackedInvoiceListener
{
    public function handle(InvoiceTracked $event): void
    {
        $event->calls[] = 'domain-listener';
    }
}
