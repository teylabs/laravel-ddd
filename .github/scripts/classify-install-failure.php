<?php

/*
 * Classify a composer install/update failure.
 *
 * A supported framework release that every consumer is currently forbidden to
 * install is a gap in what CI can cover, not a defect in this package. Those
 * runs are reported as coverage unavailable and do not block. Everything else —
 * a solver conflict, a missing extension, a network failure, a broken script,
 * an install that unexpectedly succeeded and then failed later — must still
 * block, or the workflow becomes a rubber stamp.
 *
 * The classification is deliberately one-sided: anything not positively proven
 * to be advisory-only is treated as a real failure. Being wrong in that
 * direction costs a red build that someone reads. Being wrong the other way
 * hides a genuine incompatibility behind a reassuring label, which is the exact
 * failure mode this whole workflow exists to prevent.
 *
 * Usage:
 *   php classify-install-failure.php <composer output file> <composer exit code>
 *   php classify-install-failure.php --self-test
 *
 * Writes one of these tokens to stdout:
 *   success             - composer exited 0
 *   advisory-blocked    - solver failure, every problem explained by advisories
 *   unexpected-failure  - anything else
 *
 * The reason is written to stderr. The exit code is 0 for a successful
 * classification and 2 for a usage error, so the caller decides what each
 * classification means for the job.
 *
 * IMPORTANT: pass composer's own captured output, not the whole job log. A job
 * log also contains the workflow's own echoed script text, which mentions
 * advisories and solver conflicts in prose and would poison the matching.
 */

const CLASSIFY_SUCCESS = 'success';
const CLASSIFY_ADVISORY = 'advisory-blocked';
const CLASSIFY_UNEXPECTED = 'unexpected-failure';

// The solver's own header. Without it the failure did not come from dependency
// resolution at all, so it can never be advisory-blocked.
const SOLVER_HEADER = 'Your requirements could not be resolved to an installable set of packages.';

// The advisory explanation, which must be accompanied by at least one advisory
// identifier. Matching the phrase alone would accept composer's generic hint
// text ("To ignore the advisories, ...") that appears even in unrelated output.
const ADVISORY_PHRASE = 'because they are affected by security advisories';

/**
 * Reasons that are definitely not "this release is embargoed": if any of these
 * appear in the problem section, something else is also wrong and the run has
 * to block. Kept broad on purpose — a false "unexpected" is a visible red
 * build, a false "advisory" is a silent coverage lie.
 */
const DISQUALIFYING_MARKERS = [
    'does not satisfy that requirement',
    'your php version',
    'requires php ',
    'requires ext-',
    'conflicts with',
    'could not be found in any version',
    'no matching package found',
    'it is not installable',
    'cannot be installed',
    'is not present in lock file',
    'does not match',
    'has higher repository priority',
];

/**
 * Failures that happen outside the solver entirely. If any of these appear the
 * run blocks regardless of what else the output says.
 */
const NON_SOLVER_MARKERS = [
    'returned with error code',
    'php fatal error',
    'could not be downloaded',
    'curl error',
    'failed to download',
    'the following exception is caused by a lack of memory',
    'allowed memory size of',
    'proc_open(): fork failed',
];

function classifyInstallOutput(string $output, int $exitCode): array
{
    if ($exitCode === 0) {
        return [CLASSIFY_SUCCESS, 'composer exited 0'];
    }

    $haystack = strtolower($output);

    foreach (NON_SOLVER_MARKERS as $marker) {
        if (str_contains($haystack, $marker)) {
            return [CLASSIFY_UNEXPECTED, "output contains a non-solver failure marker: \"{$marker}\""];
        }
    }

    if (! str_contains($output, SOLVER_HEADER)) {
        return [CLASSIFY_UNEXPECTED, 'composer failed without the dependency-resolution header, so this is not a solver failure'];
    }

    // Everything from the header onwards; composer repeats the problem list
    // afterwards, which is harmless because both copies are checked the same way.
    $problemSection = substr($output, strpos($output, SOLVER_HEADER));

    foreach (DISQUALIFYING_MARKERS as $marker) {
        if (str_contains(strtolower($problemSection), $marker)) {
            return [CLASSIFY_UNEXPECTED, "the solver reported a problem that is not an advisory block: \"{$marker}\""];
        }
    }

    $blocks = splitProblemBlocks($problemSection);

    if ($blocks === []) {
        return [CLASSIFY_UNEXPECTED, 'the solver failed but reported no parseable problem block'];
    }

    foreach ($blocks as $index => $block) {
        $bullets = bulletsOf($block);

        if ($bullets === []) {
            return [CLASSIFY_UNEXPECTED, sprintf('problem %d has no explanation lines to classify', $index + 1)];
        }

        $hasAdvisory = false;

        foreach ($bullets as $bullet) {
            if (isAdvisoryBullet($bullet)) {
                $hasAdvisory = true;

                continue;
            }

            // A "satisfiable by" line only enumerates candidates on the way to
            // the real explanation; it states no failure of its own.
            if (isEnumerationBullet($bullet)) {
                continue;
            }

            return [CLASSIFY_UNEXPECTED, sprintf(
                'problem %d contains an explanation that is not an advisory block: %s',
                $index + 1,
                trim(substr($bullet, 0, 160)),
            )];
        }

        if (! $hasAdvisory) {
            return [CLASSIFY_UNEXPECTED, sprintf('problem %d is not explained by a security advisory', $index + 1)];
        }
    }

    return [CLASSIFY_ADVISORY, sprintf('all %d solver problem(s) are explained by security advisories', count($blocks))];
}

