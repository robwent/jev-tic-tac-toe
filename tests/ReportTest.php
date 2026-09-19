<?php

declare(strict_types=1);

namespace JevTtt\Tests;

use JevTtt\Benchmark\Report;
use JevTtt\Benchmark\Split;
use JevTtt\Benchmark\Store;
use JevTtt\Board;
use JevTtt\JevResponse;
use JevTtt\ParsedAnswers;
use JevTtt\QuestionBuilder;
use JevTtt\Solver;
use PHPUnit\Framework\TestCase;

/**
 * Feeds the report two fake players whose scores are known in advance:
 * an oracle that answers everything exactly, and one that always takes the
 * first empty cell and answers every question with a shrug.
 */
final class ReportTest extends TestCase
{
    private static Solver $solver;

    /** @var array<string, mixed> */
    private static array $report;

    public static function setUpBeforeClass(): void
    {
        self::$solver = new Solver();
        $split = new Split(self::$solver);
        $store = new Store(':memory:');

        foreach (self::$solver->nonTerminalPositions() as $board) {
            $evaluation = self::$solver->evaluate($board);

            $best = $evaluation->bestMoves[0];
            $outcome = [0.0, 0.0, 0.0];
            $outcome[$evaluation->value + 1] = 1.0;
            self::save($store, $split, $board, 'grid', $best, 1.0, (float) (self::$solver->immediateWins($board) !== []), (float) (self::$solver->opponentThreats($board) !== []), $outcome);

            self::save($store, $split, $board, 'cells', $board->emptyCells()[0], 0.5, 0.5, 0.5, [0.0, 1.0, 0.0]);
        }

        self::$report = (new Report(self::$solver, $store))->build();
    }

    /** @param array{0: float, 1: float, 2: float} $outcome */
    private static function save(Store $store, Split $split, Board $board, string $representation, int $move, float $p, float $canWin, float $mustBlock, array $outcome): void
    {
        $empty = $board->emptyCells();
        $others = count($empty) > 1 ? (1 - $p) / (count($empty) - 1) : 0.0;
        $probabilities = [];
        foreach ($empty as $cell) {
            $probabilities[Board::CELL_NAMES[$cell]] = $cell === $move ? (count($empty) > 1 ? $p : 1.0) : $others;
        }

        $body = [
            'model' => 'jev-1.13.0',
            'answers' => [
                'move' => ['type' => 'choice', 'choice' => Board::CELL_NAMES[$move], 'confidence' => $p, 'probabilities' => $probabilities],
                'can_win_now' => ['type' => 'noul', 'noul' => $canWin],
                'must_block' => ['type' => 'noul', 'noul' => $mustBlock],
                'outcome' => ['type' => 'score', 'score' => 1.0, 'confidence' => 1.0, 'probabilities' => $outcome],
            ],
            'usage' => ['input_tokens' => 500],
        ];

        $store->save(
            $board,
            $representation,
            QuestionBuilder::PROMPT_VERSION,
            'reading',
            0,
            $split->of($board),
            [],
            new JevResponse(200, json_encode($body, JSON_THROW_ON_ERROR), 300, 90, 'req_test', 1),
            ParsedAnswers::fromResponse($body, $board),
        );
    }

    public function testOracleScoresPerfectly(): void
    {
        $oracle = self::$report['representations']['grid'];
        $all = $oracle['splits']['all'];

        self::assertTrue($oracle['complete']);
        self::assertSame(4520, $all['n']);
        self::assertSame(3191, $all['decisions']);
        self::assertSame(1.0, $all['optimal_rate']['decisions']);
        self::assertSame(1.0, $all['optimal_rate']['all_positions']);
        self::assertSame(0.0, (float) $all['nouls']['can_win_now']['brier']);
        self::assertSame(1.0, $all['nouls']['must_block']['accuracy_at_half']);
        self::assertSame(1.0, $all['outcome']['accuracy']);
        self::assertSame(0.0, (float) $all['outcome']['brier']);
        self::assertSame(0, $all['quality_on_decisions']['slow_win']);
        self::assertSame([], $oracle['worst_blunders']);

        foreach (['perfect', 'random', 'itself'] as $opponent) {
            foreach (['jev_as_X', 'jev_as_O'] as $side) {
                self::assertSame(0.0, (float) $oracle['tournament'][$opponent][$side]['loss'], "{$opponent} {$side}");
            }
        }
        self::assertSame(1.0, $oracle['tournament']['perfect']['jev_as_X']['draw']);
        self::assertSame(1.0, $oracle['tournament']['itself']['jev_as_O']['draw']);
        self::assertGreaterThan(0.9, $oracle['tournament']['random']['jev_as_X']['win']);
    }

    public function testSplitsPartitionThePositions(): void
    {
        $splits = self::$report['representations']['grid']['splits'];

        self::assertSame(4520, $splits['dev']['n'] + $splits['test']['n']);
        self::assertSame(3191, $splits['dev']['decisions'] + $splits['test']['decisions']);
    }

    public function testFirstEmptyCellPlayerIsMeasuredAsWeak(): void
    {
        $naive = self::$report['representations']['cells'];
        $all = $naive['splits']['all'];

        self::assertLessThan(0.6, $all['optimal_rate']['decisions']);
        self::assertGreaterThan(0, $all['quality_on_decisions']['missed_win']);
        self::assertGreaterThan(0, $all['quality_on_decisions']['missed_block']);
        self::assertSame(3191, array_sum($all['quality_on_decisions']));

        // Every Noul answered 0.5 scores exactly 0.25.
        self::assertSame(0.25, $all['nouls']['can_win_now']['brier']);
        self::assertSame(0.5217, $all['nouls']['can_win_now']['base_rate']); // 2358 / 4520
        self::assertSame(0.7164, $all['nouls']['must_block']['base_rate']); // 3238 / 4520

        // Always answering "draw" is right exactly as often as the truth is a draw.
        self::assertSame(round(1052 / 4520, 4), $all['outcome']['accuracy']);
        self::assertSame(round(2836 / 4520, 4), $all['outcome']['majority_class_accuracy']);

        // Reading order is not symmetric, so the picks cannot all agree.
        self::assertLessThan(1.0, $naive['symmetry']['fully_consistent_rate']);
        self::assertGreaterThan(0.0, $naive['tournament']['perfect']['jev_as_X']['loss'] + $naive['tournament']['perfect']['jev_as_O']['loss']);
        self::assertCount(10, $naive['worst_blunders']);
    }

    public function testASymmetricPlayerIsFullyConsistent(): void
    {
        // The oracle is not symmetric either, so check the metric directly with
        // a player that picks by canonical frame.
        $store = new Store(':memory:');
        $split = new Split(self::$solver);

        foreach (self::$solver->nonTerminalPositions() as $board) {
            $canonical = $board->canonical();
            foreach (array_keys(Board::SYMMETRIES) as $symmetry) {
                if ($canonical->transform($symmetry)->cells === $board->cells) {
                    $move = Board::transformCell($symmetry, $canonical->emptyCells()[0]);
                    break;
                }
            }
            self::save($store, $split, $board, 'lines', $move, 0.5, 0.5, 0.5, [0.0, 1.0, 0.0]);
        }

        $symmetry = (new Report(self::$solver, $store))->build()['representations']['lines']['symmetry'];

        self::assertSame(1.0, $symmetry['fully_consistent_rate']);
        self::assertSame(1.0, $symmetry['mean_agreement_with_modal_pick']);
    }
}
