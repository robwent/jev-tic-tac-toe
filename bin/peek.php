<?php

declare(strict_types=1);

// php bin/peek.php [--representation=lines] [--split=dev] [--prompt=v1]
// Row-by-row view of stored results next to the solver's truth, for eyeballing small runs.

use JevTtt\Benchmark\Store;
use JevTtt\Board;
use JevTtt\Solver;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['representation::', 'split::', 'prompt::']);
$where = array_filter([
    'representation' => $options['representation'] ?? null,
    'split' => $options['split'] ?? null,
    'prompt_version' => $options['prompt'] ?? null,
]);

$solver = new Solver();
$rows = (new Store(dirname(__DIR__) . '/storage/results.sqlite'))->rows($where);

$stats = [];
printf("%-6s %-9s %-4s %-5s %-9s %-13s %5s %5s  %-9s %-9s %s\n", 'rep', 'position', 'move', 'p', 'optimal', 'quality', 'win?', 'blk?', 'outcome', 'truth', 'ms');

foreach ($rows as $row) {
    $board = Board::fromString($row['position']);
    $evaluation = $solver->evaluate($board);
    $quality = $solver->classify($board, (int) $row['move']);
    $probabilities = json_decode($row['move_probabilities'], true);
    $outcome = json_decode($row['outcome_probabilities'], true);
    $predicted = array_search(max($outcome), $outcome, true) - 1;

    $canWin = $solver->immediateWins($board) !== [];
    $threat = $solver->opponentThreats($board) !== [];

    printf(
        "%-6s %-9s %-4d %-5.2f %-9s %-13s %4.2f%s %4.2f%s  %-9s %-9s %d\n",
        $row['representation'],
        $row['position'],
        $row['move'],
        $probabilities[$row['move']],
        implode('', $evaluation->optimalMoves),
        $quality->value . ($evaluation->isDecision() ? '' : '*'),
        $row['can_win_now'],
        ($row['can_win_now'] >= 0.5) === $canWin ? ' ' : '!',
        $row['must_block'],
        ($row['must_block'] >= 0.5) === $threat ? ' ' : '!',
        ['loss', 'draw', 'win'][$predicted + 1] . sprintf(' %.2f', max($outcome)),
        ['loss', 'draw', 'win'][$evaluation->value + 1],
        $row['latency_ms'],
    );

    $s = &$stats[$row['representation']];
    $s ??= ['n' => 0, 'decisions' => 0, 'optimal' => 0, 'baseline' => 0.0, 'mass' => 0.0, 'win' => 0, 'block' => 0, 'outcome' => 0, 'ms' => 0, 'upstream' => 0];
    $s['n']++;
    $s['win'] += (int) ((($row['can_win_now'] >= 0.5) === $canWin));
    $s['block'] += (int) ((($row['must_block'] >= 0.5) === $threat));
    $s['outcome'] += (int) ($predicted === $evaluation->value);
    $s['ms'] += $row['latency_ms'];
    $s['upstream'] += (int) $row['upstream_ms'];
    if ($evaluation->isDecision()) {
        $s['decisions']++;
        $s['optimal'] += (int) !$quality->isBlunder();
        $s['baseline'] += $evaluation->randomBaseline();
        $s['mass'] += array_sum(array_intersect_key($probabilities, array_flip($evaluation->optimalMoves)));
    }
    unset($s);
}

echo "\n* = not a decision position (every move is optimal). ! = wrong side of 0.5.\n\n";
printf("%-6s %4s %5s  %-22s %-10s %-9s %-9s %-9s %s\n", 'rep', 'n', 'dec', 'optimal on decisions', 'random', 'opt mass', 'win acc', 'block acc', 'outcome acc / mean ms (upstream)');
foreach ($stats as $name => $s) {
    $d = max(1, $s['decisions']);
    printf(
        "%-6s %4d %5d  %-22s %-10s %-9s %-9s %-9s %s\n",
        $name,
        $s['n'],
        $s['decisions'],
        sprintf('%d/%d = %.0f%%', $s['optimal'], $s['decisions'], 100 * $s['optimal'] / $d),
        sprintf('%.0f%%', 100 * $s['baseline'] / $d),
        sprintf('%.2f', $s['mass'] / $d),
        sprintf('%.0f%%', 100 * $s['win'] / $s['n']),
        sprintf('%.0f%%', 100 * $s['block'] / $s['n']),
        sprintf('%.0f%% / %d (%d)', 100 * $s['outcome'] / $s['n'], $s['ms'] / $s['n'], $s['upstream'] / $s['n']),
    );
}
