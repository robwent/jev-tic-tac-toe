<script setup lang="ts">
import { isBlunder, QUALITY_LABELS } from '../api'
import { CELL_LABELS } from '../game'
import type { LogEntry } from '../useGame'

defineProps<{ log: LogEntry[] }>()

const cells = (list: number[]) => list.map((i) => CELL_LABELS[i]).join(', ')
</script>

<template>
  <ol v-if="log.length" class="log">
    <li v-for="entry in log" :key="entry.number" :class="{ blunder: entry.quality && isBlunder(entry.quality) }">
      <span class="number tabular">{{ entry.number }}.</span>
      <span class="who">{{ entry.by === 'jev' ? 'Jev' : 'You' }} ({{ entry.mark }})</span>
      <span class="where">{{ CELL_LABELS[entry.cell] }}</span>
      <template v-if="entry.by === 'jev' && entry.quality">
        <span class="tabular muted">{{ Math.round((entry.probability ?? 0) * 100) }}%</span>
        <span class="tag" :class="isBlunder(entry.quality) ? 'bad' : entry.quality === 'slow_win' ? 'meh' : 'good'">
          {{ isBlunder(entry.quality) ? '✗' : '✓' }} {{ QUALITY_LABELS[entry.quality] }}
        </span>
        <span v-if="entry.overruled" class="fix small muted">
          Code look-ahead overruled Jev, which wanted {{ CELL_LABELS[entry.overruled.cell] }}
          ({{ Math.round(entry.overruled.probability * 100) }}%, {{ QUALITY_LABELS[entry.overruled.quality].toLowerCase() }}).
        </span>
        <span v-if="isBlunder(entry.quality) && entry.optimal" class="fix small muted">Should have played: {{ cells(entry.optimal) }}</span>
      </template>
    </li>
  </ol>
  <p v-else class="muted small">No moves yet.</p>
</template>

<style scoped>
.log {
  list-style: none;
  margin: 0;
  padding: 0;
  font-size: 0.92rem;
}

li {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 2px 8px;
  padding: 6px 0;
  border-bottom: 1px solid var(--grid);
}

li:last-child {
  border-bottom: 0;
}

.number {
  color: var(--text-muted);
  min-width: 1.6em;
}

.who {
  font-weight: 600;
}

.tag {
  margin-left: auto;
  font-size: 0.82rem;
  font-weight: 600;
  white-space: nowrap;
}

.tag.good {
  color: var(--good-text);
}

.tag.meh {
  color: var(--text-secondary);
}

.tag.bad {
  color: var(--critical-text);
}

.fix {
  flex-basis: 100%;
  padding-left: calc(1.6em + 8px);
}
</style>
