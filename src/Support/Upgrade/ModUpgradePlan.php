<?php

namespace Tey\LaravelDDD\Support\Upgrade;

use Tey\Mod\Layout\Layout;

/**
 * What `ddd:upgrade --to=mod` changes, before anything is written: the files
 * it writes (with their current and new contents), the files it removes, the
 * calls that extend mod's ddd layout, and what it reports.
 */
final class ModUpgradePlan
{
    /** @var array<string, array{before: ?string, after: string}> relative path => contents */
    public array $writes = [];

    /** @var list<string> relative paths */
    public array $deletes = [];

    /** @var list<array{0: 'mounts'|'generates'|'path', 1: array<array-key, mixed>}> Mod::layout('ddd') calls: method and arguments (named for generates) */
    public array $layoutCalls = [];

    /** @var list<string> */
    public array $converted = [];

    /** @var list<string> */
    public array $attention = [];

    /** @var list<string> */
    public array $blockers = [];

    /** @var array{file_types: array<string, string|false>, factories: bool, policies: bool} mod.discovery settings */
    public array $discovery = ['file_types' => [], 'factories' => true, 'policies' => true];

    /** @var list<string> provider classes to add to bootstrap/providers.php */
    public array $providers = [];

    /** @var list<list<string>> */
    public array $composer = [['composer', 'require', 'tey/mod:^0.7'], ['composer', 'remove', 'tey/laravel-ddd']];

    /** @var list<string> */
    public array $nextSteps = [];

    public function write(string $relative, ?string $before, string $after): void
    {
        if ($before !== $after) {
            $this->writes[$relative] = ['before' => $before, 'after' => $after];
        }
    }

    public function blocked(): bool
    {
        return $this->blockers !== [];
    }

    /**
     * Apply the layout calls to a layout, as the generated provider does to Mod::layout('ddd').
     */
    public function applyLayout(Layout $layout): Layout
    {
        foreach ($this->layoutCalls as [$method, $arguments]) {
            $layout->{$method}(...$arguments);
        }

        return $layout;
    }

    /**
     * The layout calls as PHP: `->mounts(...)` and `->generates('id', in: ...)`, one per line.
     */
    public function renderLayoutCalls(string $indent): string
    {
        $lines = [];

        foreach ($this->layoutCalls as [$method, $arguments]) {
            $rendered = [];

            foreach ($arguments as $name => $value) {
                $rendered[] = (is_string($name) ? $name.': ' : '').self::export($value);
            }

            $lines[] = $indent.'->'.$method.'('.implode(', ', $rendered).')';
        }

        return implode("\n", $lines);
    }

    /**
     * A line diff of two contents: unchanged lines near a change with a leading space, removed with -, added with +.
     *
     * @return list<string>
     */
    public static function diff(?string $before, string $after, int $context = 2): array
    {
        $old = $before === null ? [] : preg_split("/\r\n|\n|\r/", rtrim($before, "\r\n"));
        $new = preg_split("/\r\n|\n|\r/", rtrim($after, "\r\n"));
        $n = count($old);
        $m = count($new);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $old[$i] === $new[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $ops = [];

        for ($i = 0, $j = 0; $i < $n || $j < $m;) {
            if ($i < $n && $j < $m && $old[$i] === $new[$j]) {
                $ops[] = [' ', $old[$i++]];
                $j++;
            } elseif ($j < $m && ($i >= $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $ops[] = ['+', $new[$j++]];
            } else {
                $ops[] = ['-', $old[$i++]];
            }
        }

        $lines = [];
        $skipped = false;

        foreach ($ops as $index => [$sign, $line]) {
            $near = false;

            for ($k = max(0, $index - $context); $k <= min(count($ops) - 1, $index + $context); $k++) {
                $near = $near || $ops[$k][0] !== ' ';
            }

            if ($near) {
                $lines[] = $sign.$line;
                $skipped = false;
            } elseif (! $skipped) {
                $lines[] = '…';
                $skipped = true;
            }
        }

        return $lines;
    }

    public static function export(mixed $value): string
    {
        if (is_array($value)) {
            $list = array_is_list($value);
            $items = [];

            foreach ($value as $key => $item) {
                $items[] = ($list ? '' : self::export($key).' => ').self::export($item);
            }

            return '['.implode(', ', $items).']';
        }

        return $value === null ? 'null' : var_export($value, true);
    }
}
