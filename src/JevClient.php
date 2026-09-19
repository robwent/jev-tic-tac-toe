<?php

declare(strict_types=1);

namespace JevTtt;

use ArrayObject;
use CurlHandle;
use RuntimeException;

/**
 * curl wrapper for TypeSafe's System One endpoint, pinned to one model version.
 *
 * 429, 529, 5xx and connection failures are retried with exponential backoff.
 * A retry-after header is honoured when present, but the API does not document one.
 */
final class JevClient
{
    public const string ENDPOINT = 'https://api.typesafe.ai/v1/systemone';
    public const string MODEL = 'jev-1.13.0';
    public const float USD_PER_INPUT_TOKEN = 0.042 / 1_000_000;

    private const int MAX_ATTEMPTS = 5;

    /** Gap between request starts. 60 ms is 1,000 a minute, under the 1,200 limit. */
    private const float MIN_INTERVAL_SECONDS = 0.06;

    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 30,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('Missing TypeSafe API key.');
        }
    }

    /**
     * @param array<string, mixed>|string $state
     * @param array<string, array<string, mixed>> $questions
     */
    public function ask(array|string $state, array $questions): JevResponse
    {
        $result = null;
        $this->askMany(
            [['state' => $state, 'questions' => $questions]],
            static function (int|string $key, JevResponse $response) use (&$result): void {
                $result = $response;
            },
            1,
        );

        return $result ?? throw new RuntimeException('Request produced no response.');
    }

    /**
     * Runs requests through a rolling curl_multi window and hands each final
     * response to $onResponse as soon as it is settled.
     *
     * @param array<int|string, array{state: array<string, mixed>|string, questions: array<string, array<string, mixed>>}> $requests
     * @param callable(int|string, JevResponse): void $onResponse
     */
    public function askMany(array $requests, callable $onResponse, int $concurrency = 10): void
    {
        $queue = [];
        foreach ($requests as $key => $request) {
            $queue[] = ['key' => $key, 'request' => $request, 'attempts' => 0, 'notBefore' => 0.0];
        }

        $multi = curl_multi_init();
        $active = [];
        $lastStart = 0.0;

        while ($queue !== [] || $active !== []) {
            $now = microtime(true);

            while (count($active) < $concurrency && $now - $lastStart >= self::MIN_INTERVAL_SECONDS) {
                $index = array_find_key($queue, static fn (array $job): bool => $job['notBefore'] <= $now);
                if ($index === null) {
                    break;
                }

                $job = $queue[$index];
                unset($queue[$index]);
                $job['attempts']++;
                $job['headers'] = new ArrayObject();
                $job['handle'] = $this->handle($job['request'], $job['headers']);

                curl_multi_add_handle($multi, $job['handle']);
                $active[spl_object_id($job['handle'])] = $job;
                $lastStart = $now;
            }

            curl_multi_exec($multi, $running);
            if ($active !== []) {
                curl_multi_select($multi, 0.02);
            } else {
                usleep(20_000);
            }

            while ($done = curl_multi_info_read($multi)) {
                $id = spl_object_id($done['handle']);
                $job = $active[$id];
                unset($active[$id]);

                $response = $this->response($job, $done['result']);
                curl_multi_remove_handle($multi, $done['handle']);

                if (self::retryable($response) && $job['attempts'] < self::MAX_ATTEMPTS) {
                    $job['notBefore'] = microtime(true) + self::backoff($job['attempts'], $job['headers']);
                    unset($job['handle']);
                    $queue[] = $job;

                    continue;
                }

                $onResponse($job['key'], $response);
            }
        }

        curl_multi_close($multi);
    }

    /**
     * @param array{state: array<string, mixed>|string, questions: array<string, array<string, mixed>>} $request
     * @param ArrayObject<string, string> $headers filled as the response arrives
     */
    private function handle(array $request, ArrayObject $headers): CurlHandle
    {
        $handle = curl_init(self::ENDPOINT);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['model' => self::MODEL] + $request, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use ($headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);

        return $handle;
    }

    /** @param array<string, mixed> $job */
    private function response(array $job, int $curlResult): JevResponse
    {
        $handle = $job['handle'];
        $headers = $job['headers'];
        $upstream = $headers['x-envoy-upstream-service-time'] ?? null;

        return new JevResponse(
            status: $curlResult === CURLE_OK ? (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) : 0,
            body: (string) curl_multi_getcontent($handle),
            latencyMs: (int) round(curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000),
            upstreamMs: is_numeric($upstream) ? (int) $upstream : null,
            requestId: $headers['x-typesafe-request-id'] ?? null,
            attempts: $job['attempts'],
            error: $curlResult === CURLE_OK ? null : curl_strerror($curlResult),
        );
    }

    private static function retryable(JevResponse $response): bool
    {
        return $response->status === 0 || $response->status === 429 || $response->status >= 500;
    }

    /** @param ArrayObject<string, string> $headers */
    private static function backoff(int $attempts, ArrayObject $headers): float
    {
        $retryAfter = $headers['retry-after'] ?? null;
        if (is_numeric($retryAfter)) {
            return min(60.0, (float) $retryAfter);
        }

        return min(30.0, 0.5 * 2 ** ($attempts - 1)) + random_int(0, 250) / 1000;
    }
}
