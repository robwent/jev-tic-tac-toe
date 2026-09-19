<script setup lang="ts">
import { computed } from 'vue'
import type { JevDecision } from '../api'
import { CELL_LABELS, winningLine } from '../game'

const props = defineProps<{
  board: string
  decision: JevDecision | null
  boardSeen: string | null
  showSolver: boolean
  canPlay: boolean
  thinking: boolean
}>()

defineEmits<{ play: [cell: number] }>()

const line = computed(() => winningLine(props.board) ?? [])

const cells = computed(() =>
  [...props.board].map((mark, i) => {
    const considered = props.decision !== null && props.boardSeen?.[i] === '-'
    const probability = considered ? (props.decision!.probabilities[String(i)] ?? 0) : null
    const optimal = considered && props.decision!.solver.optimal_moves.includes(i)

    return {
      i,
      mark,
      probability,
      optimal,
      chosen: considered && props.decision!.move === i,
      jevPick: considered && props.decision!.jev_pick === i && props.decision!.move !== i,
      winning: line.value.includes(i),
      // Text flips to white once the wash is dark enough to need it.
      strong: probability !== null && probability >= 0.55,
      label: [
        CELL_LABELS[i],
        mark === '-' ? 'empty' : mark,
        probability === null ? null : `Jev gave this ${Math.round(probability * 100)}%`,
        optimal && props.showSolver ? 'solver: optimal' : null,
      ].filter(Boolean).join(', '),
    }
  }),
)
</script>

<template>
  <div class="board" :class="{ thinking }" role="grid" aria-label="Board">
    <button
      v-for="cell in cells"
      :key="cell.i"
      type="button"
      class="cell"
      :class="{ playable: canPlay && cell.mark === '-', winning: cell.winning, strong: cell.strong, chosen: cell.chosen, 'jev-pick': cell.jevPick }"
      :style="cell.probability === null ? undefined : { '--p': cell.probability }"
      :disabled="!canPlay || cell.mark !== '-'"
      :aria-label="cell.label"
      @click="$emit('play', cell.i)"
    >
      <span v-if="cell.probability !== null" class="probability tabular">{{ Math.round(cell.probability * 100) }}%</span>
      <span v-if="showSolver && cell.optimal" class="optimal" title="The solver says this was an optimal move">★</span>
      <span v-if="cell.mark !== '-'" class="mark" :class="cell.mark">{{ cell.mark === 'X' ? '✕' : '◯' }}</span>
    </button>
  </div>
</template>

<style scoped>
.board {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
  width: 100%;
  max-width: 420px;
  aspect-ratio: 1;
  margin: 0 auto;
  transition: opacity 0.15s;
}

.board.thinking {
  opacity: 0.6;
}

.cell {
  --p: 0;
  position: relative;
  display: grid;
  place-items: center;
  border: 1px solid var(--border);
  border-radius: 10px;
  background:
    linear-gradient(rgba(var(--heat-rgb), calc(var(--p) * 0.92)), rgba(var(--heat-rgb), calc(var(--p) * 0.92))),
    var(--surface-raised);
  padding: 0;
  min-width: 0;
  cursor: default;
}

.cell.playable {
  cursor: pointer;
}

.cell.playable:hover {
  border-color: var(--accent);
  box-shadow: inset 0 0 0 1px var(--accent);
}

.cell.chosen {
  box-shadow: inset 0 0 0 2px var(--accent);
}

.cell.jev-pick {
  box-shadow: inset 0 0 0 2px var(--text-muted);
}

.cell.winning {
  box-shadow: inset 0 0 0 3px var(--good);
}

.mark {
  font-size: clamp(2rem, 12vw, 3.6rem);
  line-height: 1;
  font-weight: 300;
  color: var(--text);
}

.probability,
.optimal {
  position: absolute;
  top: 6px;
  font-size: 0.8rem;
  line-height: 1;
  color: var(--text-secondary);
}

.probability {
  left: 8px;
  font-weight: 600;
}

.optimal {
  right: 8px;
  font-size: 0.95rem;
  color: var(--text);
}

.cell.strong .probability,
.cell.strong .optimal,
.cell.strong .mark {
  color: #fff;
}
</style>
