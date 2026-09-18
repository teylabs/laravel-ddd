<?php

namespace Domain\Invoicing\Ignored;

use Domain\Invoicing\Events\InvoiceTracked;

class IgnoredInvoiceListener
{
    public function handle(InvoiceTracked $event): void
    {
        $event->calls[] = 'ignored-listener';
    }
}
