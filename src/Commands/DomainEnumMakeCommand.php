<?php

namespace Tey\LaravelDDD\Commands;

use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\Mod\Commands\EnumCommand;

class DomainEnumMakeCommand extends EnumCommand
{
    use HasDomainStubs,
        ResolvesDomainFromInput;

    protected $name = 'ddd:enum';
}
