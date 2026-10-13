<?php

// Check the default-branch URLs against this checkout, including on stacked PRs
// whose files have not reached main yet. Fragments select sections, not files.
$root = dirname(__DIR__, 2);
$index = $argv[1] ?? $root.'/llms.txt';
$contents = file_get_contents($index);

if ($contents === false) {
    fwrite(STDERR, "Cannot read {$index}.\n");
    exit(1);
}

preg_match_all('/\[[^\]\r\n]+\]\(([^)\s]+)\)/', $contents, $matches);

if ($matches[1] === []) {
    fwrite(STDERR, "No Markdown links found in {$index}.\n");
    exit(1);
}

$prefix = 'https://github.com/teylabs/laravel-ddd/blob/main/';
$errors = [];

foreach ($matches[1] as $url) {
    if (! str_starts_with($url, $prefix)) {
        $errors[] = "Link must use {$prefix}: {$url}";

        continue;
    }

    $path = rawurldecode(explode('#', substr($url, strlen($prefix)), 2)[0]);
    $resolved = realpath($root.'/'.$path);

    if ($resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || ! is_file($resolved)) {
        $errors[] = "Link does not resolve to a file in the checkout: {$url}";
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}

echo 'Checked '.count($matches[1])." llms.txt links against the checkout.\n";