function splitProblemBlocks(string $section): array
{
    $parts = preg_split('/^\s*Problem \d+\s*$/m', $section);

    if ($parts === false || count($parts) < 2) {
        return [];
    }

    // The first part is the preamble before "Problem 1".
    array_shift($parts);

    return array_values(array_filter(array_map('trim', $parts), fn (string $part) => $part !== ''));
}

function bulletsOf(string $block): array
{
    $bullets = [];

    foreach (preg_split('/\R/', $block) ?: [] as $line) {
        $line = rtrim($line);

        if (preg_match('/^\s*-\s+(.*)$/', $line, $matches) === 1) {
            $bullets[] = $matches[1];
        }
    }

    return $bullets;
}

function isAdvisoryBullet(string $bullet): bool
{
    if (! str_contains(strtolower($bullet), ADVISORY_PHRASE)) {
        return false;
    }

    // Require at least one concrete advisory identifier. Composer prints these
    // as PKSA-xxxx-xxxx-xxxx or CVE-YYYY-NNNN.
    return preg_match('/\b(PKSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}|CVE-\d{4}-\d{4,})\b/i', $bullet) === 1;
}

function isEnumerationBullet(string $bullet): bool
{
    return str_contains($bullet, '-> satisfiable by');
}

// ---------------------------------------------------------------------------

function runSelfTest(): int
{
    $fixtures = __DIR__.'/fixtures';

    $cases = [
        // Captured from the real failing jobs of run 35263443349 on PR #120.
        ['floor-advisory-only.log', 2, CLASSIFY_ADVISORY],
        ['released-advisory-multi-problem.log', 2, CLASSIFY_ADVISORY],
        // Captured from the real dev-canary job of the same run: composer
        // resolved fine and then a post-autoload-dump script died.
        ['canary-script-fatal.log', 255, CLASSIFY_UNEXPECTED],
        ['mixed-advisory-and-conflict.log', 2, CLASSIFY_UNEXPECTED],
        ['php-requirement.log', 2, CLASSIFY_UNEXPECTED],
        ['network-failure.log', 1, CLASSIFY_UNEXPECTED],
        ['successful-install.log', 0, CLASSIFY_SUCCESS],
        // A success exit code always wins, even over scary-looking output: the
        // caller only classifies when composer actually failed.
        ['floor-advisory-only.log', 0, CLASSIFY_SUCCESS],
    ];

    $failures = 0;

    foreach ($cases as [$file, $exitCode, $expected]) {
        $path = $fixtures.'/'.$file;

        if (! is_file($path)) {
            fwrite(STDERR, "MISSING FIXTURE {$file}\n");
            $failures++;

            continue;
        }

        [$actual, $reason] = classifyInstallOutput(file_get_contents($path), $exitCode);

        if ($actual === $expected) {
            printf("  ok   %-40s exit=%-3d => %s\n", $file, $exitCode, $actual);

            continue;
        }

        printf("  FAIL %-40s exit=%-3d => %s (expected %s; %s)\n", $file, $exitCode, $actual, $expected, $reason);
        $failures++;
    }

    // Guard the identifier requirement directly: advisory prose with no advisory
    // id must never be accepted.
    $proseOnly = SOLVER_HEADER."\n\n  Problem 1\n    - Root composer.json requires laravel/framework 11.44.0, found laravel/framework[v11.44.0] but these were not loaded, because they are affected by security advisories. To ignore the advisories, add their IDs to the \"policy.advisories.ignore-id\" config.\n";
    [$actual] = classifyInstallOutput($proseOnly, 2);

    if ($actual === CLASSIFY_UNEXPECTED) {
        printf("  ok   %-40s exit=%-3d => %s\n", '(advisory prose without an id)', 2, $actual);
    } else {
        printf("  FAIL %-40s exit=%-3d => %s (expected %s)\n", '(advisory prose without an id)', 2, $actual, CLASSIFY_UNEXPECTED);
        $failures++;
    }

    if ($failures > 0) {
        fwrite(STDERR, "\n{$failures} classifier self-test case(s) failed.\n");

        return 1;
    }

    echo "\nClassifier self-test passed.\n";

    return 0;
}

// ---------------------------------------------------------------------------

if (($argv[1] ?? null) === '--self-test') {
    exit(runSelfTest());
}

if (($argv[1] ?? null) === null || ($argv[2] ?? null) === null) {
    fwrite(STDERR, "Usage: classify-install-failure.php <composer output file> <composer exit code>\n");
    fwrite(STDERR, "       classify-install-failure.php --self-test\n");
    exit(2);
}

$outputFile = $argv[1];

if (! is_file($outputFile)) {
    // No captured output means nothing can be positively identified, so this
    // must not be treated as an advisory block.
    fwrite(STDERR, "No composer output captured at {$outputFile}; cannot classify.\n");
    echo CLASSIFY_UNEXPECTED."\n";
    exit(0);
}

[$classification, $reason] = classifyInstallOutput(file_get_contents($outputFile), (int) $argv[2]);

fwrite(STDERR, $reason."\n");
echo $classification."\n";
