<?php

declare(strict_types=1);

namespace JevTtt;

use PDO;

/**
 * SQLite-backed fixed-window counters: one per client per minute, one global per day.
 */
final class RateLimiter
{
    private readonly PDO $pdo;

    public function __construct(
        string $path,
        private readonly int $perClientPerMinute,
        private readonly int $globalPerDay,
    ) {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA busy_timeout = 2000');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS hits (bucket TEXT PRIMARY KEY, count INTEGER NOT NULL, expires_at INTEGER NOT NULL)');
    }

    /**
     * Counts the request and says whether it may proceed. Returns the number
     * of seconds to wait when it may not, or null when it may.
     */
    public function hit(string $client, ?int $now = null): ?int
    {
        $now ??= time();
        $minuteEnds = intdiv($now, 60) * 60 + 60;
        $dayEnds = intdiv($now, 86400) * 86400 + 86400;

        $this->pdo->beginTransaction();
        $this->pdo->prepare('DELETE FROM hits WHERE expires_at <= ?')->execute([$now]);

        // Hash the address so the file holds no raw IPs.
        $clientCount = $this->increment('client:' . hash('sha256', $client) . ':' . $minuteEnds, $minuteEnds);
        $globalCount = $this->increment('global:' . $dayEnds, $dayEnds);
        $this->pdo->commit();

        return match (true) {
            $globalCount > $this->globalPerDay => $dayEnds - $now,
            $clientCount > $this->perClientPerMinute => $minuteEnds - $now,
            default => null,
        };
    }

    private function increment(string $bucket, int $expiresAt): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO hits (bucket, count, expires_at) VALUES (?, 1, ?)
             ON CONFLICT (bucket) DO UPDATE SET count = count + 1 RETURNING count',
        );
        $statement->execute([$bucket, $expiresAt]);

        return (int) $statement->fetchColumn();
    }
}
