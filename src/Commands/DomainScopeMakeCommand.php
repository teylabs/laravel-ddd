<?php

namespace Tey\LaravelDDD\Commands;

use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\Mod\Commands\ScopeCommand;

class DomainScopeMakeCommand extends ScopeCommand
{
    use HasDomainStubs,
        ResolvesDomainFromInput;

    protected $name = 'ddd:scope';
}
