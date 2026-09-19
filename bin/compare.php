<?php

declare(strict_types=1);

// php bin/compare.php [--split=dev]
// Prompt versions side by side on one split. Tune on dev, report on test.

use JevTtt\Benchmark\Report;
use JevTtt\Benchmark\Store;
use JevTtt\QuestionBuilder;
use JevTtt\Solver;

require dirname(__DIR__) . '/vendor/autoload.php';

$split = getopt('', ['split::'])['split'] ?? 'dev';
$report = new Report(new Solver(), new Store(dirname(__DIR__) . '/storage/results.sqlite'));

printf("split: %s\n%-6s %-16s %5s %8s %8s %8s %8s %8s %8s\n", $split, 'rep', 'prompt', 'dec', 'optimal', 'random', 'opt mass', 'miss win', 'miss blk', 'fork+oth');
foreach (QuestionBuilder::VERSIONS as $version) {
    $built[$version] = $report->build($version)['representations'];
}
foreach (QuestionBuilder::REPRESENTATIONS as $representation) {
    foreach (QuestionBuilder::VERSIONS as $version) {
        $m = $built[$version][$representation]['splits'][$split] ?? null;
        if ($m === null) {
            continue;
        }
        $q = $m['quality_on_decisions'];
        $d = $m['decisions'];
        printf(
            "%-6s %-16s %5d %7.1f%% %7.1f%% %7.1f%% %7.1f%% %7.1f%% %7.1f%%\n",
            $representation,
            $version,
            $d,
            100 * $m['optimal_rate']['decisions'],
            100 * $m['optimal_rate']['random_baseline_decisions'],
            100 * $m['optimal_mass_decisions'],
            100 * $q['missed_win'] / $d,
            100 * $q['missed_block'] / $d,
            100 * ($q['allowed_fork'] + $q['other_blunder']) / $d,
        );
    }
    echo "\n";
}
