<?php

declare(strict_types=1);

namespace JevTtt;

use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * The only thing the browser can ask for: Jev's move in a legal position where
 * it is Jev's turn. State and questions are built here, never taken from the
 * client, so this cannot be used as a general Jev proxy.
 */
final class MoveEndpoint
{
    /**
     * Best single-call combination on the dev split so far: 83.8% optimal on
     * decision positions, against 68.2% for `lines` under v1.
     */
    public const string REPRESENTATION = 'lines';
    public const string PROMPT_VERSION = 'v2-priority';

    public const int PER_CLIENT_PER_MINUTE = 30;
    public const int GLOBAL_PER_DAY = 2000;

    private const int MAX_BODY_BYTES = 256;

    public const int MAX_LOOK_AHEAD = 4;

    /**
     * @param \Closure(): JevClient $client built lazily so rejected requests never touch the API key
     * @param \Closure(): array<string, array{x_line: float, o_line: float, x_threat: float, o_threat: float}> $judgements
     *        Jev's stored yes or no judgements of every board, only loaded when look-ahead is asked for
     */
    public function __construct(
        private readonly Solver $solver,
        private readonly QuestionBuilder $questions,
        private readonly RateLimiter $limiter,
        private readonly \Closure $client,
        private readonly \Closure $judgements,
    ) {
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: array<string, mixed>}
     */
    public function handle(string $method, string $queryString, string $rawBody, string $clientAddress): array
    {
        if ($method !== 'POST') {
            return self::error(405, 'POST only.', ['Allow' => 'POST']);
        }
        if ($queryString !== '' || strlen($rawBody) > self::MAX_BODY_BYTES) {
            return self::error(400, 'Send a small JSON body and no query string.');
        }

        try {
            $input = json_decode($rawBody, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::error(400, 'Body must be a flat JSON object.');
        }

        $keys = is_array($input) ? array_keys($input) : [];
        sort($keys);

        // look_ahead is optional: how many moves code searches ahead over Jev's stored judgements.
        $lookAhead = 0;
        if ($keys === ['board', 'jev_plays', 'look_ahead']) {
            $lookAhead = $input['look_ahead'];
            if (!is_int($lookAhead) || $lookAhead < 0 || $lookAhead > self::MAX_LOOK_AHEAD) {
                return self::error(400, 'look_ahead must be a whole number from 0 to ' . self::MAX_LOOK_AHEAD . '.');
            }
            $keys = ['board', 'jev_plays'];
        }

        if (
            $keys !== ['board', 'jev_plays']
            || !is_string($input['board'])
            || !in_array($input['jev_plays'], ['X', 'O'], true)
        ) {
            return self::error(400, 'Body must be {"board": "<9 chars of X, O, ->", "jev_plays": "X" or "O"} with an optional "look_ahead".');
        }

        try {
            $board = Board::fromString($input['board']);
        } catch (InvalidArgumentException $e) {
            return self::error(400, $e->getMessage());
        }

        if (!$board->isLegal()) {
            return self::error(400, 'That position cannot be reached in a real game.');
        }
        if ($board->isTerminal()) {
            return self::error(400, 'The game is already over.');
        }
        if ($board->toMove() !== $input['jev_plays']) {
            return self::error(400, 'It is not Jev\'s turn.');
        }

        $wait = $this->limiter->hit($clientAddress);
        if ($wait !== null) {
            return self::error(429, 'Too many requests. Try again shortly.', ['Retry-After' => (string) $wait]);
        }

        try {
            $request = $this->questions->build($board, self::REPRESENTATION, null, self::PROMPT_VERSION);
            $response = ($this->client)()->ask($request['state'], $request['questions']);
            if (!$response->ok()) {
                return self::error($response->status === 429 ? 429 : 502, 'Jev is unavailable right now.');
            }
            $answers = ParsedAnswers::fromResponse($response->json() ?? [], $board);
        } catch (Throwable) {
            // Deliberately vague: upstream bodies and exception text stay server-side.
            return self::error(502, 'Jev is unavailable right now.');
        }

        $evaluation = $this->solver->evaluate($board);

        // Jev's own pick stands unless code look-ahead was asked for and disagrees.
        $move = $answers->move;
        if ($lookAhead > 0) {
            try {
                $move = (new SearchPlayer(($this->judgements)()))->choose($board, $lookAhead, $answers->moveProbabilities);
            } catch (Throwable) {
                $lookAhead = 0;
            }
        }

        return [
            'status' => 200,
            'headers' => [],
            'body' => [
                'move' => $move,
                'move_name' => Board::CELL_NAMES[$move],
                'jev_pick' => $answers->move,
                'look_ahead' => $lookAhead,
                'probabilities' => $answers->moveProbabilities,
                'confidence' => $answers->moveConfidence,
                'can_win_now' => $answers->canWinNow,
                'must_block' => $answers->mustBlock,
                'outcome' => [
                    'score' => $answers->outcomeScore,
                    'loss' => $answers->outcomeProbabilities[0],
                    'draw' => $answers->outcomeProbabilities[1],
                    'win' => $answers->outcomeProbabilities[2],
                ],
                'latency_ms' => $response->latencyMs,
                'upstream_ms' => $response->upstreamMs,
                'input_tokens' => $answers->inputTokens,
                'cost_usd' => ($answers->inputTokens ?? 0) * JevClient::USD_PER_INPUT_TOKEN,
                'model' => $answers->model,
                'representation' => self::REPRESENTATION,
                'prompt_version' => self::PROMPT_VERSION,
                'solver' => [
                    'value' => $evaluation->value,
                    'optimal_moves' => $evaluation->optimalMoves,
                    'best_moves' => $evaluation->bestMoves,
                    'is_decision' => $evaluation->isDecision(),
                    'can_win_now' => $this->solver->immediateWins($board) !== [],
                    'must_block' => $this->solver->opponentThreats($board) !== [],
                    'jev_move_quality' => $this->solver->classify($board, $move)->value,
                    'jev_pick_quality' => $this->solver->classify($board, $answers->move)->value,
                ],
            ],
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: array<string, mixed>}
     */
    private static function error(int $status, string $message, array $headers = []): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => ['error' => $message]];
    }
}
