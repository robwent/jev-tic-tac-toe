import { computed, reactive, ref, watch } from 'vue'
import { askJev, type JevDecision, type MoveQuality } from './api'
import { EMPTY_BOARD, isOver, other, play, toMove, winner, type Mark } from './game'

export interface LogEntry {
  number: number
  by: 'you' | 'jev'
  mark: Mark
  cell: number
  probability?: number
  quality?: MoveQuality
  /** Set when code look-ahead played something other than Jev's own pick. */
  overruled?: { cell: number; probability: number; quality: MoveQuality }
  optimal?: number[]
}

interface Tally {
  wins: number
  draws: number
  losses: number
}

const TALLY_KEY = 'human-tally'

function loadTally(): Tally {
  try {
    const saved = JSON.parse(localStorage.getItem(TALLY_KEY) ?? 'null')
    if (saved && [saved.wins, saved.draws, saved.losses].every(Number.isInteger)) return saved
  } catch {
    // Storage can be unavailable. The tally then lasts for the session only.
  }
  return { wins: 0, draws: 0, losses: 0 }
}

export function useGame() {
  const humanPlays = ref<Mark>('X')
  const board = ref(EMPTY_BOARD)
  const thinking = ref(false)
  /** Moves of look-ahead done by code over Jev's stored judgements. 0 is Jev alone. */
  const lookAhead = ref(0)
  const error = ref<string | null>(null)
  const log = ref<LogEntry[]>([])

  /** Jev's most recent decision and the board it was looking at. */
  const decision = ref<JevDecision | null>(null)
  const boardSeen = ref<string | null>(null)

  const tally = reactive(loadTally())
  const session = reactive({ requests: 0, costUsd: 0, tokens: 0 })

  let request: AbortController | null = null

  const jevPlays = computed(() => other(humanPlays.value))
  const over = computed(() => isOver(board.value))
  const humanToMove = computed(() => !over.value && !thinking.value && toMove(board.value) === humanPlays.value)

  const result = computed(() => {
    if (!over.value) return null
    const won = winner(board.value)
    return won === null ? 'draw' : won === humanPlays.value ? 'win' : 'loss'
  })

  watch(tally, (value) => {
    try {
      localStorage.setItem(TALLY_KEY, JSON.stringify(value))
    } catch {
      // See loadTally.
    }
  })

  function finishIfOver() {
    if (result.value === 'win') tally.wins++
    if (result.value === 'draw') tally.draws++
    if (result.value === 'loss') tally.losses++
  }

  async function jevMove() {
    request?.abort()
    const mine = (request = new AbortController())
    const asked = board.value
    thinking.value = true
    error.value = null

    try {
      const answer = await askJev(asked, jevPlays.value, lookAhead.value, mine.signal)
      if (request !== mine) return

      session.requests++
      session.costUsd += answer.cost_usd
      session.tokens += answer.input_tokens ?? 0

      decision.value = answer
      boardSeen.value = asked
      board.value = play(asked, answer.move)
      log.value.push({
        number: log.value.length + 1,
        by: 'jev',
        mark: jevPlays.value,
        cell: answer.move,
        probability: answer.probabilities[String(answer.move)],
        quality: answer.solver.jev_move_quality,
        optimal: answer.solver.optimal_moves,
        overruled: answer.move === answer.jev_pick ? undefined : {
          cell: answer.jev_pick,
          probability: answer.probabilities[String(answer.jev_pick)] ?? 0,
          quality: answer.solver.jev_pick_quality,
        },
      })
      finishIfOver()
    } catch (e) {
      if (e instanceof DOMException && e.name === 'AbortError') return
      error.value = e instanceof Error ? e.message : 'Something went wrong.'
    } finally {
      if (request === mine) thinking.value = false
    }
  }

  function humanMove(cell: number) {
    if (!humanToMove.value || board.value[cell] !== '-') return

    log.value.push({ number: log.value.length + 1, by: 'you', mark: humanPlays.value, cell })
    board.value = play(board.value, cell)
    finishIfOver()
    if (!over.value) void jevMove()
  }

  function newGame(side: Mark = humanPlays.value) {
    request?.abort()
    request = null
    humanPlays.value = side
    board.value = EMPTY_BOARD
    decision.value = null
    boardSeen.value = null
    log.value = []
    error.value = null
    thinking.value = false
    if (side === 'O') void jevMove()
  }

  function resetTally() {
    tally.wins = tally.draws = tally.losses = 0
  }

  return {
    humanPlays, jevPlays, board, thinking, lookAhead, error, log, decision, boardSeen,
    tally, session, over, result, humanToMove,
    humanMove, newGame, retry: jevMove, resetTally,
  }
}
