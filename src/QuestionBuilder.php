<?php

declare(strict_types=1);

namespace JevTtt;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Board + representation => the state and questions sent to Jev.
 *
 * Every word Jev reads is defined here. Change any of it and bump
 * PROMPT_VERSION, and add the version to the log in NOTES.md.
 */
final class QuestionBuilder
{
    /** The version the live game and the headline benchmark use. */
    public const string PROMPT_VERSION = 'v1';

    /**
     * v1              "Choose the best cell", board only.
     * v2-priority     Move instruction spells out win first, then block.
     * v2-chain-jev    v2-priority, plus Jev's own can_win_now / must_block answers
     *                 from an earlier call written into the state as yes or no.
     * v2-chain-oracle v2-priority, plus the solver's true answers in the same place.
     */
    public const array VERSIONS = ['v1', 'v2-priority', 'v2-chain-jev', 'v2-chain-oracle'];

    /** From pure intuition to code doing most of the work. */
    public const array REPRESENTATIONS = ['grid', 'cells', 'lines', 'rules'];

    public const string RULES = 'Players take turns placing their mark in an empty cell. '
        . 'The first player to fill a whole row, column or diagonal with three of their marks wins. '
        . 'A full board with no completed line is a draw.';

    /**
     * Jev always plays the side to move.
     *
     * @param int|null $shuffleSeed null keeps the move options in reading order
     * @param array{can_win_now: bool, must_block: bool}|null $assessment required by the chain versions
     * @return array{state: array<string, mixed>, questions: array<string, array<string, mixed>>}
     */
    public function build(
        Board $board,
        string $representation,
        ?int $shuffleSeed = null,
        string $version = self::PROMPT_VERSION,
        ?array $assessment = null,
    ): array {
        if (!in_array($version, self::VERSIONS, true)) {
            throw new InvalidArgumentException("Unknown prompt version: {$version}");
        }
        if (str_starts_with($version, 'v2-chain') !== ($assessment !== null)) {
            throw new InvalidArgumentException('An assessment is required by the chain versions and only by them.');
        }
        if (!in_array($representation, self::REPRESENTATIONS, true)) {
            throw new InvalidArgumentException("Unknown representation: {$representation}");
        }
        if ($board->isTerminal()) {
            throw new InvalidArgumentException('There is nothing to ask about a finished game.');
        }

        $state = $this->state($board, $representation);
        if ($assessment !== null) {
            $me = $board->toMove();
            $opponent = $me === 'X' ? 'O' : 'X';
            $state['assessment'] = [
                "{$me}_can_complete_three_in_a_row_this_turn" => $assessment['can_win_now'] ? 'yes' : 'no',
                "{$opponent}_threatens_to_complete_three_in_a_row_next_turn" => $assessment['must_block'] ? 'yes' : 'no',
            ];
        }

        return [
            'state' => $state,
            'questions' => $this->questions($board, $shuffleSeed, $version),
        ];
    }

    /** Wording of the four static judgements used by SearchPlayer. */
    public const string JUDGEMENT_VERSION = 'j1';

    public const array JUDGEMENTS = ['x_line', 'o_line', 'x_threat', 'o_threat'];

    /**
     * Four yes or no questions about a board as it stands, with nothing about
     * whose turn it is or what to play. Works on finished games too.
     *
     * @return array{state: array<string, mixed>, questions: array<string, array<string, mixed>>}
     */
    public function buildJudgement(Board $board, string $representation = 'lines'): array
    {
        if (!in_array($representation, self::REPRESENTATIONS, true)) {
            throw new InvalidArgumentException("Unknown representation: {$representation}");
        }

        $state = $this->state($board, $representation === 'rules' ? 'lines' : $representation);
        unset($state['you_play']);

        $questions = [];
        foreach (['X', 'O'] as $mark) {
            $id = strtolower($mark);
            $questions["{$id}_line"] = [
                'type' => 'noul',
                'instructions' => "{$mark} has completed a line: all three cells of one line contain {$mark}.",
            ];
            $questions["{$id}_threat"] = [
                'type' => 'noul',
                'instructions' => "{$mark} has two marks in one line and the third cell of that line is empty.",
            ];
        }

        return ['state' => $state, 'questions' => $questions];
    }

    /** @return array<string, mixed> */
    private function state(Board $board, string $representation): array
    {
        $state = ['you_play' => $board->toMove()];

        if ($representation === 'grid') {
            $state['board'] = array_map(
                static fn (string $row): string => implode(' ', str_split(strtr($row, '-', '.'))),
                str_split($board->cells, 3),
            );

            return $state;
        }

        $cells = [];
        foreach (Board::CELL_NAMES as $i => $name) {
            $cells[$name] = self::label($board->cells[$i]);
        }
        $state['board'] = $cells;

        if ($representation === 'cells') {
            return $state;
        }

        $state['lines'] = [];
        foreach (Board::LINES as $lineName => $line) {
            foreach ($line as $i) {
                $state['lines'][$lineName][Board::CELL_NAMES[$i]] = self::label($board->cells[$i]);
            }
        }

        if ($representation === 'rules') {
            $state['rules'] = self::RULES;
            $state['player_to_move'] = $board->toMove();
        }

        return $state;
    }

    /** @return array<string, array<string, mixed>> */
    private function questions(Board $board, ?int $shuffleSeed, string $version): array
    {
        $me = $board->toMove();
        $opponent = $me === 'X' ? 'O' : 'X';

        $options = array_map(static fn (int $i): string => Board::CELL_NAMES[$i], $board->emptyCells());
        if ($shuffleSeed !== null) {
            $options = (new Randomizer(new Mt19937($shuffleSeed)))->shuffleArray($options);
        }

        $moveInstructions = $version === 'v1'
            ? "Choose the best cell for {$me} to play next."
            : "Choose the cell for {$me} to play next. "
                . "When {$me} has two marks in a line and the third cell of that line is empty, choose that empty cell. "
                . "Otherwise, when {$opponent} has two marks in a line and the third cell of that line is empty, choose that empty cell. "
                . "Otherwise choose the cell that gives {$me} the best chance of winning.";

        return [
            'move' => [
                'type' => 'choice',
                'instructions' => $moveInstructions,
                'criteria' => array_fill_keys($options, null),
            ],
            'can_win_now' => [
                'type' => 'noul',
                'instructions' => "{$me} can complete three in a row with a single move.",
            ],
            'must_block' => [
                'type' => 'noul',
                'instructions' => "{$opponent} will be able to complete three in a row on their next turn unless blocked.",
            ],
            'outcome' => [
                'type' => 'score',
                'instructions' => "Judge the final result of this game for {$me} when both players play perfectly from this position.",
                'criteria' => [
                    "{$me} loses the game.",
                    'The game ends in a draw.',
                    "{$me} wins the game.",
                ],
            ],
        ];
    }

    private static function label(string $cell): string
    {
        return $cell === '-' ? 'empty' : $cell;
    }
}
