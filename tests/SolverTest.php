<?php

declare(strict_types=1);

namespace JevTtt\Tests;

use JevTtt\Board;
use JevTtt\MoveQuality;
use JevTtt\Solver;
use PHPUnit\Framework\TestCase;

final class SolverTest extends TestCase
{
    private static Solver $solver;

    public static function setUpBeforeClass(): void
    {
        self::$solver = new Solver();
    }

    public function testKnownPositionCounts(): void
    {
        $positions = self::$solver->positions();

        self::assertCount(5478, $positions);
        self::assertSame('---------', $positions[0]->cells);

        $canonical = [];
        $xWins = $oWins = $draws = 0;
        foreach ($positions as $board) {
            $canonical[$board->canonical()->cells] = true;
            if ($board->isTerminal()) {
                match ($board->winner()) {
                    'X' => $xWins++,
                    'O' => $oWins++,
                    null => $draws++,
                };
            }
        }

        self::assertCount(765, $canonical);
        self::assertSame(626, $xWins);
        self::assertSame(316, $oWins);
        self::assertSame(16, $draws);
        self::assertSame(958, $xWins + $oWins + $draws);
    }

    public function testNonTerminalPositionsAreTheBenchmarkSet(): void
    {
        self::assertCount(4520, self::$solver->nonTerminalPositions());
    }

    public function testKnownGameCount(): void
    {
        self::assertSame(255168, self::$solver->countGames());
    }

    public function testEnumeratorAgreesWithLegalityCheckOverAllStrings(): void
    {
        $reachable = [];
        foreach (self::$solver->positions() as $board) {
            $reachable[$board->cells] = true;
        }

        $legal = [];
        for ($n = 0; $n < 3 ** 9; $n++) {
            $cells = strtr(str_pad(base_convert((string) $n, 10, 3), 9, '0', STR_PAD_LEFT), '012', '-XO');
            if (Board::fromString($cells)->isLegal()) {
                $legal[$cells] = true;
            }
        }

        self::assertSame([], array_keys(array_diff_key($reachable, $legal)), 'reachable but judged illegal');
        self::assertSame([], array_keys(array_diff_key($legal, $reachable)), 'judged legal but unreachable');
    }

    public function testEmptyBoardIsADrawAndEveryOpeningHoldsIt(): void
    {
        $evaluation = self::$solver->evaluate(Board::empty());

        self::assertSame(0, $evaluation->value);
        self::assertSame(9, $evaluation->depth);
        self::assertSame(range(0, 8), $evaluation->optimalMoves);
        self::assertFalse($evaluation->isDecision());
        self::assertSame(1.0, $evaluation->randomBaseline());
    }

    public function testOnlyTheCentreAnswersACornerOpening(): void
    {
        $evaluation = self::$solver->evaluate(Board::fromString('X--------'));

        self::assertSame(0, $evaluation->value);
        self::assertSame([4], $evaluation->optimalMoves);
        self::assertTrue($evaluation->isDecision());
        self::assertSame(1 / 8, $evaluation->randomBaseline());
    }

    public function testTerminalPositions(): void
    {
        $lost = self::$solver->evaluate(Board::fromString('XXXOO----'));
        self::assertSame(-1, $lost->value);
        self::assertSame(0, $lost->depth);
        self::assertSame([], $lost->moves);

        $drawn = self::$solver->evaluate(Board::fromString('XOXXOOOXX'));
        self::assertSame(0, $drawn->value);
        self::assertSame(0, $drawn->depth);
    }

    public function testImmediateWinIsAlwaysTheBestMove(): void
    {
        $checked = 0;
        foreach (self::$solver->nonTerminalPositions() as $board) {
            $wins = self::$solver->immediateWins($board);
            if ($wins === []) {
                continue;
            }

            $evaluation = self::$solver->evaluate($board);
            self::assertSame(1, $evaluation->value, $board->cells);
            self::assertSame(1, $evaluation->depth, $board->cells);
            self::assertSame($wins, $evaluation->bestMoves, $board->cells);
            $checked++;
        }

        self::assertGreaterThan(0, $checked);
    }

