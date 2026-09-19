<?php

declare(strict_types=1);

namespace JevTtt;

enum MoveQuality: string
{
    /** Keeps the minimax value. Includes every move in a lost position. */
    case Optimal = 'optimal';

    /** Keeps a forced win but a faster win was available. Not a blunder. */
    case SlowWin = 'slow_win';

    case MissedWin = 'missed_win';
    case MissedBlock = 'missed_block';
    case AllowedFork = 'allowed_fork';
    case OtherBlunder = 'other_blunder';

    public function isBlunder(): bool
    {
        return match ($this) {
            self::Optimal, self::SlowWin => false,
            default => true,
        };
    }
}
