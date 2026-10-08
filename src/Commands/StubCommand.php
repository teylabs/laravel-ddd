<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Events\PublishingStubs;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tey\LaravelDDD\Facades\DDD;
use Tey\LaravelDDD\Support\Path;

use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

class StubCommand extends Command
{
    public $name = 'ddd:stub';

    protected $description = 'Publish one or more stubs';

    protected function getArguments()
    {
        return [
            new InputArgument('name', InputArgument::IS_ARRAY, 'One or more names of specific stubs to publish'),
        ];
    }

    protected function getOptions()
    {
        return [
            new InputOption('all', 'a', InputOption::VALUE_NONE, 'Publish all stubs'),
            new InputOption('existing', null, InputOption::VALUE_NONE, 'Publish and overwrite only the files that have already been published'),
            new InputOption('force', null, InputOption::VALUE_NONE, 'Overwrite any existing files'),
            new InputOption('list', 'l', InputOption::VALUE_NONE, 'List all available stubs'),
        ];
    }

    protected function getStubChoices()
    {
        return [
            ...app('ddd')->stubs()->dddStubs(),
            ...app('ddd')->stubs()->frameworkStubs(),
        ];
    }

    protected function resolveSelectedStubs(array $names = [])
    {
        $stubs = $this->getStubChoices();

        if ($names) {
            [$startsWith, $exactNames] = collect($names)
                ->partition(fn ($name) => str($name)->endsWith(['*', '.']));

            $startsWith = $startsWith->map(
                fn ($name) => str($name)
                    ->replaceEnd('*', '.')
                    ->replaceEnd('.', '')
            );

            return collect($stubs)
                ->filter(function ($stub, $path) use ($startsWith, $exactNames) {
                    $stubWithoutExtension = str($stub)->replaceEnd('.stub', '');

                    return $exactNames->contains($stub)
                        || $exactNames->contains($stubWithoutExtension)
                        || str($stub)->startsWith($startsWith);
                })
                ->all();
        }

        $selected = multisearch(
            label: 'Which stub should be published?',
            placeholder: 'Search for a stub...',
            options: fn (string $value) => strlen($value) > 0
                ? collect($stubs)->filter(fn ($stub, $path) => str($stub)->contains($value))->all()
                : $stubs,
            required: true
        );

        // multisearch answers with the option keys, which are the stub paths.
        return collect($stubs)
            ->filter(fn ($stub, $path) => in_array($path, $selected, true))
            ->all();
    }

    public function handle(): int
    {
        $option = match (true) {
            $this->option('list') => 'list',
            $this->option('all') => 'all',
            count($this->argument('name')) > 0 => 'named',
            default => select(
                label: 'What do you want to do?',
                options: [
                    'some' => 'Choose stubs to publish',
                    'all' => 'Publish all stubs',
                ],
                required: true,
                default: 'some'
            )
        };

        if ($option === 'list') {
            // $this->table(
            //     ['Stub', 'Path'],
            //     collect($this->getStubChoices())->map(
            //         fn($stub, $path) => [
            //             $stub,
            //             Str::after($path, $this->laravel->basePath())
            //         ]
            //     )
            // );

            table(
                headers: ['Stub', 'Source'],
                rows: collect($this->getStubChoices())->map(
                    fn ($stub, $path) => [
                        Str::replaceLast('.stub', '', $stub),
                        str($path)->startsWith(DDD::packagePath())
                            ? 'ddd'
                            : 'laravel',
                    ]
                )
            );

            return self::SUCCESS;
        }

        $stubs = $option === 'all'
            ? $this->getStubChoices()
            : $this->resolveSelectedStubs($this->argument('name'));

        if (empty($stubs)) {
            $this->warn('No matching stubs found.');

            return self::INVALID;
        }

        File::ensureDirectoryExists($stubsPath = $this->laravel->basePath('stubs/ddd'));

        $this->laravel['events']->dispatch($event = new PublishingStubs($stubs));

        foreach ($event->stubs as $from => $to) {
            $to = $stubsPath.DIRECTORY_SEPARATOR.ltrim($to, DIRECTORY_SEPARATOR);

            $relativePath = Str::after($to, $this->laravel->basePath());

            $this->info("Publishing {$relativePath}");

            if ((! $this->option('existing') && (! file_exists($to) || $this->option('force')))
                || ($this->option('existing') && file_exists($to))
            ) {
                file_put_contents($to, file_get_contents($from));
            }
        }

        $this->components->info('Stubs published successfully.');

        return self::SUCCESS;
    }
}
