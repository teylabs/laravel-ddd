<?php

namespace Tey\LaravelDDD\Commands\Concerns;

use Illuminate\Support\Str;

trait ForwardsToDomainCommands
{
    public function call($command, array $arguments = [])
    {
        $subfolder = Str::contains($this->getNameInput(), '/')
            ? Str::beforeLast($this->getNameInput(), '/')
            : null;

        $nameWithSubfolder = $subfolder ? "{$subfolder}/{$arguments['name']}" : $arguments['name'];

        $domainCommand = match ($command) {
            'make:request', 'make:model', 'make:factory', 'make:policy',
            'make:migration', 'make:seeder', 'make:controller' => Str::replaceStart('make:', 'ddd:', $command),
            default => $command,
        };

        if ($domainCommand !== $command) {
            // Migration names describe a table operation, not a nested class.
            $arguments = [
                ...$arguments,
                ...($command === 'make:migration' ? [] : ['name' => $nameWithSubfolder]),
                '--domain' => $this->blueprint->domain->dotName,
            ];
        }

        return $this->runCommand($domainCommand, $arguments, $this->output);
    }
}
