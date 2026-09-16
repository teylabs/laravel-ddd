<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\LaravelDDD\Commands\DomainModelMakeCommand;
use Tey\LaravelDDD\Support\GeneratorBlueprint;

it('preserves child command names arguments and status', function ($commandName, $expectedCommand, $expectedArguments) {
    $command = new class(app(Filesystem::class)) extends DomainModelMakeCommand
    {
        public array $forwarded = [];

        public function forward(string $command): int
        {
            $this->blueprint = new GeneratorBlueprint('ddd:model', 'Nested/Invoice', 'Billing.Internal');
            $this->output = new NullOutput;

            return $this->call($command, ['name' => 'InvoiceFactory', '--domain' => 'Explicit', '--force' => true]);
        }

        protected function getNameInput()
        {
            return 'Nested/Invoice';
        }

        protected function runCommand($command, array $arguments, OutputInterface $output)
        {
            $this->forwarded = [$command, $arguments];

            expect($output)->toBe($this->output);

            return 17;
        }
    };

    expect($command->forward($commandName))->toBe(17)
        ->and($command->forwarded)->toBe([$expectedCommand, $expectedArguments]);
})->with([
    'related class inherits folder and domain' => ['make:factory', 'ddd:factory', [
        'name' => 'Nested/InvoiceFactory', '--domain' => 'Billing.Internal', '--force' => true,
    ]],
    'migration inherits domain without class folder' => ['make:migration', 'ddd:migration', [
        'name' => 'InvoiceFactory', '--domain' => 'Billing.Internal', '--force' => true,
    ]],
    'unmapped command retains explicit arguments' => ['make:event', 'make:event', [
        'name' => 'InvoiceFactory', '--domain' => 'Explicit', '--force' => true,
    ]],
]);
