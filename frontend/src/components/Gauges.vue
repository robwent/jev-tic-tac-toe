<script setup lang="ts">
import { computed } from 'vue'
import type { JevDecision } from '../api'

const props = defineProps<{ decision: JevDecision | null }>()

const percent = (value: number) => `${Math.round(value * 100)}%`

const nouls = computed(() => {
  const d = props.decision
  return [
    {
      key: 'can_win_now',
      label: 'Can I win right now?',
      value: d?.can_win_now ?? null,
      truth: d?.solver.can_win_now ?? null,
    },
    {
      key: 'must_block',
      label: 'Is my opponent threatening to win?',
      value: d?.must_block ?? null,
      truth: d?.solver.must_block ?? null,
    },
  ]
})

const outcome = computed(() => {
  const d = props.decision
  if (!d) return null
  const levels = [
    { key: 'loss', label: 'Jev loses', value: d.outcome.loss },
    { key: 'draw', label: 'Draw', value: d.outcome.draw },
    { key: 'win', label: 'Jev wins', value: d.outcome.win },
  ]
  const truth = (['loss', 'draw', 'win'] as const)[d.solver.value + 1]
  const believed = levels.reduce((a, b) => (b.value > a.value ? b : a))
  return { levels, truth, truthLabel: levels.find((l) => l.key === truth)!.label, right: believed.key === truth }
})
</script>

<template>
  <div class="gauges">
    <p v-if="!decision" class="muted small">Jev's answers about the position appear here after its first move.</p>

    <div v-for="noul in nouls" :key="noul.key" class="gauge">
      <div class="head">
        <span>{{ noul.label }}</span>
        <span class="value tabular">{{ noul.value === null ? '–' : percent(noul.value) }}</span>
      </div>
      <div class="track" role="meter" :aria-label="noul.label" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.round((noul.value ?? 0) * 100)">
        <div class="fill" :style="{ width: percent(noul.value ?? 0) }"></div>
        <div class="half"></div>
      </div>
      <p v-if="noul.truth !== null && noul.value !== null" class="truth small">
        <span :class="(noul.value >= 0.5) === noul.truth ? 'right' : 'wrong'">
          {{ (noul.value >= 0.5) === noul.truth ? '✓' : '✗' }}
        </span>
        Solver: {{ noul.truth ? 'yes' : 'no' }}
      </p>
    </div>

    <div class="gauge">
      <div class="head"><span>How does Jev think this ends?</span></div>
      <div class="stack" :aria-label="outcome ? outcome.levels.map((l) => `${l.label} ${percent(l.value)}`).join(', ') : 'No answer yet'" role="img">
        <template v-if="outcome">
          <div v-for="level in outcome.levels" :key="level.key" class="segment" :class="level.key" :style="{ flexGrow: Math.max(level.value, 0.001) }"></div>
        </template>
      </div>
      <div v-if="outcome" class="legend small">
        <span v-for="level in outcome.levels" :key="level.key"><i class="swatch" :class="level.key"></i>{{ level.label }} <span class="tabular">{{ percent(level.value) }}</span></span>
      </div>
      <p v-if="outcome" class="truth small">
        <span :class="outcome.right ? 'right' : 'wrong'">{{ outcome.right ? '✓' : '✗' }}</span>
        Solver, with perfect play: {{ outcome.truthLabel.toLowerCase() }}
      </p>
    </div>
  </div>
</template>

<style scoped>
.gauges {
  display: grid;
  gap: 16px;
}

.head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 0.92rem;
}

.value {
  font-weight: 600;
}

.track,
.stack {
  position: relative;
  height: 10px;
  margin-top: 6px;
  border-radius: 5px;
  background: rgba(var(--heat-rgb), 0.16);
  overflow: hidden;
}

.fill {
  height: 100%;
  background: var(--accent);
  border-radius: 5px 0 0 5px;
  transition: width 0.25s;
}

.half {
  position: absolute;
  inset: 0 auto 0 50%;
  width: 2px;
  margin-left: -1px;
  background: var(--surface);
}

.stack {
  display: flex;
  gap: 2px;
  background: none;
}

.segment {
  flex-basis: 0;
  min-width: 2px;
  transition: flex-grow 0.25s;
}

.segment.loss,
.swatch.loss {
  background: var(--critical);
}

.segment.draw,
.swatch.draw {
  background: var(--axis);
}

.segment.win,
.swatch.win {
  background: var(--accent);
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 14px;
  margin-top: 6px;
  color: var(--text-secondary);
}

.truth {
  margin-top: 4px;
  color: var(--text-secondary);
}

.right {
  color: var(--good-text);
  font-weight: 700;
}

.wrong {
  color: var(--critical-text);
  font-weight: 700;
}
</style>
