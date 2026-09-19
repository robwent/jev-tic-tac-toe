<?php

declare(strict_types=1);

namespace JevTtt;

use UnexpectedValueException;

/**
 * The four answers for one position, validated against the board they were asked about.
 */
final readonly class ParsedAnswers
{
    /**
     * @param array<int, float> $moveProbabilities keyed by cell index
     * @param array{0: float, 1: float, 2: float} $outcomeProbabilities loses, draws, wins
     */
    private function __construct(
        public string $model,
        public int $move,
        public float $moveConfidence,
        public array $moveProbabilities,
        public float $canWinNow,
        public float $mustBlock,
        public float $outcomeScore,
        public float $outcomeConfidence,
        public array $outcomeProbabilities,
        public ?int $inputTokens,
    ) {
    }

    /** @param array<string, mixed> $response decoded response body */
    public static function fromResponse(array $response, Board $board): self
    {
        $answers = $response['answers'] ?? null;
        if (!is_array($answers)) {
            throw new UnexpectedValueException('Response has no answers.');
        }

        $move = $answers['move'] ?? [];
        $outcome = $answers['outcome'] ?? [];
        $cellByName = array_flip(Board::CELL_NAMES);

        $probabilities = [];
        foreach ((array) ($move['probabilities'] ?? []) as $name => $probability) {
            if (!isset($cellByName[$name])) {
                throw new UnexpectedValueException("Unknown cell in probabilities: {$name}");
            }
            $probabilities[$cellByName[$name]] = (float) $probability;
        }
        ksort($probabilities);

        if (array_keys($probabilities) !== $board->emptyCells()) {
            throw new UnexpectedValueException('Move probabilities do not cover exactly the empty cells.');
        }

        $choice = $cellByName[$move['choice'] ?? ''] ?? null;
        if ($choice === null || !isset($probabilities[$choice])) {
            throw new UnexpectedValueException('Move choice is not an empty cell.');
        }

        $levels = array_map(floatval(...), array_values((array) ($outcome['probabilities'] ?? [])));
        if (count($levels) !== 3) {
            throw new UnexpectedValueException('Outcome does not have three levels.');
        }

        foreach (['can_win_now', 'must_block'] as $id) {
            if (!is_numeric($answers[$id]['noul'] ?? null)) {
                throw new UnexpectedValueException("Missing noul: {$id}");
            }
        }
        if (!is_numeric($move['confidence'] ?? null) || !is_numeric($outcome['score'] ?? null)) {
            throw new UnexpectedValueException('Missing confidence or score.');
        }

        return new self(
            model: (string) ($response['model'] ?? ''),
            move: $choice,
            moveConfidence: (float) $move['confidence'],
            moveProbabilities: $probabilities,
            canWinNow: (float) $answers['can_win_now']['noul'],
            mustBlock: (float) $answers['must_block']['noul'],
            outcomeScore: (float) $outcome['score'],
            outcomeConfidence: (float) ($outcome['confidence'] ?? 0.0),
            outcomeProbabilities: $levels,
            inputTokens: isset($response['usage']['input_tokens']) ? (int) $response['usage']['input_tokens'] : null,
        );
    }
}
