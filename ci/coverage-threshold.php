<?php

declare(strict_types=1);

/**
 * Enforces a minimum line-coverage threshold.
 *
 * PHPUnit has no built-in "fail under N%" option, so this script reads the
 * Clover XML emitted by `phpunit --coverage-clover` and exits non-zero when
 * the covered-statement ratio falls below the threshold. CI runs it right
 * after the coverage test run; it can also be used locally via
 * `composer coverage:check`.
 *
 * Usage: php ci/coverage-threshold.php [clover.xml] [threshold]
 *        threshold defaults to 95 (percent), or the COVERAGE_THRESHOLD
 *        environment variable when set.
 */

$cloverFile = $argv[1] ?? __DIR__ . '/../coverage-report/coverage.xml';
$threshold = isset($argv[2])
    ? (float) $argv[2]
    : (float) (getenv('COVERAGE_THRESHOLD') ?: 95.0);

if (!is_file($cloverFile)) {
    fwrite(STDERR, "Clover file not found: {$cloverFile}\n");
    fwrite(STDERR, "Run: php vendor/bin/phpunit --coverage-clover {$cloverFile}\n");
    exit(1);
}

if ($threshold < 0 || $threshold > 100) {
    fwrite(STDERR, "Threshold must be between 0 and 100, got {$threshold}\n");
    exit(1);
}

$xml = simplexml_load_file($cloverFile);
if ($xml === false) {
    fwrite(STDERR, "Could not parse Clover XML: {$cloverFile}\n");
    exit(1);
}

$metrics = $xml->xpath('//project/metrics');
if ($metrics === false || count($metrics) === 0) {
    fwrite(STDERR, "No project metrics found in Clover XML: {$cloverFile}\n");
    exit(1);
}

$statements = (int) $metrics[0]['statements'];
$covered = (int) $metrics[0]['coveredstatements'];

if ($statements === 0) {
    fwrite(STDERR, "Clover XML reports zero statements — is the <source> config correct?\n");
    exit(1);
}

$percentage = $covered / $statements * 100;

printf(
    "Coverage: %.2f%% (%d/%d statements) — threshold %.2f%%\n",
    $percentage,
    $covered,
    $statements,
    $threshold
);

if ($percentage < $threshold) {
    fwrite(STDERR, sprintf(
        "FAIL: coverage %.2f%% is below the %.2f%% threshold (%d statements uncovered).\n",
        $percentage,
        $threshold,
        $statements - $covered
    ));
    exit(1);
}

echo "PASS\n";
