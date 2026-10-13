<?php

namespace Tey\LaravelDDD\Commands;

use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\Mod\Commands\JobCommand;

class DomainJobMakeCommand extends JobCommand
{
    use HasDomainStubs,
        ResolvesDomainFromInput;

    protected $name = 'ddd:job';
}
