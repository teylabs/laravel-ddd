<?php

namespace Tey\LaravelDDD\Commands\Migration;

use Illuminate\Database\Console\Migrations\MigrateMakeCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class BaseMigrateMakeCommand extends MigrateMakeCommand
{
    protected $signature = null;

    protected function getArguments()
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of the migration'],
        ];
    }

    protected function getOptions()
    {
        return [
            new InputOption('create', null, InputOption::VALUE_REQUIRED, 'The table to be created'),
            new InputOption('table', null, InputOption::VALUE_REQUIRED, 'The table to migrate'),
            new InputOption('path', null, InputOption::VALUE_REQUIRED, 'The location where the migration file should be created'),
            new InputOption('realpath', null, InputOption::VALUE_NONE, 'Indicate any provided migration file paths are pre-resolved absolute paths'),
            new InputOption('fullpath', null, InputOption::VALUE_NONE, 'Output the full path of the migration (Deprecated)'),
        ];
    }

    protected function getNameInput()
    {
        $name = trim($this->argument('name'));

        return $name;
    }

    protected function qualifyModel(string $model) {}

    protected function getDefaultNamespace($rootNamespace) {}

    protected function getPath($name) {}
}
