<?php

declare(strict_types=1);

namespace JevTtt\Benchmark;

use JevTtt\Board;
use JevTtt\JevClient;
use JevTtt\QuestionBuilder;
use JevTtt\SearchPlayer;
use JevTtt\Solver;

/**
 * The follow-up experiments: prompt versions, perception on its own, seeing
 * against acting, repeatability, and code look-ahead over Jev's judgements.
 */
final class Extras
{
    private const string REPRESENTATION = 'lines';
    private const array KINDS = ['row', 'column', 'diagonal'];

    public function __construct(
        private readonly Solver $solver,
        private readonly Store $store,
        private readonly Split $split,
    ) {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return [
            'prompt_versions' => $this->promptVersions(),
            'perception' => $this->perception(),
            'acting' => [
                'v1' => $this->acting('v1'),
                'v2-priority' => $this->acting('v2-priority'),
            ],
            'repeatability' => $this->repeatability(),
            'look_ahead' => $this->lookAhead(),
        ];
    }

    /**
     * Every prompt version on every split it was run on in full.
     *
     * @return list<array<string, mixed>>
     */
    private function promptVersions(): array
    {
        $sizes = ['dev' => 0, 'test' => 0];
        foreach ($this->solver->nonTerminalPositions() as $board) {
            $sizes[$this->split->of($board)]++;
        }

        $out = [];
        foreach (QuestionBuilder::REPRESENTATIONS as $representation) {
            foreach (QuestionBuilder::VERSIONS as $version) {
                $rows = $this->store->rows(['representation' => $representation, 'prompt_version' => $version, 'option_order' => 'reading', 'repeat' => 0]);
                foreach (['test', 'dev'] as $splitName) {
                    $subset = array_filter($rows, static fn (array $row): bool => $row['split'] === $splitName);
                    if (count($subset) !== $sizes[$splitName]) {
                        continue;
                    }

                    $counts = ['n' => 0, 'good' => 0, 'missed_win' => 0, 'missed_block' => 0, 'allowed_fork' => 0, 'other_blunder' => 0];
                    foreach ($subset as $row) {
                        $board = Board::fromString($row['position']);
                        if (!$this->solver->evaluate($board)->isDecision()) {
                            continue;
                        }
                        $quality = $this->solver->classify($board, (int) $row['move']);
                        $counts['n']++;
                        $counts[$quality->isBlunder() ? $quality->value : 'good']++;
                    }

                    $out[] = [
                        'representation' => $representation,
                        'version' => $version,
                        'split' => $splitName,
                        'decisions' => $counts['n'],
                        'optimal' => self::rate($counts['good'], $counts['n']),
                        'missed_win' => self::rate($counts['missed_win'], $counts['n']),
                        'missed_block' => self::rate($counts['missed_block'], $counts['n']),
                        'allowed_fork' => self::rate($counts['allowed_fork'], $counts['n']),
                        'other_blunder' => self::rate($counts['other_blunder'], $counts['n']),
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * How well Jev answers the static judgements, over every reachable
     * position, finished games included. The wording was fixed in advance.
     *
     * @return array<string, mixed>
     */
    private function perception(): array
    {
        $judgements = $this->store->judgements(self::REPRESENTATION, QuestionBuilder::JUDGEMENT_VERSION, JevClient::MODEL);
        $stats = [];
        $add = static function (string $key, bool $truth, float $p) use (&$stats): void {
            $stats[$key] ??= ['n' => 0, 'right' => 0];
            $stats[$key]['n']++;
            $stats[$key]['right'] += (int) (($p >= 0.5) === $truth);
        };

        foreach ($this->solver->positions() as $board) {
            $judgement = $judgements[$board->cells] ?? null;
            if ($judgement === null) {
                continue;
            }
            foreach (['X', 'O'] as $mark) {
                $id = strtolower($mark);
                foreach (['line' => 3, 'threat' => 2] as $question => $count) {
                    $kinds = self::kinds($board, $mark, $count);
                    $p = $judgement["{$id}_{$question}"];
                    if ($kinds === []) {
                        $add("{$question}:no", false, $p);
                        if ($question === 'threat') {
                            $add('threat:no:' . self::falseAlarmCase($board, $mark), false, $p);
                        }
                    } elseif (count($kinds) === 1) {
                        $add("{$question}:yes:{$kinds[0]}", true, $p);
                    }
                }
            }
        }

        if ($stats === []) {
            return ['available' => false];
        }

        $out = ['available' => true, 'positions' => count($judgements), 'wording' => QuestionBuilder::JUDGEMENT_VERSION];
        foreach ($stats as $key => $s) {
            $out['rows'][$key] = ['n' => $s['n'], 'right' => self::rate($s['right'], $s['n'])];
        }

        return $out;
    }

    /**
     * When choosing a move: how often a lone win is taken, and a lone threat
     * blocked, by the kind of line involved. Test split.
     *
     * @return array<string, mixed>
     */
    private function acting(string $version): array
    {
        $take = $block = array_fill_keys(self::KINDS, ['n' => 0, 'did' => 0]);
        $rows = $this->store->rows(['representation' => self::REPRESENTATION, 'prompt_version' => $version, 'split' => 'test', 'option_order' => 'reading', 'repeat' => 0]);

        foreach ($rows as $row) {
            $board = Board::fromString($row['position']);
            $me = $board->toMove();
            $wins = $this->solver->immediateWins($board);
            $threats = $this->solver->opponentThreats($board);

            if (count($wins) === 1) {
                $kinds = self::kindsThrough($board, $me, $wins[0]);
                if (count($kinds) === 1) {
                    $take[$kinds[0]]['n']++;
                    $take[$kinds[0]]['did'] += (int) ((int) $row['move'] === $wins[0]);
                }
            } elseif ($wins === [] && count($threats) === 1) {
                $kinds = self::kindsThrough($board, $me === 'X' ? 'O' : 'X', $threats[0]);
                if (count($kinds) === 1) {
                    $block[$kinds[0]]['n']++;
                    $block[$kinds[0]]['did'] += (int) ((int) $row['move'] === $threats[0]);
                }
            }
        }

        $format = static fn (array $table): array => array_map(
            static fn (array $cell): array => ['n' => $cell['n'], 'rate' => self::rate($cell['did'], $cell['n'])],
            $table,
        );

        return ['rows' => count($rows), 'took_win' => $format($take), 'blocked' => $format($block)];
    }

    /**
     * Positions asked five times over: how often the pick is the same every time.
     *
     * @return array<string, mixed>
     */
    private function repeatability(): array
    {
        $byPosition = [];
        foreach (range(0, 4) as $repeat) {
            $rows = $this->store->rows(['representation' => self::REPRESENTATION, 'prompt_version' => 'v2-priority', 'option_order' => 'reading', 'repeat' => $repeat]);
            foreach ($rows as $row) {
                $byPosition[$row['position']][$repeat] = $row;
            }
        }
        $byPosition = array_filter($byPosition, static fn (array $rows): bool => count($rows) === 5);
        if ($byPosition === []) {
            return ['available' => false];
        }

        $buckets = ['0.8 or more' => [0, 0], '0.6 to 0.8' => [0, 0], '0.4 to 0.6' => [0, 0], 'under 0.4' => [0, 0]];
        $same = $mattered = 0;
        foreach ($byPosition as $cells => $rows) {
            $moves = array_map(static fn (array $row): int => (int) $row['move'], $rows);
            $counts = array_count_values($moves);
            arsort($counts);
            $usual = array_key_first($counts);
            $identical = count($counts) === 1;

            $mean = array_sum(array_map(
                static fn (array $row): float => (float) (json_decode($row['move_probabilities'], true)[$usual] ?? 0.0),
                $rows,
            )) / 5;
            $bucket = match (true) {
                $mean >= 0.8 => '0.8 or more',
                $mean >= 0.6 => '0.6 to 0.8',
                $mean >= 0.4 => '0.4 to 0.6',
                default => 'under 0.4',
            };
            $buckets[$bucket][0]++;
            $buckets[$bucket][1] += (int) $identical;
            $same += (int) $identical;

            $board = Board::fromString((string) $cells);
            $verdicts = array_unique(array_map(fn (int $move): bool => $this->solver->classify($board, $move)->isBlunder(), $moves));
            $mattered += (int) (count($verdicts) > 1);
        }

        $n = count($byPosition);

        return [
            'available' => true,
            'positions' => $n,
            'asks' => 5,
            'same_every_time' => self::rate($same, $n),
            'change_mattered' => self::rate($mattered, $n),
            'by_confidence' => array_map(
                static fn (string $label, array $b): array => ['confidence' => $label, 'n' => $b[0], 'same_every_time' => self::rate($b[1], $b[0])],
                array_keys($buckets),
                $buckets,
            ),
        ];
    }

    /**
     * Code search over Jev's stored judgements, ties broken by Jev's own
     * v2-priority pick. Test split. Measures Jev's perception plus our code.
     *
     * @return array<string, mixed>
     */
    private function lookAhead(): array
    {
        $judgements = $this->store->judgements(self::REPRESENTATION, QuestionBuilder::JUDGEMENT_VERSION, JevClient::MODEL);
        $priors = [];
        foreach ($this->store->rows(['representation' => self::REPRESENTATION, 'prompt_version' => 'v2-priority', 'split' => 'test', 'option_order' => 'reading', 'repeat' => 0]) as $row) {
            $priors[$row['position']] = ['move' => (int) $row['move'], 'p' => array_map(floatval(...), json_decode($row['move_probabilities'], true))];
        }
        if ($judgements === [] || $priors === []) {
            return ['available' => false];
        }

        $player = new SearchPlayer($judgements);
        $depths = [];
        foreach ([0, 1, 2, 3, 4, 9] as $depth) {
            $n = $good = 0;
            foreach ($priors as $cells => $prior) {
                $board = Board::fromString((string) $cells);
                if (!$this->solver->evaluate($board)->isDecision()) {
                    continue;
                }
                $move = $depth === 0 ? $prior['move'] : $player->choose($board, $depth, $prior['p']);
                $n++;
                $good += (int) !$this->solver->classify($board, $move)->isBlunder();
            }
            $depths[] = ['depth' => $depth, 'decisions' => $n, 'optimal' => self::rate($good, $n)];
        }

        return ['available' => true, 'split' => 'test', 'depths' => $depths];
    }

    /**
     * Kinds of line on which $mark has exactly $count marks and the rest empty.
     *
     * @return list<string>
     */
    private static function kinds(Board $board, string $mark, int $count): array
    {
        $found = [];
        foreach (Board::LINES as $name => $line) {
            $marks = $empty = 0;
            foreach ($line as $i) {
                $marks += (int) ($board->cells[$i] === $mark);
                $empty += (int) ($board->cells[$i] === '-');
            }
            if ($marks === $count && $marks + $empty === 3) {
                $found[self::kindOf($name)] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Kinds of line that $mark would complete by playing $cell.
     *
     * @return list<string>
     */
    private static function kindsThrough(Board $board, string $mark, int $cell): array
    {
        $found = [];
        foreach (Board::LINES as $name => $line) {
            if (!in_array($cell, $line, true)) {
                continue;
            }
            $marks = 0;
            foreach ($line as $i) {
                $marks += (int) ($board->cells[$i] === $mark);
            }
            if ($marks === 2) {
                $found[self::kindOf($name)] = true;
            }
        }

        return array_keys($found);
    }

    /** Why a board with no open two-in-a-line might still look like one. */
    private static function falseAlarmCase(Board $board, string $mark): string
    {
        if ($board->hasWon($mark)) {
            return 'already_three';
        }
        foreach (Board::LINES as $line) {
            $marks = $empty = 0;
            foreach ($line as $i) {
                $marks += (int) ($board->cells[$i] === $mark);
                $empty += (int) ($board->cells[$i] === '-');
            }
            if ($marks === 2 && $empty === 0) {
                return 'third_cell_taken';
            }
        }

        return 'no_pair';
    }

    private static function kindOf(string $lineName): string
    {
        return match (true) {
            str_starts_with($lineName, 'row') => 'row',
            str_starts_with($lineName, 'col') => 'column',
            default => 'diagonal',
        };
    }

    private static function rate(int $count, int $total): ?float
    {
        return $total === 0 ? null : round($count / $total, 4);
    }
}
