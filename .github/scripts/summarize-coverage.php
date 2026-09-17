<?php

/*
 * Aggregate the per-matrix coverage statuses into one summary table and decide
 * whether the run passes.
 *
 * Statuses come from artifacts rather than job outputs because every leg of a
 * matrix writes to the same `outputs` map — the last leg to finish would be the
 * only one anybody could see.
 *
 * The table exists to keep one distinction sharp: "tested" means the suite
 * actually ran against that framework version, "coverage unavailable" means
 * nothing ran at all. Collapsing those two into a single green tick is the
 * dishonesty this workflow was built to remove, so they are never merged.
 *
 * Usage: php summarize-coverage.php <directory of status json files>
 *   --self-test  run the decision-table checks instead
 */

const STATUS_TESTED = 'tested';
const STATUS_UNAVAILABLE = 'coverage-unavailable';
const STATUS_INSTALL_OK = 'install-ok';
const STATUS_FAILED = 'failed';

/**
 * @param  array<int, array<string, mixed>>  $statuses
 * @return array{rows: array<int, array{label: string, status: string, note: string}>, blocking: bool, tested: int, unavailable: int}
 */
function summarizeCoverage(array $statuses): array
{
    $rows = [];
    $blocking = false;
    $tested = 0;
    $unavailable = 0;

    usort($statuses, fn (array $a, array $b) => ($a['key'] ?? '') <=> ($b['key'] ?? ''));

    foreach ($statuses as $status) {
        $label = (string) ($status['label'] ?? $status['key'] ?? 'unknown');
        $state = (string) ($status['status'] ?? '');
        $reason = (string) ($status['reason'] ?? '');

        switch ($state) {
            case STATUS_TESTED:
                $rows[] = ['label' => $label, 'status' => '✅ tested', 'note' => ''];
                $tested++;
                break;

            case STATUS_UNAVAILABLE:
                $rows[] = [
                    'label' => $label,
                    'status' => '⚠️ coverage unavailable',
                    'note' => 'not tested — every candidate release is blocked by a security advisory',
                ];
                $unavailable++;
                break;

            case STATUS_INSTALL_OK:
                // Installed, then never promoted: the tests did not finish
                // successfully. The job itself is already red; say so here too
                // rather than implying it was covered.
                $rows[] = [
                    'label' => $label,
                    'status' => '❌ failed after install',
                    'note' => 'dependencies installed but the run did not complete successfully',
                ];
                $blocking = true;
                break;

            case STATUS_FAILED:
                $rows[] = ['label' => $label, 'status' => '❌ failed', 'note' => $reason];
                $blocking = true;
                break;

            default:
                $rows[] = [
                    'label' => $label,
                    'status' => '❌ unrecognized',
                    'note' => 'unreadable coverage status; treated as a failure',
                ];
                $blocking = true;
                break;
        }
    }

    return ['rows' => $rows, 'blocking' => $blocking, 'tested' => $tested, 'unavailable' => $unavailable];
}

/**
 * @return array<int, array<string, mixed>>
 */
function readStatuses(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $statuses = [];

    foreach (glob(rtrim($directory, '/').'/*.json') ?: [] as $file) {
        $decoded = json_decode((string) file_get_contents($file), true);

        if (is_array($decoded)) {
            $statuses[] = $decoded;

            continue;
        }

        $statuses[] = ['key' => basename($file), 'label' => basename($file), 'status' => 'unreadable'];
    }

    return $statuses;
}