    public function testBestMovesAreASubsetOfOptimalMovesAndSlowWinsExist(): void
    {
        $slowWinPositions = 0;
        foreach (self::$solver->nonTerminalPositions() as $board) {
            $evaluation = self::$solver->evaluate($board);

            self::assertNotSame([], $evaluation->bestMoves, $board->cells);
            self::assertSame([], array_diff($evaluation->bestMoves, $evaluation->optimalMoves), $board->cells);

            if ($evaluation->value === 1 && count($evaluation->optimalMoves) > count($evaluation->bestMoves)) {
                $slowWinPositions++;
            }
        }

        self::assertGreaterThan(0, $slowWinPositions);
    }

    public function testThreatsAreLiteralEvenWhenThePlayerCanWinFirst(): void
    {
        // X X -
        // O O -
        // - - -
        $board = Board::fromString('XX-OO----');

        self::assertSame([2], self::$solver->immediateWins($board));
        self::assertSame([5], self::$solver->opponentThreats($board));
        self::assertFalse(self::$solver->blockForced($board));
        self::assertSame(MoveQuality::Optimal, self::$solver->classify($board, 2));
        self::assertSame(MoveQuality::MissedWin, self::$solver->classify($board, 8));
    }

    public function testForcedBlock(): void
    {
        // X - X
        // - O -
        // - - -
        $board = Board::fromString('X-X-O----');

        self::assertSame([], self::$solver->immediateWins($board));
        self::assertSame([1], self::$solver->opponentThreats($board));
        self::assertTrue(self::$solver->blockForced($board));
        self::assertSame([1], self::$solver->evaluate($board)->optimalMoves);
        self::assertSame(MoveQuality::MissedBlock, self::$solver->classify($board, 3));
    }

    public function testOppositeCornersTrap(): void
    {
        // X - -
        // - O -     O must play an edge. A corner lets X fork.
        // - - X
        $board = Board::fromString('X---O---X');
        $evaluation = self::$solver->evaluate($board);

        self::assertSame(0, $evaluation->value);
        self::assertSame([1, 3, 5, 7], $evaluation->optimalMoves);
        self::assertTrue(self::$solver->allowsFork($board, 2));
        self::assertTrue(self::$solver->allowsFork($board, 6));
        self::assertFalse(self::$solver->allowsFork($board, 1));
        self::assertSame(MoveQuality::AllowedFork, self::$solver->classify($board, 2));
        self::assertSame(MoveQuality::Optimal, self::$solver->classify($board, 1));
    }

    public function testLostPositionIsNotADecision(): void
    {
        // X - O
        // - O -     O to move, X threatens both middle_left and bottom_middle.
        // X - X
        $board = Board::fromString('X-O-O-X-X');
        $evaluation = self::$solver->evaluate($board);

        self::assertSame(-1, $evaluation->value);
        self::assertSame(2, $evaluation->depth);
        self::assertSame([1, 3, 5, 7], $evaluation->optimalMoves);
        self::assertFalse($evaluation->isDecision());
        self::assertFalse(self::$solver->blockForced($board) && $evaluation->isDecision());
        self::assertSame(MoveQuality::Optimal, self::$solver->classify($board, 1));
    }

    public function testSlowWinIsClassifiedSeparately(): void
    {
        foreach (self::$solver->nonTerminalPositions() as $board) {
            $evaluation = self::$solver->evaluate($board);
            $slow = array_values(array_diff($evaluation->optimalMoves, $evaluation->bestMoves));
            if ($evaluation->value !== 1 || $slow === []) {
                continue;
            }

            self::assertSame(MoveQuality::SlowWin, self::$solver->classify($board, $slow[0]), $board->cells);
            self::assertSame(MoveQuality::Optimal, self::$solver->classify($board, $evaluation->bestMoves[0]));

            return;
        }

        self::fail('No slow-win position found.');
    }

    public function testEvaluationIsInvariantUnderSymmetry(): void
    {
        foreach (self::$solver->nonTerminalPositions() as $board) {
            $evaluation = self::$solver->evaluate($board);

            foreach (array_keys(Board::SYMMETRIES) as $symmetry) {
                $mirrored = self::$solver->evaluate($board->transform($symmetry));
                $expected = array_map(
                    static fn (int $cell): int => Board::transformCell($symmetry, $cell),
                    $evaluation->optimalMoves,
                );
                sort($expected);

                self::assertSame($evaluation->value, $mirrored->value);
                self::assertSame($evaluation->depth, $mirrored->depth);
                self::assertSame($expected, $mirrored->optimalMoves);
            }
        }
    }
}
