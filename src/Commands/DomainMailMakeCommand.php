<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Foundation\Console\MailMakeCommand;
use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\Mod\Commands\MailCommand;

class DomainMailMakeCommand extends MailCommand
{
    use HasDomainStubs,
        ResolvesDomainFromInput;

    protected $name = 'ddd:mail';

    /** Keep companion views native: the isolated DDD plan places the class only. */
    protected function writeMarkdownTemplate()
    {
        MailMakeCommand::writeMarkdownTemplate();
    }

    /** Keep companion views native: the isolated DDD plan places the class only. */
    protected function writeView()
    {
        MailMakeCommand::writeView();
    }
}
