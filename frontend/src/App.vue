<script setup lang="ts">
import { computed, ref } from 'vue'
import BenchmarkSection from './components/BenchmarkSection.vue'
import GameBoard from './components/GameBoard.vue'
import Gauges from './components/Gauges.vue'
import MoveLog from './components/MoveLog.vue'
import { toMove, type Mark } from './game'
import { useGame } from './useGame'

const game = useGame()
const showSolver = ref(true)

const status = computed(() => {
  if (game.error.value) return game.error.value
  if (game.result.value === 'win') return 'You won. Jev slipped up.'
  if (game.result.value === 'loss') return 'Jev won.'
  if (game.result.value === 'draw') return 'A draw. With perfect play it always is.'
  if (game.thinking.value) return 'Jev is deciding…'
  return `Your move. You are ${toMove(game.board.value)}.`
})

function toggleTheme() {
  const root = document.documentElement
  const dark = root.dataset.theme
    ? root.dataset.theme === 'dark'
    : window.matchMedia('(prefers-color-scheme: dark)').matches
  root.dataset.theme = dark ? 'light' : 'dark'
  try {
    localStorage.setItem('theme', root.dataset.theme)
  } catch {
    // The choice then lasts until the page is closed.
  }
}

const sides: Mark[] = ['X', 'O']
const money = (usd: number) => `$${usd.toFixed(6)}`
</script>

<template>
  <div class="page">
    <header class="top">
      <div>
        <h1>Noughts and crosses against Jev</h1>
        <p class="muted lede">
          Jev is a model that returns decisions and probabilities, not text. Each move is one question about the board,
          answered in about a tenth of a second with no thinking out loud. A perfect solver marks its homework.
        </p>
      </div>
      <button type="button" class="button theme" @click="toggleTheme">Light / dark</button>
    </header>

    <main>
      <section class="play" aria-label="Game">
        <div class="card board-card">
          <div class="controls">
            <div class="sides" role="group" aria-label="Your side">
              <span class="small muted">You play</span>
              <button
                v-for="side in sides"
                :key="side"
                type="button"
                class="button"
                :aria-pressed="game.humanPlays.value === side"
                @click="game.newGame(side)"
              >
                {{ side }}{{ side === 'X' ? ' (first)' : '' }}
              </button>
            </div>
            <button type="button" class="button" @click="game.newGame()">New game</button>
          </div>

          <p class="status" :class="{ error: game.error.value }" role="status" aria-live="polite">
            {{ status }}
            <button v-if="game.error.value" type="button" class="button" @click="game.retry()">Try again</button>
          </p>

          <GameBoard
            :board="game.board.value"
            :decision="game.decision.value"
            :board-seen="game.boardSeen.value"
            :show-solver="showSolver"
            :can-play="game.humanToMove.value"
            :thinking="game.thinking.value"
            @play="game.humanMove"
          />

          <label class="mode small">
            <span>Who decides</span>
            <select v-model.number="game.lookAhead.value" class="button">
              <option :value="0">Jev alone: one question, one answer</option>
              <option v-for="n in 4" :key="n" :value="n">Jev + code looking {{ n }} {{ n === 1 ? 'move' : 'moves' }} ahead</option>
            </select>
          </label>
          <p v-if="game.lookAhead.value > 0" class="small muted">
            Code tries each move and the replies to it. It cannot see lines itself: whether a board has a completed line or two in a
            line comes from Jev's stored yes or no answers about that board. Jev's own pick breaks ties, and is outlined in grey when overruled.
          </p>

          <label class="toggle small">
            <input v-model="showSolver" type="checkbox" />
            Show the solver's optimal moves (★) for Jev's last turn
          </label>
          <p class="small muted">Percentages are how Jev split its choice across the cells that were empty on its last turn.</p>
        </div>

        <div class="side">
          <div class="card tiles">
            <div class="tile">
              <span class="small muted">You have beaten Jev</span>
              <span class="hero">{{ game.tally.wins }}</span>
              <span class="small muted tabular">{{ game.tally.draws }} drawn · {{ game.tally.losses }} lost
                <button type="button" class="link" @click="game.resetTally()">reset</button>
              </span>
            </div>
            <div class="tile">
              <span class="small muted">Last answer took</span>
              <span class="figure tabular">{{ game.decision.value ? `${game.decision.value.latency_ms} ms` : '–' }}</span>
              <span class="small muted tabular">{{ game.decision.value?.upstream_ms != null ? `${game.decision.value.upstream_ms} ms of that was the model` : 'round trip to the API' }}</span>
            </div>
            <div class="tile">
              <span class="small muted">This session has cost</span>
              <span class="figure tabular">{{ money(game.session.costUsd) }}</span>
              <span class="small muted tabular">{{ game.session.requests }} requests · {{ game.session.tokens.toLocaleString('en-GB') }} tokens</span>
            </div>
          </div>

          <div class="card">
            <h3>What Jev thinks of the position</h3>
            <Gauges :decision="game.decision.value" />
          </div>

          <div class="card">
            <h3>Moves</h3>
            <MoveLog :log="game.log.value" />
          </div>
        </div>
      </section>

      <BenchmarkSection />
    </main>
  </div>
</template>

<style scoped>
.top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 24px;
}

.lede {
  margin-top: 8px;
  max-width: 62ch;
}

.theme {
  flex: none;
}

.play {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 16px;
}

@media (min-width: 860px) {
  .play {
    grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr);
    align-items: start;
  }
}

.board-card {
  display: grid;
  gap: 12px;
}

.controls {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

.sides {
  display: flex;
  align-items: center;
  gap: 6px;
}

.status {
  min-height: 36px;
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 600;
}

.status.error {
  color: var(--critical-text);
}

.mode {
  display: grid;
  gap: 4px;
}

.mode select {
  width: 100%;
  max-width: 100%;
}

.toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
}

.side {
  display: grid;
  gap: 16px;
}

.side h3 {
  margin-bottom: 12px;
}

.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 16px;
}

.tile {
  display: grid;
  gap: 2px;
  align-content: start;
}

.hero {
  font-size: 3rem;
  font-weight: 600;
  line-height: 1.05;
}

.figure {
  font-size: 1.35rem;
  font-weight: 600;
  line-height: 1.3;
}

.link {
  appearance: none;
  border: 0;
  background: none;
  padding: 0 0 0 6px;
  color: var(--accent-ink);
  text-decoration: underline;
  cursor: pointer;
}
</style>
