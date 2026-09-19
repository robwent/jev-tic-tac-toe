<?php

declare(strict_types=1);

namespace JevTtt\Tests;

use JevTtt\Board;
use JevTtt\SearchPlayer;
use JevTtt\Solver;
use PHPUnit\Framework\TestCase;

/**
 * With perfect judgements in place of Jev's, the search alone must be sound.
 */
final class SearchPlayerTest extends TestCase
{
    private static Solver $solver;
    private static SearchPlayer $player;

    public static function setUpBeforeClass(): void
    {
        self::$solver = new Solver();

        $judgements = [];
        foreach (self::$solver->positions() as $board) {
            $judgements[$board->cells] = [
                'x_line' => (float) $board->hasWon('X'),
                'o_line' => (float) $board->hasWon('O'),
                'x_threat' => (float) ($board->completingCells('X') !== []),
                'o_threat' => (float) ($board->completingCells('O') !== []),
            ];
        }
        self::$player = new SearchPlayer($judgements);
    }

    public function testOneMoveOfLookAheadTakesWinsAndBlocks(): void
    {
        self::assertSame(2, self::$player->choose(Board::fromString('XX-OO----'), 1));
        self::assertSame(1, self::$player->choose(Board::fromString('X-X-O----'), 1));
    }

    public function testThreeMovesOfLookAheadAvoidsTheOppositeCornersFork(): void
    {
        $move = self::$player->choose(Board::fromString('X---O---X'), 3);

        self::assertContains($move, [1, 3, 5, 7]);
    }

    public function testFullDepthWithPerfectJudgementsNeverBlunders(): void
    {
        foreach (self::$solver->nonTerminalPositions() as $board) {
            $move = self::$player->choose($board, 9);
            self::assertFalse(self::$solver->classify($board, $move)->isBlunder(), $board->cells);
        }
    }

    public function testPriorBreaksTies(): void
    {
        // Every opening draws, so the prior decides.
        self::assertSame(4, self::$player->choose(Board::empty(), 9, [4 => 0.9, 0 => 0.1]));
        self::assertSame(8, self::$player->choose(Board::empty(), 9, [8 => 0.6, 4 => 0.4]));
    }
}
