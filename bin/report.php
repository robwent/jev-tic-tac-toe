<?php

declare(strict_types=1);

// php bin/report.php [--prompt=v1]
// Reads storage/results.sqlite, writes public/data/report.json.

use JevTtt\Benchmark\Extras;
use JevTtt\Benchmark\Report;
use JevTtt\Benchmark\Split;
use JevTtt\Benchmark\Store;
use JevTtt\QuestionBuilder;
use JevTtt\Solver;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['prompt::']);
$root = dirname(__DIR__);

$solver = new Solver();
$store = new Store($root . '/storage/results.sqlite');

// The headline section is always v1, the untuned baseline. Everything that came after it sits under "extras".
$report = (new Report($solver, $store))->build($options['prompt'] ?? QuestionBuilder::PROMPT_VERSION);
$report['extras'] = (new Extras($solver, $store, new Split($solver)))->all();

if (!is_dir($root . '/public/data')) {
    mkdir($root . '/public/data', 0775, true);
}
file_put_contents(
    $root . '/public/data/report.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);

foreach ($report['representations'] as $name => $entry) {
    $m = $entry['splits']['test'] ?? $entry['splits']['all'];
    printf(
        "%-6s rows %4d  test decisions %4d  optimal %.1f%% (random %.1f%%)  symmetry %.1f%%\n",
        $name,
        $entry['rows'],
        $m['decisions'],
        100 * $m['optimal_rate']['decisions'],
        100 * $m['optimal_rate']['random_baseline_decisions'],
        100 * ($entry['symmetry']['mean_agreement_with_modal_pick'] ?? 0),
    );
}
echo "Wrote public/data/report.json\n";
