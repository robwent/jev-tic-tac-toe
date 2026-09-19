<?php

declare(strict_types=1);

use JevTtt\Benchmark\Store;
use JevTtt\Env;
use JevTtt\JevClient;
use JevTtt\MoveEndpoint;
use JevTtt\QuestionBuilder;
use JevTtt\RateLimiter;
use JevTtt\Solver;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

ini_set('display_errors', '0');

try {
    $endpoint = new MoveEndpoint(
        new Solver(),
        new QuestionBuilder(),
        new RateLimiter($root . '/storage/ratelimit.sqlite', MoveEndpoint::PER_CLIENT_PER_MINUTE, MoveEndpoint::GLOBAL_PER_DAY),
        static fn (): JevClient => new JevClient(Env::get('TYPESAFE_API_KEY'), timeoutSeconds: 10),
        static fn (): array => (new Store($root . '/storage/results.sqlite'))
            ->judgements(MoveEndpoint::REPRESENTATION, QuestionBuilder::JUDGEMENT_VERSION, JevClient::MODEL),
    );

    $result = $endpoint->handle(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $_SERVER['QUERY_STRING'] ?? '',
        (string) file_get_contents('php://input', length: 4096),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    );
} catch (Throwable $e) {
    error_log('move.php: ' . $e::class);
    $result = ['status' => 500, 'headers' => [], 'body' => ['error' => 'Something went wrong.']];
}

http_response_code($result['status']);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
foreach ($result['headers'] as $name => $value) {
    header("{$name}: {$value}");
}

echo json_encode($result['body'], JSON_THROW_ON_ERROR);
