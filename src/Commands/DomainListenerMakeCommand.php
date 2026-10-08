<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Foundation\Console\ListenerMakeCommand;
use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;

class DomainListenerMakeCommand extends ListenerMakeCommand
{
    use HasDomainStubs,
        ResolvesDomainFromInput;

    protected $name = 'ddd:listener';

    protected function buildClass($name)
    {
        $event = $this->option('event');

        // A bare (or folder/Name) event is the listener's domain event; a name
        // with a backslash is already qualified. Native make:listener would
        // place a bare name under App\Events.
        if (is_string($event) && trim($event) !== '' && $this->blueprint) {
            $event = trim($event);

            $this->input->setOption('event', '\\'.(str_contains($event, '\\')
                ? ltrim($event, '\\')
                : $this->blueprint->domain->object('event', $event)->fullyQualifiedName));
        }

        return parent::buildClass($name);
    }
}
