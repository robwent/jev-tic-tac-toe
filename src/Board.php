<?php

declare(strict_types=1);

namespace JevTtt;

use InvalidArgumentException;

/**
 * A position as a 9-character string of X, O and -, index 0 = top left,
 * reading order. X always moves first.
 */
final readonly class Board
{
    public const array CELL_NAMES = [
        'top_left', 'top_middle', 'top_right',
        'middle_left', 'middle_middle', 'middle_right',
        'bottom_left', 'bottom_middle', 'bottom_right',
    ];

    public const array LINES = [
        'row_1' => [0, 1, 2],
        'row_2' => [3, 4, 5],
        'row_3' => [6, 7, 8],
        'col_1' => [0, 3, 6],
        'col_2' => [1, 4, 7],
        'col_3' => [2, 5, 8],
        'diag_main' => [0, 4, 8],
        'diag_anti' => [2, 4, 6],
    ];

    /**
     * The eight rotations and reflections. Each is a permutation where
     * transformed[i] = original[permutation[i]].
     */
    public const array SYMMETRIES = [
        'identity' => [0, 1, 2, 3, 4, 5, 6, 7, 8],
        'rotate_90' => [6, 3, 0, 7, 4, 1, 8, 5, 2],
        'rotate_180' => [8, 7, 6, 5, 4, 3, 2, 1, 0],
        'rotate_270' => [2, 5, 8, 1, 4, 7, 0, 3, 6],
        'mirror_horizontal' => [2, 1, 0, 5, 4, 3, 8, 7, 6],
        'mirror_vertical' => [6, 7, 8, 3, 4, 5, 0, 1, 2],
        'transpose' => [0, 3, 6, 1, 4, 7, 2, 5, 8],
        'anti_transpose' => [8, 5, 2, 7, 4, 1, 6, 3, 0],
    ];

    private function __construct(public string $cells)
    {
    }

    public static function empty(): self
    {
        return new self('---------');
    }

    public static function fromString(string $cells): self
    {
        if (preg_match('/\A[XO-]{9}\z/', $cells) !== 1) {
            throw new InvalidArgumentException('A board is exactly 9 characters of X, O or -.');
        }

        return new self($cells);
    }

    public function count(string $mark): int
    {
        return substr_count($this->cells, $mark);
    }

    public function toMove(): string
    {
        return $this->count('X') === $this->count('O') ? 'X' : 'O';
    }

    public function hasWon(string $mark): bool
    {
        foreach (self::LINES as [$a, $b, $c]) {
            if ($this->cells[$a] === $mark && $this->cells[$b] === $mark && $this->cells[$c] === $mark) {
                return true;
            }
        }

        return false;
    }

    public function winner(): ?string
    {
        return match (true) {
            $this->hasWon('X') => 'X',
            $this->hasWon('O') => 'O',
            default => null,
        };
    }

    public function isFull(): bool
    {
        return !str_contains($this->cells, '-');
    }

    public function isDraw(): bool
    {
        return $this->isFull() && $this->winner() === null;
    }

    public function isTerminal(): bool
    {
        return $this->isFull() || $this->winner() !== null;
    }

    /**
     * Counts are valid, at most one side has won, and the winner made the last move.
     */
    public function isLegal(): bool
    {
        $x = $this->count('X');
        $o = $this->count('O');
        if ($x !== $o && $x !== $o + 1) {
            return false;
        }

        $xWon = $this->hasWon('X');
        $oWon = $this->hasWon('O');

        return match (true) {
            $xWon && $oWon => false,
            $xWon => $x === $o + 1,
            $oWon => $x === $o,
            default => true,
        };
    }

    /** @return list<int> */
    public function emptyCells(): array
    {
        $empty = [];
        for ($i = 0; $i < 9; $i++) {
            if ($this->cells[$i] === '-') {
                $empty[] = $i;
            }
        }

        return $empty;
    }

    public function play(int $cell): self
    {
        if ($this->isTerminal()) {
            throw new InvalidArgumentException('The game is already over.');
        }
        if ($cell < 0 || $cell > 8 || $this->cells[$cell] !== '-') {
            throw new InvalidArgumentException("Cell {$cell} is not an empty cell.");
        }

        return new self(substr_replace($this->cells, $this->toMove(), $cell, 1));
    }

    /**
     * Empty cells that would complete three in a row for the given mark.
     *
     * @return list<int>
     */
    public function completingCells(string $mark): array
    {
        $cells = [];
        foreach (self::LINES as $line) {
            $marks = 0;
            $gap = null;
            foreach ($line as $i) {
                if ($this->cells[$i] === $mark) {
                    $marks++;
                } elseif ($this->cells[$i] === '-') {
                    $gap = $i;
                }
            }
            if ($marks === 2 && $gap !== null) {
                $cells[$gap] = true;
            }
        }

        ksort($cells);

        return array_keys($cells);
    }

    public function transform(string $symmetry): self
    {
        $cells = '';
        foreach (self::SYMMETRIES[$symmetry] as $source) {
            $cells .= $this->cells[$source];
        }

        return new self($cells);
    }

    /**
     * Where a cell of the original board ends up after the symmetry is applied.
     */
    public static function transformCell(string $symmetry, int $cell): int
    {
        $target = array_search($cell, self::SYMMETRIES[$symmetry], true);
        if (!is_int($target)) {
            throw new InvalidArgumentException("Cell {$cell} is not on the board.");
        }

        return $target;
    }

    /** @return list<self> identity first */
    public function symmetries(): array
    {
        return array_map($this->transform(...), array_keys(self::SYMMETRIES));
    }

    /**
     * The lexicographically smallest of the eight symmetric variants.
     */
    public function canonical(): self
    {
        $best = $this;
        foreach ($this->symmetries() as $variant) {
            if ($variant->cells < $best->cells) {
                $best = $variant;
            }
        }

        return $best;
    }
}
