<?php

namespace Tey\LaravelDDD\Commands\Concerns;

use Illuminate\Support\Str;

trait ForwardsToDomainCommands
{
    /**
     * The name a forwarded child object is generated under.
     *
     * A nested generator keeps its children beside it, so `Billing/Invoice`
     * produces `Billing/InvoiceFactory` rather than `InvoiceFactory`. Anything
     * that needs to refer to a child — an import written into the parent, say —
     * has to ask for the name here rather than work it out again, because two
     * implementations of this rule drifting apart is exactly how a generated
     * class ends up importing a file that was never written.
     */
    protected function forwardedNameFor(string $name): string
    {
        $subfolder = Str::contains($this->getNameInput(), '/')
            ? Str::beforeLast($this->getNameInput(), '/')
            : null;

        return $subfolder ? "{$subfolder}/{$name}" : $name;
    }

    public function call($command, array $arguments = [])
    {
        $nameWithSubfolder = $this->forwardedNameFor($arguments['name']);

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
