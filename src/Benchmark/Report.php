<?php

declare(strict_types=1);

namespace JevTtt\Benchmark;

use JevTtt\Board;
use JevTtt\JevClient;
use JevTtt\MoveQuality;
use JevTtt\QuestionBuilder;
use JevTtt\Solver;

/**
 * Turns stored results into the metrics behind public/data/report.json.
 * Ground truth is recomputed from the Solver on every run.
 */
final class Report
{
    private const array OUTCOMES = ['loss', 'draw', 'win'];

    public function __construct(
        private readonly Solver $solver,
        private readonly Store $store,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(string $promptVersion = QuestionBuilder::PROMPT_VERSION, string $model = JevClient::MODEL): array
    {
        $report = [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'model' => $model,
            'prompt_version' => $promptVersion,
            'positions' => [
                'non_terminal' => count($this->solver->nonTerminalPositions()),
                'decision' => count(array_filter(
                    $this->solver->nonTerminalPositions(),
                    fn (Board $board): bool => $this->solver->evaluate($board)->isDecision(),
                )),
            ],
            'caveats' => [
                'One sample per position. Jev is not deterministic, so every figure carries sampling noise that was not measured.',
                'Probabilities arrive rounded to two decimal places.',
                'Headline figures use the test split. Prompt wording is only ever tuned on the dev split.',
                'The tournament walks the whole game tree, so it uses positions from both splits.',
            ],
            'representations' => [],
        ];

        foreach (QuestionBuilder::REPRESENTATIONS as $representation) {
            $where = ['representation' => $representation, 'prompt_version' => $promptVersion, 'model' => $model, 'repeat' => 0];
            $rows = $this->store->rows($where + ['option_order' => 'reading']);
            if ($rows === []) {
                continue;
            }

            $entry = ['rows' => count($rows), 'complete' => count($rows) === $report['positions']['non_terminal']];
            foreach (['test', 'dev', 'all'] as $split) {
                $subset = $split === 'all' ? $rows : array_values(array_filter($rows, static fn (array $row): bool => $row['split'] === $split));
                if ($subset !== []) {
                    $entry['splits'][$split] = $this->metrics($subset);
                }
            }

            $entry['symmetry'] = $this->symmetry($rows);
            $entry['tournament'] = $entry['complete'] ? $this->tournament($rows) : null;
            $entry['option_order'] = $this->optionOrder($rows, $this->store->rows($where));
            $entry['cost'] = $this->cost($rows);
            $entry['worst_blunders'] = $this->worstBlunders($rows);

            $report['representations'][$representation] = $entry;
        }

        return $report;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function metrics(array $rows): array
    {
        $qualities = array_fill_keys(array_map(static fn (MoveQuality $q): string => $q->value, MoveQuality::cases()), 0);
        $byPieces = [];
        $decisions = $optimalAll = 0;
        $baselineAll = $baselineDecisions = $mass = 0.0;
        $topPick = $confidence = [];
        $nouls = ['can_win_now' => [], 'must_block' => []];
        $confusion = array_fill_keys(self::OUTCOMES, array_fill_keys(self::OUTCOMES, 0));
        $outcomeBrier = 0.0;
        $outcomeCorrect = 0;

        foreach ($rows as $row) {
            $board = Board::fromString($row['position']);
            $evaluation = $this->solver->evaluate($board);
            $quality = $this->solver->classify($board, (int) $row['move']);
            $good = !$quality->isBlunder();
            $probabilities = json_decode($row['move_probabilities'], true);
            $pieces = 9 - count($evaluation->moves);

            $optimalAll += (int) $good;
            $baselineAll += $evaluation->randomBaseline();

            $byPieces[$pieces] ??= ['pieces' => $pieces, 'n' => 0, 'decisions' => 0, 'optimal' => 0, 'baseline' => 0.0];
            $byPieces[$pieces]['n']++;

            if ($evaluation->isDecision()) {
                $decisions++;
                $qualities[$quality->value]++;
                $baselineDecisions += $evaluation->randomBaseline();
                $mass += array_sum(array_intersect_key($probabilities, array_flip($evaluation->optimalMoves)));

                $byPieces[$pieces]['decisions']++;
                $byPieces[$pieces]['optimal'] += (int) $good;
                $byPieces[$pieces]['baseline'] += $evaluation->randomBaseline();

                $stratum = min(3, count($evaluation->optimalMoves));
                $topPick[] = [(float) $probabilities[$row['move']], $good, $stratum];
                $confidence[] = [(float) $row['move_confidence'], $good, $stratum];
            }

            $nouls['can_win_now'][] = [(float) $row['can_win_now'], $this->solver->immediateWins($board) !== []];
            $nouls['must_block'][] = [(float) $row['must_block'], $this->solver->opponentThreats($board) !== []];

            $levels = json_decode($row['outcome_probabilities'], true);
            $predicted = self::OUTCOMES[array_search(max($levels), $levels, true)];
            $truth = self::OUTCOMES[$evaluation->value + 1];
            $confusion[$truth][$predicted]++;
            $outcomeCorrect += (int) ($predicted === $truth);
            foreach ($levels as $i => $p) {
                $outcomeBrier += ($p - (int) ($i === $evaluation->value + 1)) ** 2;
            }
        }

        ksort($byPieces);
        $n = count($rows);
        $d = max(1, $decisions);
        $truthCounts = array_map(array_sum(...), $confusion);
        $optimalDecisions = ($qualities['optimal'] + $qualities['slow_win']) / $d;

        return [
            'n' => $n,
            'decisions' => $decisions,
            'optimal_rate' => [
                'decisions' => self::round($optimalDecisions),
                'random_baseline_decisions' => self::round($baselineDecisions / $d),
                'lift_over_random' => self::round($optimalDecisions - $baselineDecisions / $d),
                'all_positions' => self::round($optimalAll / $n),
                'random_baseline_all' => self::round($baselineAll / $n),
            ],
            'optimal_mass_decisions' => self::round($mass / $d),
            'quality_on_decisions' => $qualities,
            'by_pieces' => array_values(array_map(static fn (array $p): array => [
                'pieces' => $p['pieces'],
                'n' => $p['n'],
                'decisions' => $p['decisions'],
                'optimal_rate' => $p['decisions'] ? self::round($p['optimal'] / $p['decisions']) : null,
                'random_baseline' => $p['decisions'] ? self::round($p['baseline'] / $p['decisions']) : null,
            ], $byPieces)),
            'move_calibration' => [
                'top_pick_probability' => self::reliability($topPick),
                'top_pick_probability_by_optimal_moves' => [
                    '1' => self::reliability(array_filter($topPick, static fn (array $s): bool => $s[2] === 1)),
                    '2' => self::reliability(array_filter($topPick, static fn (array $s): bool => $s[2] === 2)),
                    '3+' => self::reliability(array_filter($topPick, static fn (array $s): bool => $s[2] === 3)),
                ],
                'confidence' => self::reliability($confidence),
            ],
            'nouls' => array_map(self::noul(...), $nouls),
            'outcome' => [
                'accuracy' => self::round($outcomeCorrect / $n),
                'majority_class_accuracy' => self::round(max($truthCounts) / $n),
                'brier' => self::round($outcomeBrier / $n),
                'confusion_truth_by_predicted' => $confusion,
            ],
        ];
    }

    /**
     * @param list<array{0: float, 1: bool}> $samples
     * @return array<string, mixed>
     */
    private static function noul(array $samples): array
    {
        $n = count($samples);
        $true = count(array_filter($samples, static fn (array $s): bool => $s[1]));
        $baseRate = $true / $n;
        $brier = $correct = 0;
        foreach ($samples as [$p, $truth]) {
            $brier += ($p - (int) $truth) ** 2;
            $correct += (int) (($p >= 0.5) === $truth);
        }

        return [
            'base_rate' => self::round($baseRate),
            'brier' => self::round($brier / $n),
            'brier_of_always_base_rate' => self::round($baseRate * (1 - $baseRate)),
            'accuracy_at_half' => self::round($correct / $n),
            'accuracy_of_majority_answer' => self::round(max($baseRate, 1 - $baseRate)),
            'mean_when_true' => self::mean(array_column(array_filter($samples, static fn (array $s): bool => $s[1]), 0)),
            'mean_when_false' => self::mean(array_column(array_filter($samples, static fn (array $s): bool => !$s[1]), 0)),
            'reliability' => self::reliability($samples),
        ];
    }

    /**
     * Ten equal-width buckets of predicted probability against observed rate.
     *
     * @param array<array-key, array{0: float, 1: bool}> $samples
     * @return list<array{bucket: string, n: int, mean_predicted: float, observed: float}>
     */
    private static function reliability(array $samples): array
    {
        $buckets = [];
        foreach ($samples as $sample) {
            $i = min(9, (int) floor($sample[0] * 10));
            $buckets[$i]['p'][] = $sample[0];
            $buckets[$i]['hit'][] = (int) $sample[1];
        }

        ksort($buckets);
        $table = [];
        foreach ($buckets as $i => $bucket) {
            $table[] = [
                'bucket' => sprintf('%.1f-%.1f', $i / 10, ($i + 1) / 10),
                'n' => count($bucket['p']),
                'mean_predicted' => self::mean($bucket['p']),
                'observed' => self::mean($bucket['hit']),
            ];
        }

        return $table;
    }

    /**
     * For each symmetry class, map every variant's pick back into the canonical
     * frame. A perfectly consistent player picks the same canonical cell for
     * all variants. Cells that the position's own symmetry makes equivalent
     * count as the same pick.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function symmetry(array $rows): array
    {
        $classes = [];
        foreach ($rows as $row) {
            $board = Board::fromString($row['position']);
            $canonical = $board->canonical()->cells;

            $images = [];
            foreach (array_keys(Board::SYMMETRIES) as $symmetry) {
                if ($board->transform($symmetry)->cells === $canonical) {
                    $images[] = Board::transformCell($symmetry, (int) $row['move']);
                }
            }
            $classes[$canonical][] = min($images);
        }

        $classCount = $consistent = 0;
        $modal = 0.0;
        foreach ($classes as $picks) {
            if (count($picks) < 2) {
                continue;
            }
            $classCount++;
            $top = max(array_count_values($picks));
            $modal += $top / count($picks);
            $consistent += (int) ($top === count($picks));
        }

        return [
            'classes_compared' => $classCount,
            'fully_consistent_rate' => $classCount ? self::round($consistent / $classCount) : null,
            'mean_agreement_with_modal_pick' => $classCount ? self::round($modal / $classCount) : null,
        ];
    }

    /**
     * Exact results of Jev's stored picks as a fixed policy, by walking the game tree.
     * "perfect" spreads evenly over all value-preserving moves, "random" over all legal moves.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, array{win: float, draw: float, loss: float}>>
     */
    private function tournament(array $rows): array
    {
        $policy = array_column($rows, 'move', 'position');
        $results = [];

        foreach (['perfect', 'random', 'itself'] as $opponent) {
            foreach (['X', 'O'] as $jevPlays) {
                $memo = [];
                $walk = function (Board $board) use (&$walk, &$memo, $policy, $opponent, $jevPlays): array {
                    if ($board->isTerminal()) {
                        $winner = $board->winner();

                        return [(float) ($winner === $jevPlays), (float) ($winner === null), (float) ($winner !== null && $winner !== $jevPlays)];
                    }
                    if (isset($memo[$board->cells])) {
                        return $memo[$board->cells];
                    }

                    $moves = match (true) {
                        $board->toMove() === $jevPlays, $opponent === 'itself' => [(int) $policy[$board->cells]],
                        $opponent === 'perfect' => $this->solver->evaluate($board)->optimalMoves,
                        default => $board->emptyCells(),
                    };

                    $total = [0.0, 0.0, 0.0];
                    foreach ($moves as $move) {
                        foreach ($walk($board->play($move)) as $i => $p) {
                            $total[$i] += $p / count($moves);
                        }
                    }

                    return $memo[$board->cells] = $total;
                };

                [$win, $draw, $loss] = $walk(Board::empty());
                $results[$opponent]["jev_as_{$jevPlays}"] = [
                    'win' => self::round($win),
                    'draw' => self::round($draw),
                    'loss' => self::round($loss),
                ];
            }
        }

        return $results;
    }

    /**
     * Same positions asked again with the move options shuffled.
     *
     * @param list<array<string, mixed>> $reading
     * @param list<array<string, mixed>> $all
     * @return array<string, mixed>|null
     */
    private function optionOrder(array $reading, array $all): ?array
    {
        $byPosition = array_column($reading, null, 'position');
        $pairs = $same = $readingGood = $shuffledGood = 0;

        foreach ($all as $row) {
            if ($row['option_order'] === 'reading' || !isset($byPosition[$row['position']])) {
                continue;
            }
            $board = Board::fromString($row['position']);
            if (!$this->solver->evaluate($board)->isDecision()) {
                continue;
            }

            $pairs++;
            $same += (int) ((int) $row['move'] === (int) $byPosition[$row['position']]['move']);
            $readingGood += (int) !$this->solver->classify($board, (int) $byPosition[$row['position']]['move'])->isBlunder();
            $shuffledGood += (int) !$this->solver->classify($board, (int) $row['move'])->isBlunder();
        }

        return $pairs === 0 ? null : [
            'decision_positions_compared' => $pairs,
            'same_pick_rate' => self::round($same / $pairs),
            'optimal_rate_reading_order' => self::round($readingGood / $pairs),
            'optimal_rate_shuffled' => self::round($shuffledGood / $pairs),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function cost(array $rows): array
    {
        $latency = array_map(intval(...), array_column($rows, 'latency_ms'));
        $upstream = array_map(intval(...), array_filter(array_column($rows, 'upstream_ms'), is_numeric(...)));
        $tokens = array_sum(array_column($rows, 'input_tokens'));
        sort($latency);
        sort($upstream);

        return [
            'requests' => count($rows),
            'retried_requests' => count(array_filter($rows, static fn (array $row): bool => $row['attempts'] > 1)),
            'input_tokens' => $tokens,
            'mean_input_tokens' => (int) round($tokens / count($rows)),
            'usd' => round($tokens * JevClient::USD_PER_INPUT_TOKEN, 4),
            'latency_ms' => ['median' => self::percentile($latency, 0.5), 'p95' => self::percentile($latency, 0.95)],
            'upstream_ms' => ['median' => self::percentile($upstream, 0.5), 'p95' => self::percentile($upstream, 0.95)],
        ];
    }

    /**
     * Test-split blunders Jev was most sure about. Candidates for screenshots.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function worstBlunders(array $rows): array
    {
        $blunders = [];
        foreach ($rows as $row) {
            $board = Board::fromString($row['position']);
            $quality = $this->solver->classify($board, (int) $row['move']);
            if ($row['split'] !== 'test' || !$quality->isBlunder()) {
                continue;
            }

            $probabilities = json_decode($row['move_probabilities'], true);
            $blunders[] = [
                'position' => $row['position'],
                'to_move' => $board->toMove(),
                'jev_move' => (int) $row['move'],
                'jev_probability' => $probabilities[$row['move']],
                'optimal_moves' => $this->solver->evaluate($board)->optimalMoves,
                'type' => $quality->value,
            ];
        }

        usort($blunders, static fn (array $a, array $b): int => $b['jev_probability'] <=> $a['jev_probability']);

        return array_slice($blunders, 0, 10);
    }

    /** @param list<int> $sorted */
    private static function percentile(array $sorted, float $p): ?int
    {
        return $sorted === [] ? null : $sorted[(int) floor($p * (count($sorted) - 1))];
    }

    /** @param array<array-key, int|float> $values */
    private static function mean(array $values): ?float
    {
        return $values === [] ? null : self::round(array_sum($values) / count($values));
    }

    private static function round(float $value): float
    {
        return round($value, 4);
    }
}
