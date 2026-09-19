<?php

declare(strict_types=1);

namespace JevTtt\Benchmark;

use JevTtt\Board;
use JevTtt\Solver;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Fixed dev/test split over symmetry classes. A position belongs to the set of
 * its canonical form, so rotations and reflections never straddle the split.
 * Prompt wording is tuned on dev only. Headline numbers come from test only.
 */
final class Split
{
    public const int SEED = 20260919;
    public const float DEV_FRACTION = 0.15;

    /** @var array<string, true> canonical strings in the dev set */
    private array $dev = [];

    public function __construct(Solver $solver)
    {
        $classes = [];
        foreach ($solver->nonTerminalPositions() as $board) {
            $classes[$board->canonical()->cells] = true;
        }

        $classes = array_keys($classes);
        sort($classes, SORT_STRING);
        $classes = (new Randomizer(new Mt19937(self::SEED)))->shuffleArray($classes);

        $devCount = (int) round(count($classes) * self::DEV_FRACTION);
        $this->dev = array_fill_keys(array_slice($classes, 0, $devCount), true);
    }

    public function of(Board $board): string
    {
        return isset($this->dev[$board->canonical()->cells]) ? 'dev' : 'test';
    }
}
