<?php

declare(strict_types=1);

namespace JevTtt\Tests;

use JevTtt\Board;
use JevTtt\ParsedAnswers;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ParsedAnswersTest extends TestCase
{
    /** Body of a real response, captured by bin/smoke.php on 2026-09-19. */
    private const string LIVE_BODY = '{"model":"jev-1.13.0","answers":{"move":{"type":"choice","choice":"top_right","confidence":0.87,"probabilities":{"bottom_right":0.02,"middle_right":0.04,"top_right":0.91,"bottom_left":0.02,"bottom_middle":0.01}},"can_win_now":{"type":"noul","noul":0.93},"must_block":{"type":"noul","noul":0.67},"outcome":{"type":"score","score":1.67,"confidence":0.51,"legend":{"0":"The player to move loses the game.","1":"The game ends in a draw.","2":"The player to move wins the game."},"probabilities":{"0":0.09,"1":0.15,"2":0.76}}},"usage":{"input_tokens":547,"output_tokens":108}}';

    public function testParsesALiveResponse(): void
    {
        $answers = ParsedAnswers::fromResponse(json_decode(self::LIVE_BODY, true), Board::fromString('XX-OO----'));

        self::assertSame('jev-1.13.0', $answers->model);
        self::assertSame(2, $answers->move);
        self::assertSame(0.87, $answers->moveConfidence);
        self::assertSame([2 => 0.91, 5 => 0.04, 6 => 0.02, 7 => 0.01, 8 => 0.02], $answers->moveProbabilities);
        self::assertSame(0.93, $answers->canWinNow);
        self::assertSame(0.67, $answers->mustBlock);
        self::assertSame(1.67, $answers->outcomeScore);
        self::assertSame([0.09, 0.15, 0.76], $answers->outcomeProbabilities);
        self::assertSame(547, $answers->inputTokens);
    }

    public function testRejectsAnswersForADifferentBoard(): void
    {
        $this->expectException(UnexpectedValueException::class);
        ParsedAnswers::fromResponse(json_decode(self::LIVE_BODY, true), Board::fromString('XX-OO-X-O'));
    }

    public function testRejectsAMissingNoul(): void
    {
        $response = json_decode(self::LIVE_BODY, true);
        unset($response['answers']['must_block']);

        $this->expectException(UnexpectedValueException::class);
        ParsedAnswers::fromResponse($response, Board::fromString('XX-OO----'));
    }

    public function testRejectsAnErrorBody(): void
    {
        $this->expectException(UnexpectedValueException::class);
        ParsedAnswers::fromResponse(['error' => 'nope'], Board::fromString('XX-OO----'));
    }
}
