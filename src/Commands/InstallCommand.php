<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

class InstallCommand extends Command
{
    // --composer is accepted for compatibility but unused: composer.json is always reloaded with the application's Composer.
    public $signature = 'ddd:install {--composer=global : Absolute path to the Composer binary which should be used}';

    protected $description = 'Install and initialize Laravel-DDD';

    public function handle(): int
    {
        $this->call('ddd:publish', ['--config' => true]);

        $this->comment('Updating composer.json...');
        $this->callSilently('ddd:config', ['action' => 'composer']);

        if (confirm('Would you like to publish stubs now?', default: false, hint: 'You may do this at any time via ddd:stub')) {
            $this->call('ddd:stub');
        }

        return self::SUCCESS;
    }
}
