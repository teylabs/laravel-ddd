<?php

namespace Tey\LaravelDDD\Commands\Migration;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;

class DomainMigrateMakeCommand extends BaseMigrateMakeCommand
{
    use ResolvesDomainFromInput {
        beforeHandle as resolveDomainInput;
    }

    protected $name = 'ddd:migration';

    /**
     * mod places the migration before the native handle() runs, so the
     * domain (option, prefix or prompt) is resolved first; beforeHandle()
     * still runs once, from handle().
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Native creators do not participate in Mod's plan collection.
            if ($input->getOption('dry-run') && ($this->usesNativePath() || $this->usesApplicationCreator)) {
                $this->components->error('Native migration creators and --path/--realpath do not support --dry-run.');

                return self::FAILURE;
            }

            if (! $this->usesNativePath()) {
                $this->resolveDomainInput();
            }

            return parent::execute($input, $output);
        } finally {
            $this->blueprint = null;
        }
    }

    protected function beforeHandle()
    {
        if ($this->blueprint === null) {
            $this->resolveDomainInput();
        }
    }

    protected function getMigrationPath()
    {
        if ($this->usesApplicationCreator && ! $this->usesNativePath() && $this->blueprint) {
            return $this->laravel->basePath($this->blueprint->getMigrationPath());
        }

        return parent::getMigrationPath();
    }

    protected function getNameInput()
    {
        return trim($this->argument('name'));
    }

    /**
     * --path and --realpath are honoured as natively.
     */
    protected function nativePathAllowed(): bool
    {
        return true;
    }

    protected function plansEagerly(): bool
    {
        return true;
    }

    protected function usesNativePath(): bool
    {
        return $this->input->getOption('path') !== null || (bool) $this->input->getOption('realpath');
    }
}
