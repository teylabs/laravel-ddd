<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Finder\Finder;
use Tey\LaravelDDD\Facades\DDD;
use Tey\LaravelDDD\Support\Upgrade\ModUpgrade;
use Tey\LaravelDDD\Support\Upgrade\ModUpgradePlan;

use function Laravel\Prompts\confirm;

class UpgradeCommand extends Command
{
    protected $name = 'ddd:upgrade';

    protected $description = 'Upgrade the package configuration and application code for compatibility with the latest version.';

    const OLD_NAMESPACE = 'Lunarstorm\\LaravelDDD';

    const NEW_NAMESPACE = 'Tey\\LaravelDDD';

    /**
     * @return list<array{0: string, 1: ?string, 2: int, 3: string}>
     */
    protected function getOptions()
    {
        return [
            ['to', null, InputOption::VALUE_REQUIRED, 'Move the application to another package: mod (tey/mod, without Laravel-DDD)'],
            ['force', null, InputOption::VALUE_NONE, 'With --to: write the changes (a dry run otherwise)'],
            ['composer', null, InputOption::VALUE_NONE, 'With --to=mod --force: require tey/mod and remove tey/laravel-ddd through Composer'],
        ];
    }

    public function handle()
    {
        if ($this->option('to') !== null) {
            return $this->handleTarget((string) $this->option('to'));
        }

        if (! file_exists(config_path('ddd.php'))) {
            $this->components->warn('Config file was not published. Nothing to upgrade!');

            return self::SUCCESS;
        }

        $this->handleV3Upgrade();

        $this->handleConfigUpgrade();

        return self::SUCCESS;
    }

    protected function handleTarget(string $target): int
    {
        if ($target !== 'mod') {
            $this->components->error("Unknown upgrade target [{$target}]; the only target is mod.");

            return self::FAILURE;
        }

        $upgrade = ModUpgrade::for($this->laravel);
        $plan = $upgrade->plan();
        $write = (bool) $this->option('force');

        $this->components->info($write ? 'Moving to tey/mod.' : 'Moving to tey/mod (dry run).');
        $this->report($plan);

        if ($write && $plan->blocked()) {
            $this->components->error('Nothing was written: resolve the blockers above first.');

            return self::FAILURE;
        }

        if (! $write) {
            $this->components->warn('Dry run: nothing was written. Run again with --force to write these changes'.($plan->blocked() ? ' once the blockers are resolved.' : '.'));

            return self::SUCCESS;
        }

        $upgrade->apply($plan);
        $this->components->info('Written.');

        if ($this->option('composer')) {
            if (($exit = $upgrade->runComposer($plan)) !== 0) {
                $this->components->error('Composer failed (exit '.$exit.'); run the commands under Next steps yourself.');

                return self::FAILURE;
            }

            $this->components->info('tey/mod is required and tey/laravel-ddd is removed.');
        }

        return self::SUCCESS;
    }

    protected function report(ModUpgradePlan $plan): void
    {
        foreach (['Converted' => $plan->converted, 'Needs attention' => $plan->attention, 'Blockers' => $plan->blockers] as $title => $items) {
            if ($items === []) {
                continue;
            }

            $this->line("<options=bold>{$title}</>");

            foreach ($items as $item) {
                $this->line("  - {$item}");
            }

            $this->newLine();
        }

        foreach ($plan->writes as $relative => ['before' => $before, 'after' => $after]) {
            $this->line('<options=bold>'.$relative.($before === null ? ' (new)' : '').'</>');

            foreach (ModUpgradePlan::diff($before, $after) as $line) {
                $colour = match ($line[0] ?? '') {
                    '+' => 'green',
                    '-' => 'red',
                    default => 'gray',
                };
                $this->line('<fg='.$colour.'>'.$this->escape($line).'</>');
            }

            $this->newLine();
        }

        foreach ($plan->deletes as $relative) {
            $this->line("<options=bold>{$relative}</> (removed)");
        }

        $this->newLine();
        $this->line('<options=bold>Next steps</>');

        foreach ($plan->composer as $command) {
            $this->line('  '.implode(' ', $command).($this->option('composer') ? ' (with --force, run now)' : ''));
        }

        foreach ($plan->nextSteps as $step) {
            $this->line('  '.$step);
        }

        $this->newLine();
    }

    private function escape(string $line): string
    {
        return OutputFormatter::escape($line);
    }

    protected function handleV3Upgrade(): void
    {
        $this->components->info('Checking for v3 migration requirements...');

        $this->migrateConfigNamespaces();
        $this->migrateAppNamespaces();
    }

    protected function migrateConfigNamespaces(): void
    {
        $configPath = config_path('ddd.php');
        $contents = file_get_contents($configPath);

        if (! str_contains($contents, static::OLD_NAMESPACE)) {
            return;
        }

        $updated = str_replace(static::OLD_NAMESPACE, static::NEW_NAMESPACE, $contents);
        file_put_contents($configPath, $updated);

        $this->components->twoColumnDetail('Updated namespace references in config/ddd.php', '<fg=green;options=bold>DONE</>');
    }

    protected function migrateAppNamespaces(): void
    {
        $searchPaths = array_filter([
            app_path(),
            base_path('src'),
        ], fn ($path) => is_dir($path));

        if (empty($searchPaths)) {
            return;
        }

        $finder = Finder::create()
            ->in($searchPaths)
            ->name('*.php')
            ->contains(static::OLD_NAMESPACE)
            ->files();

        $files = iterator_to_array($finder);

        if (empty($files)) {
            $this->components->twoColumnDetail('No application files require namespace updates', '<fg=green;options=bold>NONE</>');

            return;
        }

        $this->components->warn(sprintf(
            'Found %d file(s) referencing the old namespace <fg=yellow>%s</>:',
            count($files),
            static::OLD_NAMESPACE,
        ));

        foreach ($files as $file) {
            $this->line('  <fg=gray>'.$file->getRelativePathname().'</>');
        }

        $this->newLine();

        if (! confirm(sprintf(
            'Replace all occurrences of [%s] with [%s]?',
            static::OLD_NAMESPACE,
            static::NEW_NAMESPACE,
        ), default: true)) {
            $this->components->warn('Skipped. You will need to update these files manually.');

            return;
        }

        foreach ($files as $file) {
            $updated = str_replace(
                static::OLD_NAMESPACE,
                static::NEW_NAMESPACE,
                file_get_contents($file->getRealPath()),
            );

            file_put_contents($file->getRealPath(), $updated);

            $this->components->twoColumnDetail($file->getRelativePathname(), '<fg=green;options=bold>UPDATED</>');
        }
    }

    protected function handleConfigUpgrade(): void
    {
        DDD::config()->syncWithLatest()->save();

        $this->components->info('Configuration upgraded successfully.');
    }
}