function runSelfTest(): int
{
    $cases = [
        'all tested passes' => [
            [['key' => 'a', 'label' => 'A', 'status' => STATUS_TESTED]],
            false,
        ],
        'advisory-only coverage gap does not block' => [
            [
                ['key' => 'a', 'label' => 'A', 'status' => STATUS_TESTED],
                ['key' => 'b', 'label' => 'B', 'status' => STATUS_UNAVAILABLE],
            ],
            false,
        ],
        'every leg unavailable still does not block' => [
            [
                ['key' => 'a', 'label' => 'A', 'status' => STATUS_UNAVAILABLE],
                ['key' => 'b', 'label' => 'B', 'status' => STATUS_UNAVAILABLE],
            ],
            false,
        ],
        'an unexpected install failure blocks' => [
            [
                ['key' => 'a', 'label' => 'A', 'status' => STATUS_UNAVAILABLE],
                ['key' => 'b', 'label' => 'B', 'status' => STATUS_FAILED, 'reason' => 'solver conflict'],
            ],
            true,
        ],
        'installed but tests never passed blocks' => [
            [['key' => 'a', 'label' => 'A', 'status' => STATUS_INSTALL_OK]],
            true,
        ],
        'an unreadable status blocks' => [
            [['key' => 'a', 'label' => 'A', 'status' => 'nonsense']],
            true,
        ],
    ];

    $failures = 0;

    foreach ($cases as $name => [$statuses, $expectedBlocking]) {
        $result = summarizeCoverage($statuses);

        if ($result['blocking'] === $expectedBlocking) {
            printf("  ok   %-45s blocking=%s\n", $name, var_export($result['blocking'], true));

            continue;
        }

        printf("  FAIL %-45s blocking=%s (expected %s)\n", $name, var_export($result['blocking'], true), var_export($expectedBlocking, true));
        $failures++;
    }

    // An empty set means nothing reported in — that cannot be read as success.
    $empty = summarizeCoverage([]);

    if ($empty['tested'] === 0 && $empty['rows'] === []) {
        printf("  ok   %-45s (caller must treat as failure)\n", 'no statuses at all');
    } else {
        printf("  FAIL %-45s\n", 'no statuses at all');
        $failures++;
    }

    if ($failures > 0) {
        fwrite(STDERR, "\n{$failures} summary self-test case(s) failed.\n");

        return 1;
    }

    echo "\nCoverage summary self-test passed.\n";

    return 0;
}

// ---------------------------------------------------------------------------

if (($argv[1] ?? null) === '--self-test') {
    exit(runSelfTest());
}

$directory = $argv[1] ?? 'coverage-status';
$statuses = readStatuses($directory);

$summaryFile = getenv('GITHUB_STEP_SUMMARY') ?: 'php://stdout';

if ($statuses === []) {
    file_put_contents($summaryFile, "## Compatibility coverage\n\n> **No coverage statuses were reported.** Every job failed before recording one, or the artifacts did not upload.\n\n", FILE_APPEND);
    fwrite(STDOUT, "::error::No coverage statuses were reported; the run cannot be considered covered.\n");
    exit(1);
}

$result = summarizeCoverage($statuses);

$lines = [];
$lines[] = '## Compatibility coverage';
$lines[] = '';
$lines[] = sprintf(
    '%d tested, %d coverage unavailable, %d total.',
    $result['tested'],
    $result['unavailable'],
    count($result['rows']),
);
$lines[] = '';
$lines[] = '| Job | Coverage | Notes |';
$lines[] = '| --- | --- | --- |';

foreach ($result['rows'] as $row) {
    $lines[] = sprintf('| %s | %s | %s |', $row['label'], $row['status'], $row['note']);
}

$lines[] = '';

if ($result['unavailable'] > 0) {
    $lines[] = '> Rows marked *coverage unavailable* were **not tested**. Every release matching them is currently blocked by a security advisory, so nothing could be installed to test against. They are non-blocking because no consumer can install those releases either — they are not passes.';
    $lines[] = '';
}

file_put_contents($summaryFile, implode("\n", $lines)."\n", FILE_APPEND);

echo implode("\n", $lines)."\n";

if ($result['blocking']) {
    fwrite(STDOUT, "::error::Compatibility run has real failures; see the coverage table.\n");
    exit(1);
}

if ($result['tested'] === 0) {
    fwrite(STDOUT, "::warning::Nothing was tested in this run - every job reported coverage unavailable.\n");
}
