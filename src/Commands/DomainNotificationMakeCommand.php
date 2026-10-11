<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Foundation\Console\NotificationMakeCommand;
use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\Mod\Commands\NotificationCommand;

class DomainNotificationMakeCommand extends NotificationCommand
{
    use HasDomainStubs,
        ResolvesDomainFromInput;

    protected $name = 'ddd:notification';

    /** Keep companion views native: the isolated DDD plan places the class only. */
    protected function buildClass($name)
    {
        return NotificationMakeCommand::buildClass($name);
    }

    /** Keep companion views native: the isolated DDD plan places the class only. */
    protected function writeMarkdownTemplate()
    {
        NotificationMakeCommand::writeMarkdownTemplate();
    }
}
