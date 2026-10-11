<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Console\Command;
use Tey\LaravelDDD\Facades\Autoload;
use Tey\LaravelDDD\Support\DomainMigration;

class OptimizeCommand extends Command
{
    protected $name = 'ddd:optimize';

    protected $description = 'Cache auto-discovered domain objects and migration paths.';

    protected function configure(): void
    {
        $this->setAliases([
            'ddd:cache',
        ]);

        parent::configure();
    }

    public function handle()
    {
        $this->components->info('Caching DDD providers, commands, listeners, migration paths.');
        $this->components->task('domain discovery', fn () => Autoload::cacheDiscovery());
        $this->components->task('domain migration paths', fn () => DomainMigration::cachePaths());
        $this->newLine();

        return self::SUCCESS;
    }
}
