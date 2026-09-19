<?php

declare(strict_types=1);

namespace JevTtt\Tests;

use InvalidArgumentException;
use JevTtt\Board;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoardTest extends TestCase
{
    public function testEmptyBoard(): void
    {
        $board = Board::empty();

        self::assertSame('---------', $board->cells);
        self::assertSame('X', $board->toMove());
        self::assertTrue($board->isLegal());
        self::assertFalse($board->isTerminal());
        self::assertSame(range(0, 8), $board->emptyCells());
    }

    #[DataProvider('malformedStrings')]
    public function testRejectsMalformedStrings(string $cells): void
    {
        $this->expectException(InvalidArgumentException::class);
        Board::fromString($cells);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedStrings(): iterable
    {
        yield 'too short' => ['--------'];
        yield 'too long' => ['----------'];
        yield 'lowercase' => ['x--------'];
        yield 'other characters' => ['X O------'];
        yield 'multibyte' => ['✗--------'];
    }

    public function testPlayAlternatesMarksAndIsImmutable(): void
    {
        $empty = Board::empty();
        $afterX = $empty->play(4);
        $afterO = $afterX->play(0);

        self::assertSame('---------', $empty->cells);
        self::assertSame('----X----', $afterX->cells);
        self::assertSame('O---X----', $afterO->cells);
        self::assertSame('X', $afterO->toMove());
    }

    public function testPlayRejectsOccupiedCell(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Board::fromString('X--------')->play(0);
    }

    public function testPlayRejectsMoveOnFinishedGame(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Board::fromString('XXXOO----')->play(8);
    }

    #[DataProvider('legality')]
    public function testLegality(string $cells, bool $legal): void
    {
        self::assertSame($legal, Board::fromString($cells)->isLegal());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function legality(): iterable
    {
        yield 'one X' => ['X--------', true];
        yield 'O moved first' => ['O--------', false];
        yield 'two X ahead' => ['XX-------', false];
        yield 'O ahead' => ['XOO------', false];
        yield 'X won on their move' => ['XXXOO----', true];
        yield 'X won but O has moved since' => ['XXXOO-O--', false];
        yield 'O won on their move' => ['OOOXX-X--', true];
        yield 'O won but counts say X moved last' => ['OOOXX-XX-', false];
        yield 'both won' => ['XXXOOO---', false];
        yield 'X double line through one last move' => ['XXXXOOXOO', true];
        yield 'full draw' => ['XOXXOOOXX', true];
    }

    public function testWinnerAndTerminal(): void
    {
        self::assertSame('X', Board::fromString('XXXOO----')->winner());
        self::assertSame('O', Board::fromString('X-OXO-O-X')->winner());
        self::assertNull(Board::fromString('XOXXOOOXX')->winner());
        self::assertTrue(Board::fromString('XOXXOOOXX')->isTerminal());
        self::assertTrue(Board::fromString('XOXXOOOXX')->isDraw());
        self::assertFalse(Board::fromString('XXXOO----')->isDraw());
    }

    public function testCompletingCells(): void
    {
        // X X -
        // O O -
        // - - -
        $board = Board::fromString('XX-OO----');

        self::assertSame([2], $board->completingCells('X'));
        self::assertSame([5], $board->completingCells('O'));
        self::assertSame([], Board::empty()->completingCells('X'));
    }

    public function testCompletingCellsListsEachCellOnce(): void
    {
        // X - X
        // - - -
        // X - X   centre completes both diagonals, edges complete the sides
        $board = Board::fromString('X-X---X-X');

        self::assertSame([1, 3, 4, 5, 7], $board->completingCells('X'));
    }

    public function testEightSymmetriesOfAnAsymmetricBoard(): void
    {
        $board = Board::fromString('XO-------');
        $variants = array_map(static fn (Board $b): string => $b->cells, $board->symmetries());

        self::assertCount(8, $variants);
        self::assertCount(8, array_unique($variants));
        self::assertSame('XO-------', $variants[0]);
        self::assertContains('--X--O---', $variants); // rotated 90 degrees clockwise
        self::assertContains('-OX------', $variants); // mirrored left to right
    }

    public function testCanonicalFormIsSharedByAllSymmetries(): void
    {
        $board = Board::fromString('XO--X---O');
        $canonical = $board->canonical()->cells;

        foreach ($board->symmetries() as $variant) {
            self::assertSame($canonical, $variant->canonical()->cells);
        }
    }

    public function testTransformCellFollowsThePiece(): void
    {
        $board = Board::fromString('X--------');

        foreach (array_keys(Board::SYMMETRIES) as $symmetry) {
            $moved = Board::transformCell($symmetry, 0);
            self::assertSame('X', $board->transform($symmetry)->cells[$moved]);
        }
    }

    public function testCellNames(): void
    {
        self::assertCount(9, Board::CELL_NAMES);
        self::assertSame('top_left', Board::CELL_NAMES[0]);
        self::assertSame('middle_middle', Board::CELL_NAMES[4]);
        self::assertSame('bottom_right', Board::CELL_NAMES[8]);
    }
}
