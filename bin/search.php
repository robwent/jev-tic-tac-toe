<?php

declare(strict_types=1);

// php bin/search.php [--split=dev] [--representation=lines] [--prior=v2-priority]
// 1. How well Jev answers the four static judgements, overall and by kind of line.
// 2. How a code search over those judgements plays, by depth of look-ahead.
//    That second table measures Jev's perception plus our search, not Jev's play.

use JevTtt\Benchmark\Split;
use JevTtt\Benchmark\Store;
use JevTtt\Board;
use JevTtt\JevClient;
use JevTtt\QuestionBuilder;
use JevTtt\SearchPlayer;
use JevTtt\Solver;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['split::', 'representation::', 'prior::']);
$splitName = $options['split'] ?? 'dev';
$representation = $options['representation'] ?? 'lines';
$priorVersion = $options['prior'] ?? 'v2-priority';

$solver = new Solver();
$split = new Split($solver);
$store = new Store(dirname(__DIR__) . '/storage/results.sqlite');
$judgements = $store->judgements($representation, QuestionBuilder::JUDGEMENT_VERSION, JevClient::MODEL);

$kindOf = static fn (string $line): string => match (true) {
    str_starts_with($line, 'row') => 'row',
    str_starts_with($line, 'col') => 'column',
    default => 'diagonal',
};

/** Kinds of line on which $mark has $count marks and the rest empty. */
$kinds = static function (Board $board, string $mark, int $count) use ($kindOf): array {
    $found = [];
    foreach (Board::LINES as $name => $line) {
        $marks = $empty = 0;
        foreach ($line as $i) {
            $marks += (int) ($board->cells[$i] === $mark);
            $empty += (int) ($board->cells[$i] === '-');
        }
        if ($marks === $count && $marks + $empty === 3) {
            $found[$kindOf($name)] = true;
        }
    }

    return array_keys($found);
};

// --- 1. Perception -----------------------------------------------------------
$stats = [];
$add = static function (string $key, bool $truth, float $p) use (&$stats): void {
    $stats[$key] ??= ['n' => 0, 'right' => 0, 'brier' => 0.0];
    $stats[$key]['n']++;
    $stats[$key]['right'] += (int) (($p >= 0.5) === $truth);
    $stats[$key]['brier'] += ($p - (int) $truth) ** 2;
};

foreach ($solver->positions() as $board) {
    $j = $judgements[$board->cells] ?? null;
    if ($j === null || ($splitName !== 'all' && !$board->isTerminal() && $split->of($board) !== $splitName)) {
        continue;
    }
    foreach (['X', 'O'] as $mark) {
        $id = strtolower($mark);
        foreach (['line' => 3, 'threat' => 2] as $question => $count) {
            $found = $kinds($board, $mark, $count);
            $p = $j["{$id}_{$question}"];
            $add("{$question}: all positions", $found !== [], $p);
            if ($found === []) {
                $add("{$question}: truly no", false, $p);
            } elseif (count($found) === 1) {
                $add("{$question}: truly yes, on a {$found[0]}", true, $p);
            } else {
                $add("{$question}: truly yes, several kinds", true, $p);
            }
        }
    }
}

printf("Perception, %s, judgement wording %s, split %s (finished games always included)\n", $representation, QuestionBuilder::JUDGEMENT_VERSION, $splitName);
printf("  %-40s %7s %9s %7s\n", '', 'n', 'right', 'Brier');
ksort($stats);
foreach ($stats as $key => $s) {
    printf("  %-40s %7d %8.1f%% %7.3f\n", $key, $s['n'], 100 * $s['right'] / $s['n'], $s['brier'] / $s['n']);
}

// --- 2. Search over Jev's judgements -----------------------------------------
$priors = [];
foreach ($store->rows(['representation' => $representation, 'prompt_version' => $priorVersion, 'option_order' => 'reading', 'repeat' => 0]) as $row) {
    $priors[$row['position']] = ['move' => (int) $row['move'], 'p' => array_map(floatval(...), json_decode($row['move_probabilities'], true))];
}

$player = new SearchPlayer($judgements);
$depths = [0, 1, 2, 3, 4, 9];
$table = [];
foreach ($solver->nonTerminalPositions() as $board) {
    if (($splitName !== 'all' && $split->of($board) !== $splitName) || !isset($priors[$board->cells])) {
        continue;
    }
    if (!$solver->evaluate($board)->isDecision()) {
        continue;
    }
    foreach ($depths as $depth) {
        $move = $depth === 0 ? $priors[$board->cells]['move'] : $player->choose($board, $depth, $priors[$board->cells]['p']);
        $quality = $solver->classify($board, $move);
        $table[$depth] ??= ['n' => 0];
        $table[$depth]['n']++;
        $table[$depth][$quality->value] = ($table[$depth][$quality->value] ?? 0) + 1;
    }
}

printf("\nJev's perception + code look-ahead, ties broken by Jev's %s move probabilities, split %s, decision positions\n", $priorVersion, $splitName);
printf("  %-22s %5s %8s %9s %9s %9s %9s\n", 'look-ahead', 'n', 'optimal', 'miss win', 'miss blk', 'fork', 'other');
foreach ($table as $depth => $t) {
    $pct = static fn (string $k): string => sprintf('%.1f%%', 100 * ($t[$k] ?? 0) / $t['n']);
    printf(
        "  %-22s %5d %8s %9s %9s %9s %9s\n",
        $depth === 0 ? "none (Jev's own pick)" : ($depth === 9 ? 'to the end' : "{$depth} " . ($depth === 1 ? 'move' : 'moves')),
        $t['n'],
        sprintf('%.1f%%', 100 * (($t['optimal'] ?? 0) + ($t['slow_win'] ?? 0)) / $t['n']),
        $pct('missed_win'),
        $pct('missed_block'),
        $pct('allowed_fork'),
        $pct('other_blunder'),
    );
}
