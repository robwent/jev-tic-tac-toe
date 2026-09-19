<?php

declare(strict_types=1);

namespace JevTtt\Tests;

use InvalidArgumentException;
use JevTtt\Board;
use JevTtt\QuestionBuilder;
use PHPUnit\Framework\TestCase;

final class QuestionBuilderTest extends TestCase
{
    // X X -
    // O O -
    // - - -
    private const string POSITION = 'XX-OO----';

    public function testMoveOptionsAreExactlyTheEmptyCellsInReadingOrder(): void
    {
        $built = (new QuestionBuilder())->build(Board::fromString(self::POSITION), 'cells');

        self::assertSame(
            ['top_right', 'middle_right', 'bottom_left', 'bottom_middle', 'bottom_right'],
            array_keys($built['questions']['move']['criteria']),
        );
    }

    public function testShuffleIsReproducibleAndKeepsTheSameOptions(): void
    {
        $builder = new QuestionBuilder();
        $board = Board::fromString('X--------');

        $reading = array_keys($builder->build($board, 'cells')['questions']['move']['criteria']);
        $first = array_keys($builder->build($board, 'cells', 7)['questions']['move']['criteria']);
        $again = array_keys($builder->build($board, 'cells', 7)['questions']['move']['criteria']);

        self::assertSame($first, $again);
        self::assertNotSame($reading, $first);
        self::assertEqualsCanonicalizing($reading, $first);
    }

    public function testQuestionsNameTheMarksAndAreSharedByAllRepresentations(): void
    {
        $builder = new QuestionBuilder();
        $board = Board::fromString('XX-OO-X--'); // O to move

        $questions = $builder->build($board, 'grid')['questions'];

        self::assertSame(['move', 'can_win_now', 'must_block', 'outcome'], array_keys($questions));
        self::assertStringStartsWith('O can complete', $questions['can_win_now']['instructions']);
        self::assertStringStartsWith('X will be able', $questions['must_block']['instructions']);
        self::assertSame(['O loses the game.', 'The game ends in a draw.', 'O wins the game.'], $questions['outcome']['criteria']);

        foreach (QuestionBuilder::REPRESENTATIONS as $representation) {
            self::assertSame($questions, $builder->build($board, $representation)['questions']);
        }
    }

    public function testGridState(): void
    {
        $state = (new QuestionBuilder())->build(Board::fromString(self::POSITION), 'grid')['state'];

        self::assertSame(['you_play' => 'X', 'board' => ['X X .', 'O O .', '. . .']], $state);
    }

    public function testCellsState(): void
    {
        $state = (new QuestionBuilder())->build(Board::fromString(self::POSITION), 'cells')['state'];

        self::assertSame(['you_play', 'board'], array_keys($state));
        self::assertSame(Board::CELL_NAMES, array_keys($state['board']));
        self::assertSame('X', $state['board']['top_left']);
        self::assertSame('O', $state['board']['middle_middle']);
        self::assertSame('empty', $state['board']['bottom_right']);
    }

    public function testLinesStateAddsTheEightLines(): void
    {
        $builder = new QuestionBuilder();
        $board = Board::fromString(self::POSITION);
        $state = $builder->build($board, 'lines')['state'];

        self::assertSame(['you_play', 'board', 'lines'], array_keys($state));
        self::assertSame($builder->build($board, 'cells')['state']['board'], $state['board']);
        self::assertSame(array_keys(Board::LINES), array_keys($state['lines']));
        self::assertSame(['top_left' => 'X', 'top_middle' => 'X', 'top_right' => 'empty'], $state['lines']['row_1']);
        self::assertSame(['top_right' => 'empty', 'middle_middle' => 'O', 'bottom_left' => 'empty'], $state['lines']['diag_anti']);
    }

    public function testRulesStateAddsRulesAndTurn(): void
    {
        $builder = new QuestionBuilder();
        $board = Board::fromString(self::POSITION);
        $state = $builder->build($board, 'rules')['state'];

        self::assertSame(['you_play', 'board', 'lines', 'rules', 'player_to_move'], array_keys($state));
        self::assertSame($builder->build($board, 'lines')['state']['lines'], $state['lines']);
        self::assertSame('X', $state['player_to_move']);
        self::assertSame(QuestionBuilder::RULES, $state['rules']);
    }

    public function testV2VersionsChangeOnlyTheMoveInstructionAndTheAssessment(): void
    {
        $builder = new QuestionBuilder();
        $board = Board::fromString(self::POSITION);
        $v1 = $builder->build($board, 'lines');
        $priority = $builder->build($board, 'lines', null, 'v2-priority');
        $chain = $builder->build($board, 'lines', null, 'v2-chain-oracle', ['can_win_now' => true, 'must_block' => false]);

        self::assertSame($v1['state'], $priority['state']);
        self::assertStringContainsString('When X has two marks in a line', $priority['questions']['move']['instructions']);
        self::assertStringContainsString('Otherwise, when O has two marks in a line', $priority['questions']['move']['instructions']);
        self::assertSame($v1['questions']['move']['criteria'], $priority['questions']['move']['criteria']);
        self::assertSame($v1['questions']['can_win_now'], $priority['questions']['can_win_now']);
        self::assertSame($v1['questions']['outcome'], $priority['questions']['outcome']);

        self::assertSame($priority['questions'], $chain['questions']);
        self::assertSame(
            [
                'X_can_complete_three_in_a_row_this_turn' => 'yes',
                'O_threatens_to_complete_three_in_a_row_next_turn' => 'no',
            ],
            $chain['state']['assessment'],
        );
        unset($chain['state']['assessment']);
        self::assertSame($v1['state'], $chain['state']);
    }

    public function testChainVersionsNeedAnAssessmentAndOthersRefuseOne(): void
    {
        $builder = new QuestionBuilder();
        $board = Board::fromString(self::POSITION);

        try {
            $builder->build($board, 'lines', null, 'v2-chain-jev');
            self::fail('A chain version was built with no assessment.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $builder->build($board, 'lines', null, 'v1', ['can_win_now' => true, 'must_block' => true]);
    }

    public function testRejectsUnknownRepresentationAndFinishedGames(): void
    {
        $builder = new QuestionBuilder();

        try {
            $builder->build(Board::empty(), 'ascii');
            self::fail('Unknown representation was accepted.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $builder->build(Board::fromString('XXXOO----'), 'cells');
    }
}
