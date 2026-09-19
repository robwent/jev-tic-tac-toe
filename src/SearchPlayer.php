<?php

declare(strict_types=1);

namespace JevTtt;

use RuntimeException;

/**
 * Code does the looking ahead, Jev does the looking. This class knows how to
 * place marks and whose turn it is, and nothing about lines: whether a line is
 * complete, or one move from complete, is only ever taken from Jev's stored
 * yes or no judgements of each board.
 */
final class SearchPlayer
{
    /** @var array<string, float> */
    private array $memo = [];

    /**
     * @param array<string, array{x_line: float, o_line: float, x_threat: float, o_threat: float}> $judgements keyed by position
     */
    public function __construct(
        private readonly array $judgements,
        private readonly float $threatThreshold = 0.5,
        private readonly float $lineThreshold = 0.5,
    ) {
    }

    /**
     * @param int $depth plies of look-ahead, 1 or more
     * @param array<int, float> $prior Jev's own move probabilities, used only to break ties
     */
    public function choose(Board $board, int $depth, array $prior = []): int
    {
        $best = null;
        foreach ($this->bestMoves($board, $depth) as $cell) {
            if ($best === null || ($prior[$cell] ?? 0.0) > ($prior[$best] ?? 0.0)) {
                $best = $cell;
            }
        }

        return $best ?? throw new RuntimeException('No empty cell to play.');
    }

    /**
     * Every move the search rates equal best. Which of them gets played is
     * down to Jev's own preference on the day.
     *
     * @return list<int>
     */
    public function bestMoves(Board $board, int $depth): array
    {
        $values = [];
        foreach ($board->emptyCells() as $cell) {
            $values[$cell] = -$this->value(self::place($board, $cell), $depth - 1, 1);
        }
        if ($values === []) {
            return [];
        }

        $top = max($values);

        return array_keys(array_filter($values, static fn (float $value): bool => abs($value - $top) <= 1e-9));
    }

    /**
     * Value for the player to move: positive is good, sooner is stronger.
     */
    private function value(Board $board, int $depth, int $ply): float
    {
        return $this->memo["{$board->cells}:{$depth}:{$ply}"] ??= $this->search($board, $depth, $ply);
    }

    private function search(Board $board, int $depth, int $ply): float
    {
        $judgement = $this->judgements[$board->cells]
            ?? throw new RuntimeException("Jev has not judged {$board->cells}.");

        $me = strtolower($board->toMove());
        $opponent = $me === 'x' ? 'o' : 'x';
        $weight = 1.0 - 0.01 * $ply;

        if ($judgement["{$opponent}_line"] >= $this->lineThreshold) {
            return -$weight;
        }

        $empty = $board->emptyCells();
        if ($empty === []) {
            return 0.0;
        }

        if ($depth === 0) {
            return $judgement["{$me}_threat"] >= $this->threatThreshold ? $weight - 0.01 : 0.0;
        }

        $best = -INF;
        foreach ($empty as $cell) {
            $best = max($best, -$this->value(self::place($board, $cell), $depth - 1, $ply + 1));
        }

        return $best;
    }

    /**
     * Board::play() refuses to move in a finished game, and it decides that by
     * looking at lines. The search must not rely on that, so it places marks itself.
     */
    private static function place(Board $board, int $cell): Board
    {
        return Board::fromString(substr_replace($board->cells, $board->toMove(), $cell, 1));
    }
}
