<?php

namespace Tey\LaravelDDD\Commands;

use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\Mod\Commands\ChannelCommand;

class DomainChannelMakeCommand extends ChannelCommand
{
    use HasDomainStubs,
        ResolvesDomainFromInput;

    protected $name = 'ddd:channel';
}
