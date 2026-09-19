<?php

declare(strict_types=1);

// php bin/benchmark.php --representation=grid|cells|lines|rules|all [--split=dev|test|all]
//                       [--limit=N] [--shuffle-seed=N] [--repeat=N] [--concurrency=10] [--prompt=v1]
//
// Always resumable: rows already stored for the same configuration are skipped.

use JevTtt\Benchmark\Runner;
use JevTtt\Benchmark\Split;
use JevTtt\Benchmark\Store;
use JevTtt\Env;
use JevTtt\JevClient;
use JevTtt\QuestionBuilder;
use JevTtt\Solver;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['representation:', 'split::', 'limit::', 'shuffle-seed::', 'repeat::', 'concurrency::', 'prompt::']);

$representation = $options['representation'] ?? null;
$representations = $representation === 'all' ? QuestionBuilder::REPRESENTATIONS : [$representation];
if (array_diff($representations, QuestionBuilder::REPRESENTATIONS) !== []) {
    fwrite(STDERR, "--representation must be one of: " . implode(', ', QuestionBuilder::REPRESENTATIONS) . ", all\n");
    exit(2);
}

$split = $options['split'] ?? 'all';
if (!in_array($split, ['dev', 'test', 'all'], true)) {
    fwrite(STDERR, "--split must be dev, test or all\n");
    exit(2);
}

$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : null;
$shuffleSeed = isset($options['shuffle-seed']) ? (int) $options['shuffle-seed'] : null;
$repeat = (int) ($options['repeat'] ?? 0);
$concurrency = max(1, min(20, (int) ($options['concurrency'] ?? 10)));

$prompt = $options['prompt'] ?? QuestionBuilder::PROMPT_VERSION;
if (!in_array($prompt, QuestionBuilder::VERSIONS, true)) {
    fwrite(STDERR, "--prompt must be one of: " . implode(', ', QuestionBuilder::VERSIONS) . "\n");
    exit(2);
}

$solver = new Solver();
$runner = new Runner(
    $solver,
    new Split($solver),
    new QuestionBuilder(),
    new JevClient(Env::get('TYPESAFE_API_KEY')),
    new Store(dirname(__DIR__) . '/storage/results.sqlite'),
);

$failed = 0;
foreach ($representations as $name) {
    printf("%s (prompt %s, %s, split %s)\n", $name, $prompt, JevClient::MODEL, $split);

    $summary = $runner->run(
        $name,
        $split,
        $limit,
        $shuffleSeed,
        $repeat,
        $concurrency,
        static function (int $settled, int $total): void {
            if ($settled % 100 === 0 || $settled === $total) {
                printf("  %d / %d\n", $settled, $total);
            }
        },
        $prompt,
    );

    printf(
        "  selected %d, already stored %d, saved %d, failed %d, %d input tokens ($%.4f), %.1f s\n",
        $summary['selected'],
        $summary['skipped'],
        $summary['saved'],
        $summary['failed'],
        $summary['input_tokens'],
        $summary['input_tokens'] * JevClient::USD_PER_INPUT_TOKEN,
        $summary['seconds'],
    );
    foreach (array_slice($summary['errors'], 0, 10) as $error) {
        printf("  ! %s\n", $error);
    }
    $failed += $summary['failed'];
}

exit($failed === 0 ? 0 : 1);
