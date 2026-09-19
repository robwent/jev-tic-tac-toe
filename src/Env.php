<?php

declare(strict_types=1);

namespace JevTtt;

use RuntimeException;

/**
 * Minimal .env reader. Values are returned, never exported to the
 * process environment, so the API key cannot leak via phpinfo() or getenv().
 */
final class Env
{
    /** @var array<string, string>|null */
    private static ?array $values = null;

    public static function get(string $key): string
    {
        self::$values ??= self::load(dirname(__DIR__) . '/.env');

        $value = self::$values[$key] ?? '';
        if ($value === '') {
            throw new RuntimeException("{$key} is not set in .env");
        }

        return $value;
    }

    /** @return array<string, string> */
    private static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('.env not found. Copy .env.example to .env and add the key.');
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $values[trim($name)] = trim(trim($value), "\"'");
        }

        return $values;
    }
}
