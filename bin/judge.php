<?php

declare(strict_types=1);

// php bin/judge.php [--representation=lines] [--limit=N]
// Asks Jev four static yes or no questions about every reachable position,
// finished games included, and stores the answers. Resumable.

use JevTtt\Benchmark\Store;
use JevTtt\Env;
use JevTtt\JevClient;
use JevTtt\JevResponse;
use JevTtt\QuestionBuilder;
use JevTtt\Solver;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['representation::', 'limit::']);
$representation = $options['representation'] ?? 'lines';

$builder = new QuestionBuilder();
$store = new Store(dirname(__DIR__) . '/storage/results.sqlite');
$client = new JevClient(Env::get('TYPESAFE_API_KEY'));

$done = $store->judgements($representation, QuestionBuilder::JUDGEMENT_VERSION, JevClient::MODEL);
$boards = [];
$requests = [];
foreach ((new Solver())->positions() as $board) {
    if (isset($done[$board->cells])) {
        continue;
    }
    if (isset($options['limit']) && count($boards) >= (int) $options['limit']) {
        break;
    }
    $boards[$board->cells] = $board;
    $requests[$board->cells] = $builder->buildJudgement($board, $representation);
}

printf("%s judgements (%s): %d to ask, %d already stored\n", $representation, QuestionBuilder::JUDGEMENT_VERSION, count($requests), count($done));

$saved = $failed = $tokens = $settled = 0;
$started = microtime(true);
$client->askMany($requests, function (int|string $cells, JevResponse $response) use (&$saved, &$failed, &$tokens, &$settled, $boards, $requests, $store, $representation): void {
    $body = $response->json();
    $nouls = [];
    foreach (QuestionBuilder::JUDGEMENTS as $id) {
        if (is_numeric($body['answers'][$id]['noul'] ?? null)) {
            $nouls[$id] = (float) $body['answers'][$id]['noul'];
        }
    }

    if ($response->ok() && count($nouls) === 4) {
        $store->saveJudgement($boards[$cells], $representation, QuestionBuilder::JUDGEMENT_VERSION, (string) $body['model'], $requests[$cells], $response, $nouls, $body['usage']['input_tokens'] ?? null);
        $saved++;
        $tokens += (int) ($body['usage']['input_tokens'] ?? 0);
    } else {
        $failed++;
        if ($failed <= 5) {
            printf("  ! %s: HTTP %d %s\n", $cells, $response->status, substr($response->error ?? $response->body, 0, 160));
        }
    }

    if (++$settled % 500 === 0) {
        printf("  %d / %d\n", $settled, count($requests));
    }
});

printf("saved %d, failed %d, %d input tokens ($%.4f), %.1f s\n", $saved, $failed, $tokens, $tokens * JevClient::USD_PER_INPUT_TOKEN, microtime(true) - $started);
exit($failed === 0 ? 0 : 1);
