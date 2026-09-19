<?php

declare(strict_types=1);

namespace JevTtt\Benchmark;

use JevTtt\Board;
use JevTtt\JevClient;
use JevTtt\JevResponse;
use JevTtt\ParsedAnswers;
use JevTtt\QuestionBuilder;
use JevTtt\Solver;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;

/**
 * Asks Jev about positions and stores the answers. Always resumable: rows
 * that already exist for the same configuration are skipped.
 */
final class Runner
{
    public function __construct(
        private readonly Solver $solver,
        private readonly Split $split,
        private readonly QuestionBuilder $questions,
        private readonly JevClient $client,
        private readonly Store $store,
    ) {
    }

    /**
     * @param string $splitFilter dev, test or all
     * @param int|null $limit a fixed seeded sample of this many positions, the same ones on every run
     * @param int|null $shuffleSeed null keeps move options in reading order
     * @param callable(int $settled, int $total): void|null $onProgress
     * @return array{selected: int, skipped: int, saved: int, failed: int, input_tokens: int, seconds: float, errors: list<string>}
     */
    public function run(
        string $representation,
        string $splitFilter = 'all',
        ?int $limit = null,
        ?int $shuffleSeed = null,
        int $repeat = 0,
        int $concurrency = 10,
        ?callable $onProgress = null,
        string $promptVersion = QuestionBuilder::PROMPT_VERSION,
    ): array {
        $optionOrder = $shuffleSeed === null ? 'reading' : "shuffle:{$shuffleSeed}";

        $boards = array_values(array_filter(
            $this->solver->nonTerminalPositions(),
            fn (Board $board): bool => $splitFilter === 'all' || $this->split->of($board) === $splitFilter,
        ));
        if ($limit !== null) {
            $boards = array_slice((new Randomizer(new Mt19937(Split::SEED)))->shuffleArray($boards), 0, $limit);
        }

        $done = $this->store->done($representation, $promptVersion, JevClient::MODEL, $optionOrder, $repeat);
        $firstCall = $promptVersion === 'v2-chain-jev' ? $this->firstCallAnswers($representation) : [];

        $pending = [];
        $requests = [];
        foreach ($boards as $board) {
            if (isset($done[$board->cells])) {
                continue;
            }
            $pending[$board->cells] = $board;
            // Per-position seed so each position gets its own order, reproducibly.
            $seed = $shuffleSeed === null ? null : $shuffleSeed + crc32($board->cells);
            $assessment = match ($promptVersion) {
                'v2-chain-oracle' => [
                    'can_win_now' => $this->solver->immediateWins($board) !== [],
                    'must_block' => $this->solver->opponentThreats($board) !== [],
                ],
                'v2-chain-jev' => $firstCall[$board->cells]
                    ?? throw new \RuntimeException("Run v1 for {$representation} first: no stored answers for {$board->cells}."),
                default => null,
            };
            $requests[$board->cells] = $this->questions->build($board, $representation, $seed, $promptVersion, $assessment);
        }

        $summary = [
            'selected' => count($boards),
            'skipped' => count($boards) - count($pending),
            'saved' => 0,
            'failed' => 0,
            'input_tokens' => 0,
            'seconds' => 0.0,
            'errors' => [],
        ];
        $started = microtime(true);
        $settled = 0;

        $this->client->askMany(
            $requests,
            function (int|string $cells, JevResponse $response) use (
                &$summary,
                &$settled,
                $pending,
                $requests,
                $representation,
                $optionOrder,
                $repeat,
                $onProgress,
                $promptVersion,
            ): void {
                $board = $pending[$cells];

                try {
                    if (!$response->ok()) {
                        throw new \RuntimeException(
                            "HTTP {$response->status} " . ($response->error ?? substr($response->body, 0, 200)),
                        );
                    }

                    $answers = ParsedAnswers::fromResponse($response->json() ?? [], $board);
                    $this->store->save(
                        $board,
                        $representation,
                        $promptVersion,
                        $optionOrder,
                        $repeat,
                        $this->split->of($board),
                        $requests[$cells],
                        $response,
                        $answers,
                    );
                    $summary['saved']++;
                    $summary['input_tokens'] += $answers->inputTokens ?? 0;
                } catch (Throwable $e) {
                    $summary['failed']++;
                    $summary['errors'][] = "{$cells}: {$e->getMessage()}";
                }

                if ($onProgress !== null) {
                    $onProgress(++$settled, count($pending));
                }
            },
            $concurrency,
        );

        $summary['seconds'] = round(microtime(true) - $started, 1);

        return $summary;
    }

    /**
     * The first call of the two-call design is exactly the v1 request, so its
     * stored Noul answers are reused instead of asking again. Cut at 0.5.
     *
     * @return array<string, array{can_win_now: bool, must_block: bool}>
     */
    private function firstCallAnswers(string $representation): array
    {
        $answers = [];
        $rows = $this->store->rows([
            'representation' => $representation,
            'prompt_version' => 'v1',
            'model' => JevClient::MODEL,
            'option_order' => 'reading',
            'repeat' => 0,
        ]);
        foreach ($rows as $row) {
            $answers[$row['position']] = [
                'can_win_now' => $row['can_win_now'] >= 0.5,
                'must_block' => $row['must_block'] >= 0.5,
            ];
        }

        return $answers;
    }
}
