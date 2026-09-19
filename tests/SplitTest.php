<?php

declare(strict_types=1);

namespace JevTtt\Tests;

use JevTtt\Benchmark\Split;
use JevTtt\Solver;
use PHPUnit\Framework\TestCase;

final class SplitTest extends TestCase
{
    public function testSymmetricVariantsNeverStraddleTheSplit(): void
    {
        $solver = new Solver();
        $split = new Split($solver);

        $counts = ['dev' => 0, 'test' => 0];
        foreach ($solver->nonTerminalPositions() as $board) {
            $name = $split->of($board);
            $counts[$name]++;

            foreach ($board->symmetries() as $variant) {
                self::assertSame($name, $split->of($variant), $board->cells);
            }
        }

        self::assertSame(4520, $counts['dev'] + $counts['test']);
        self::assertGreaterThan(0.10 * 4520, $counts['dev']);
        self::assertLessThan(0.20 * 4520, $counts['dev']);
    }

    public function testSplitIsStableAcrossInstances(): void
    {
        $solver = new Solver();
        $a = new Split($solver);
        $b = new Split(new Solver());

        foreach ($solver->nonTerminalPositions() as $board) {
            self::assertSame($a->of($board), $b->of($board));
        }
    }
}
