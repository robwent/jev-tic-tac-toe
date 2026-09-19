<?php

declare(strict_types=1);

namespace JevTtt;

/**
 * Exact result for one position, from the point of view of the player to move.
 */
final readonly class Evaluation
{
    /**
     * @param int $value 1 win, 0 draw, -1 loss under perfect play
     * @param int $depth plies to the end when the winner hurries and the loser stalls; 0 if terminal
     * @param array<int, array{value: int, depth: int}> $moves keyed by cell, value and depth after that move
     * @param list<int> $optimalMoves moves that keep $value
     * @param list<int> $bestMoves optimal moves that also keep $depth
     */
    public function __construct(
        public int $value,
        public int $depth,
        public array $moves,
        public array $optimalMoves,
        public array $bestMoves,
    ) {
    }

    /**
     * At least one legal move gives something away. Lost positions and dead
     * draws are not decisions, since every move is optimal there.
     */
    public function isDecision(): bool
    {
        return count($this->optimalMoves) < count($this->moves);
    }

    /**
     * Chance that a uniformly random legal move is optimal.
     */
    public function randomBaseline(): float
    {
        return $this->moves === [] ? 0.0 : count($this->optimalMoves) / count($this->moves);
    }
}
