<?php

declare(strict_types=1);

// One real call to Jev with all three question types. Saves the raw exchange
// to storage/smoke-test.json so JevClient is built against what comes back.

use JevTtt\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

const ENDPOINT = 'https://api.typesafe.ai/v1/systemone';
const MODEL = 'jev-1.13.0';

// X X -
// O O -     X to move: wins at top_right, and must otherwise block middle_right.
// - - -
$payload = [
    'model' => MODEL,
    'state' => [
        'game' => 'tic-tac-toe',
        'you_play' => 'X',
        'player_to_move' => 'X',
        'board' => [
            'top_left' => 'X',
            'top_middle' => 'X',
            'top_right' => 'empty',
            'middle_left' => 'O',
            'middle_middle' => 'O',
            'middle_right' => 'empty',
            'bottom_left' => 'empty',
            'bottom_middle' => 'empty',
            'bottom_right' => 'empty',
        ],
    ],
    'questions' => [
        'move' => [
            'type' => 'choice',
            'instructions' => 'Choose the best cell for X to play next.',
            'criteria' => [
                'top_right' => null,
                'middle_right' => null,
                'bottom_left' => null,
                'bottom_middle' => null,
                'bottom_right' => null,
            ],
        ],
        'can_win_now' => [
            'type' => 'noul',
            'instructions' => 'The player to move can complete three in a row with a single move.',
        ],
        'must_block' => [
            'type' => 'noul',
            'instructions' => 'The opponent will be able to complete three in a row on their next turn unless blocked.',
        ],
        'outcome' => [
            'type' => 'score',
            'instructions' => 'Judge the result of this game for the player to move when both players play perfectly.',
            'criteria' => [
                'The player to move loses the game.',
                'The game ends in a draw.',
                'The player to move wins the game.',
            ],
        ],
    ],
];

$responseHeaders = [];
$ch = curl_init(ENDPOINT);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . Env::get('TYPESAFE_API_KEY'),
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }

        return strlen($line);
    },
]);

$body = curl_exec($ch);
$info = curl_getinfo($ch);
$error = curl_error($ch);

if ($body === false) {
    fwrite(STDERR, "curl failed: {$error}\n");
    exit(1);
}

$decoded = json_decode($body, true);

$record = [
    'requested_at' => date(DATE_ATOM),
    'request' => $payload,
    'http_status' => $info['http_code'],
    'latency_ms' => (int) round($info['total_time'] * 1000),
    'response_headers' => $responseHeaders,
    'response' => $decoded ?? $body,
    'raw_body' => $body,
];

$dir = dirname(__DIR__) . '/storage';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents($dir . '/smoke-test.json', $json . "\n");

echo $json, "\n";
exit($info['http_code'] === 200 ? 0 : 1);
