import type { Mark } from './game'

export type MoveQuality =
  | 'optimal'
  | 'slow_win'
  | 'missed_win'
  | 'missed_block'
  | 'allowed_fork'
  | 'other_blunder'

export interface JevDecision {
  /** The move actually played. Differs from jev_pick when code look-ahead overruled it. */
  move: number
  move_name: string
  jev_pick: number
  look_ahead: number
  probabilities: Record<string, number>
  confidence: number
  can_win_now: number
  must_block: number
  outcome: { score: number; loss: number; draw: number; win: number }
  latency_ms: number
  upstream_ms: number | null
  input_tokens: number | null
  cost_usd: number
  model: string
  representation: string
  prompt_version: string
  solver: {
    value: -1 | 0 | 1
    optimal_moves: number[]
    best_moves: number[]
    is_decision: boolean
    can_win_now: boolean
    must_block: boolean
    jev_move_quality: MoveQuality
    jev_pick_quality: MoveQuality
  }
}

export const QUALITY_LABELS: Record<MoveQuality, string> = {
  optimal: 'Optimal',
  slow_win: 'Slow win',
  missed_win: 'Missed a win',
  missed_block: 'Missed a block',
  allowed_fork: 'Allowed a fork',
  other_blunder: 'Blunder',
}

export const isBlunder = (quality: MoveQuality): boolean => quality !== 'optimal' && quality !== 'slow_win'

export async function askJev(board: string, jevPlays: Mark, lookAhead: number, signal?: AbortSignal): Promise<JevDecision> {
  const response = await fetch('api/move.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ board, jev_plays: jevPlays, look_ahead: lookAhead }),
    signal,
  })

  const body = await response.json().catch(() => null)
  if (!response.ok || body === null) {
    throw new Error(body?.error ?? `Request failed (${response.status}).`)
  }
  return body as JevDecision
}
