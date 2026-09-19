<?php

declare(strict_types=1);

namespace JevTtt;

/**
 * Memoised minimax over the full game tree, plus the position enumerator.
 */
final class Solver
{
    /** @var array<string, Evaluation> */
    private array $memo = [];

    /** @var list<Board>|null */
    private ?array $positions = null;

    public function evaluate(Board $board): Evaluation
    {
        return $this->memo[$board->cells] ??= $this->search($board);
    }

    /**
     * Cells that complete three in a row for the player to move.
     *
     * @return list<int>
     */
    public function immediateWins(Board $board): array
    {
        return $board->isTerminal() ? [] : $board->completingCells($board->toMove());
    }

    /**
     * Cells where the opponent would complete three in a row if it were their
     * turn. Literal: still reported when the player to move can win first.
     *
     * @return list<int>
     */
    public function opponentThreats(Board $board): array
    {
        return $board->isTerminal() ? [] : $board->completingCells($board->toMove() === 'X' ? 'O' : 'X');
    }

    public function blockForced(Board $board): bool
    {
        return $this->opponentThreats($board) !== [] && $this->immediateWins($board) === [];
    }

    /**
     * After this move the opponent has a reply that leaves them two or more
     * winning cells while the mover has no win of their own to play first.
     */
    public function allowsFork(Board $board, int $cell): bool
    {
        $afterMove = $board->play($cell);
        if ($afterMove->isTerminal()) {
            return false;
        }

        foreach ($afterMove->emptyCells() as $reply) {
            $afterReply = $afterMove->play($reply);
            if ($afterReply->isTerminal()) {
                continue;
            }
            if (count($this->opponentThreats($afterReply)) >= 2 && $this->immediateWins($afterReply) === []) {
                return true;
            }
        }

        return false;
    }

    public function classify(Board $board, int $cell): MoveQuality
    {
        $evaluation = $this->evaluate($board);

        if (in_array($cell, $evaluation->optimalMoves, true)) {
            return $evaluation->value === 1 && !in_array($cell, $evaluation->bestMoves, true)
                ? MoveQuality::SlowWin
                : MoveQuality::Optimal;
        }

        return match (true) {
            $this->immediateWins($board) !== [] => MoveQuality::MissedWin,
            $this->blockForced($board) && !in_array($cell, $this->opponentThreats($board), true) => MoveQuality::MissedBlock,
            $this->allowsFork($board, $cell) => MoveQuality::AllowedFork,
            default => MoveQuality::OtherBlunder,
        };
    }

    /**
     * Every position reachable from the empty board, empty board first,
     * in order of pieces placed.
     *
     * @return list<Board>
     */
    public function positions(): array
    {
        if ($this->positions !== null) {
            return $this->positions;
        }

        $seen = ['---------' => Board::empty()];
        $frontier = [Board::empty()];
        while ($frontier !== []) {
            $next = [];
            foreach ($frontier as $board) {
                if ($board->isTerminal()) {
                    continue;
                }
                foreach ($board->emptyCells() as $cell) {
                    $child = $board->play($cell);
                    if (!isset($seen[$child->cells])) {
                        $seen[$child->cells] = $child;
                        $next[] = $child;
                    }
                }
            }
            $frontier = $next;
        }

        return $this->positions = array_values($seen);
    }

    /** @return list<Board> */
    public function nonTerminalPositions(): array
    {
        return array_values(array_filter(
            $this->positions(),
            static fn (Board $board): bool => !$board->isTerminal(),
        ));
    }

    /**
     * Number of distinct complete games (move sequences) from the empty board.
     */
    public function countGames(): int
    {
        $memo = [];
        $count = static function (Board $board) use (&$count, &$memo): int {
            if ($board->isTerminal()) {
                return 1;
            }

            return $memo[$board->cells] ??= array_sum(array_map(
                static fn (int $cell): int => $count($board->play($cell)),
                $board->emptyCells(),
            ));
        };

        return $count(Board::empty());
    }

    private function search(Board $board): Evaluation
    {
        if ($board->isTerminal()) {
            // A finished game with a winner was won by the player who just moved.
            return new Evaluation($board->winner() === null ? 0 : -1, 0, [], [], []);
        }

        $moves = [];
        $bestRank = null;
        foreach ($board->emptyCells() as $cell) {
            $reply = $this->evaluate($board->play($cell));
            $moves[$cell] = ['value' => -$reply->value, 'depth' => $reply->depth + 1];
            $bestRank = max($bestRank ?? PHP_INT_MIN, self::rank($moves[$cell]));
        }

        $value = $bestRank <=> 0;
        $optimal = $best = [];
        foreach ($moves as $cell => $move) {
            if ($move['value'] === $value) {
                $optimal[] = $cell;
            }
            if (self::rank($move) === $bestRank) {
                $best[] = $cell;
            }
        }

        return new Evaluation($value, $moves[$best[0]]['depth'], $moves, $optimal, $best);
    }

    /**
     * Higher is better: any win beats any draw beats any loss, wins prefer
     * fewer plies, losses prefer more.
     *
     * @param array{value: int, depth: int} $move
     */
    private static function rank(array $move): int
    {
        return $move['value'] * (100 - $move['depth']);
    }
}
