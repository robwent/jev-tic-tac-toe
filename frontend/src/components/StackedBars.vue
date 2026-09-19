<script setup lang="ts">
import { computed, ref } from 'vue'

/** One horizontal stacked bar per row, shares of a common total, scaled to the largest row. */
const props = defineProps<{
  rows: { key: string; label: string; segments: { key: string; label: string; value: number; color: string }[] }[]
  legend: readonly { key: string; label: string; color: string }[]
}>()

const max = computed(() => Math.max(0.01, ...props.rows.map((row) => row.segments.reduce((sum, s) => sum + s.value, 0))))
const scaleMax = computed(() => Math.ceil(max.value * 10) / 10)
const hovered = ref<string | null>(null)
</script>

<template>
  <div class="chart">
    <div class="legend small">
      <span v-for="item in legend" :key="item.key"><i class="swatch" :style="{ background: item.color }"></i>{{ item.label }}</span>
    </div>
    <div v-for="row in rows" :key="row.key" class="row">
      <div class="label">{{ row.label }}</div>
      <div class="lane">
        <div
          v-for="segment in row.segments.filter((s) => s.value > 0)"
          :key="segment.key"
          class="segment"
          tabindex="0"
          :style="{ width: `${(segment.value / scaleMax) * 100}%`, background: segment.color }"
          :aria-label="`${row.label}: ${segment.label} ${(segment.value * 100).toFixed(1)}%`"
          @mouseenter="hovered = `${row.key}:${segment.key}`"
          @mouseleave="hovered = null"
          @focus="hovered = `${row.key}:${segment.key}`"
          @blur="hovered = null"
        >
          <span v-if="hovered === `${row.key}:${segment.key}`" class="tip small">
            {{ segment.label }} <strong class="tabular">{{ (segment.value * 100).toFixed(1) }}%</strong>
          </span>
        </div>
        <span class="total tabular">{{ (row.segments.reduce((sum, s) => sum + s.value, 0) * 100).toFixed(1) }}%</span>
      </div>
    </div>
    <div class="axis small muted tabular"><span>0%</span><span>{{ Math.round(scaleMax * 100) }}%</span></div>
  </div>
</template>

<style scoped>
.chart {
  --label: 64px;
  display: grid;
  gap: 12px;
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 14px;
  color: var(--text-secondary);
}

.row {
  display: grid;
  grid-template-columns: var(--label) minmax(0, 1fr);
  align-items: center;
  gap: 8px;
  font-size: 0.92rem;
}

.lane {
  display: flex;
  align-items: center;
  gap: 2px;
  height: 20px;
  margin-right: 52px;
  border-left: 1px solid var(--axis);
}

.segment {
  position: relative;
  height: 100%;
  min-width: 3px;
}

.segment:last-of-type {
  border-radius: 0 4px 4px 0;
}

.segment:hover,
.segment:focus-visible {
  filter: brightness(1.1);
}

.tip {
  position: absolute;
  bottom: calc(100% + 6px);
  left: 50%;
  transform: translateX(-50%);
  z-index: 2;
  padding: 4px 8px;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: var(--surface-raised);
  color: var(--text);
  white-space: nowrap;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
  pointer-events: none;
}

.total {
  margin-left: 6px;
  font-size: 0.85rem;
  font-weight: 600;
}

.axis {
  display: flex;
  justify-content: space-between;
  margin: -6px 52px 0 calc(var(--label) + 8px);
}
</style>
