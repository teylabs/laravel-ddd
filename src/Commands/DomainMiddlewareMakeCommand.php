<?php

namespace Tey\LaravelDDD\Commands;

use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\Mod\Commands\MiddlewareCommand;

class DomainMiddlewareMakeCommand extends MiddlewareCommand
{
    use HasDomainStubs,
        ResolvesDomainFromInput;

    protected $name = 'ddd:middleware';
}
