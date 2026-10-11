<?php

namespace Tey\LaravelDDD\Commands;

use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\MapsRelatedKinds;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\Mod\Commands\PolicyCommand;

class DomainPolicyMakeCommand extends PolicyCommand
{
    use HasDomainStubs,
        MapsRelatedKinds,
        ResolvesDomainFromInput;

    protected $name = 'ddd:policy';
}
