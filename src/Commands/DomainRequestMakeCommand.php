<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Support\Str;
use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\Mod\Commands\RequestCommand;

class DomainRequestMakeCommand extends RequestCommand
{
    use HasDomainStubs,
        ResolvesDomainFromInput;

    protected $name = 'ddd:request';

    protected function rootNamespace()
    {
        return Str::finish(DomainResolver::resolveRootNamespace($this->blueprint->type), '\\');
    }
}
