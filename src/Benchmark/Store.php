<?php

declare(strict_types=1);

namespace JevTtt\Benchmark;

use JevTtt\Board;
use JevTtt\JevResponse;
use JevTtt\ParsedAnswers;
use PDO;

/**
 * SQLite storage for benchmark results. Ground truth is not stored: reports
 * recompute it from the Solver so the truth logic can change without a rerun.
 */
final class Store
{
    private readonly PDO $pdo;

    public function __construct(string $path)
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS results (
                id INTEGER PRIMARY KEY,
                position TEXT NOT NULL,
                representation TEXT NOT NULL,
                prompt_version TEXT NOT NULL,
                model TEXT NOT NULL,
                option_order TEXT NOT NULL,
                repeat INTEGER NOT NULL DEFAULT 0,
                split TEXT NOT NULL,
                move INTEGER NOT NULL,
                move_confidence REAL NOT NULL,
                move_probabilities TEXT NOT NULL,
                can_win_now REAL NOT NULL,
                must_block REAL NOT NULL,
                outcome_score REAL NOT NULL,
                outcome_confidence REAL NOT NULL,
                outcome_probabilities TEXT NOT NULL,
                input_tokens INTEGER,
                latency_ms INTEGER NOT NULL,
                upstream_ms INTEGER,
                attempts INTEGER NOT NULL,
                request_id TEXT,
                request_json TEXT NOT NULL,
                raw_response TEXT NOT NULL,
                created_at TEXT NOT NULL,
                UNIQUE (position, representation, prompt_version, model, option_order, repeat)
            )
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS judgements (
                id INTEGER PRIMARY KEY,
                position TEXT NOT NULL,
                representation TEXT NOT NULL,
                judgement_version TEXT NOT NULL,
                model TEXT NOT NULL,
                x_line REAL NOT NULL,
                o_line REAL NOT NULL,
                x_threat REAL NOT NULL,
                o_threat REAL NOT NULL,
                input_tokens INTEGER,
                latency_ms INTEGER NOT NULL,
                upstream_ms INTEGER,
                request_id TEXT,
                request_json TEXT NOT NULL,
                raw_response TEXT NOT NULL,
                created_at TEXT NOT NULL,
                UNIQUE (position, representation, judgement_version, model)
            )
            SQL);
    }

    /**
     * Positions already answered for this configuration.
     *
     * @return array<string, true>
     */
    public function done(string $representation, string $promptVersion, string $model, string $optionOrder, int $repeat): array
    {
        $statement = $this->pdo->prepare(
            'SELECT position FROM results WHERE representation = ? AND prompt_version = ? AND model = ? AND option_order = ? AND repeat = ?',
        );
        $statement->execute([$representation, $promptVersion, $model, $optionOrder, $repeat]);

        return array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true);
    }

    /** @param array<string, mixed> $request exactly what was sent, minus the model field */
    public function save(
        Board $board,
        string $representation,
        string $promptVersion,
        string $optionOrder,
        int $repeat,
        string $split,
        array $request,
        JevResponse $response,
        ParsedAnswers $answers,
    ): void {
        $this->pdo->prepare(<<<'SQL'
            INSERT OR IGNORE INTO results (
                position, representation, prompt_version, model, option_order, repeat, split,
                move, move_confidence, move_probabilities, can_win_now, must_block,
                outcome_score, outcome_confidence, outcome_probabilities,
                input_tokens, latency_ms, upstream_ms, attempts, request_id,
                request_json, raw_response, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL)->execute([
            $board->cells,
            $representation,
            $promptVersion,
            $answers->model,
            $optionOrder,
            $repeat,
            $split,
            $answers->move,
            $answers->moveConfidence,
            json_encode($answers->moveProbabilities, JSON_THROW_ON_ERROR),
            $answers->canWinNow,
            $answers->mustBlock,
            $answers->outcomeScore,
            $answers->outcomeConfidence,
            json_encode($answers->outcomeProbabilities, JSON_THROW_ON_ERROR),
            $answers->inputTokens,
            $response->latencyMs,
            $response->upstreamMs,
            $response->attempts,
            $response->requestId,
            json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $response->body,
            gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Jev's stored judgements, keyed by position.
     *
     * @return array<string, array{x_line: float, o_line: float, x_threat: float, o_threat: float}>
     */
    public function judgements(string $representation, string $version, string $model): array
    {
        $statement = $this->pdo->prepare(
            'SELECT position, x_line, o_line, x_threat, o_threat FROM judgements WHERE representation = ? AND judgement_version = ? AND model = ?',
        );
        $statement->execute([$representation, $version, $model]);

        $judgements = [];
        foreach ($statement->fetchAll() as $row) {
            $judgements[$row['position']] = [
                'x_line' => (float) $row['x_line'],
                'o_line' => (float) $row['o_line'],
                'x_threat' => (float) $row['x_threat'],
                'o_threat' => (float) $row['o_threat'],
            ];
        }

        return $judgements;
    }

    /**
     * @param array<string, mixed> $request
     * @param array{x_line: float, o_line: float, x_threat: float, o_threat: float} $nouls
     */
    public function saveJudgement(Board $board, string $representation, string $version, string $model, array $request, JevResponse $response, array $nouls, ?int $inputTokens): void
    {
        $this->pdo->prepare(<<<'SQL'
            INSERT OR IGNORE INTO judgements (
                position, representation, judgement_version, model, x_line, o_line, x_threat, o_threat,
                input_tokens, latency_ms, upstream_ms, request_id, request_json, raw_response, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL)->execute([
            $board->cells, $representation, $version, $model,
            $nouls['x_line'], $nouls['o_line'], $nouls['x_threat'], $nouls['o_threat'],
            $inputTokens, $response->latencyMs, $response->upstreamMs, $response->requestId,
            json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $response->body,
            gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * @param array<string, string|int> $where column => value
     * @return list<array<string, mixed>>
     */
    public function rows(array $where = []): array
    {
        $sql = 'SELECT * FROM results';
        if ($where !== []) {
            foreach (array_keys($where) as $column) {
                if (preg_match('/\A[a-z_]+\z/', $column) !== 1) {
                    throw new \InvalidArgumentException("Bad column name: {$column}");
                }
            }

            $sql .= ' WHERE ' . implode(' AND ', array_map(
                static fn (string $column): string => "{$column} = ?",
                array_keys($where),
            ));
        }

        $statement = $this->pdo->prepare($sql . ' ORDER BY id');
        $statement->execute(array_values($where));

        return $statement->fetchAll();
    }
}
