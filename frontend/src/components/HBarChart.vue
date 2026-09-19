<script setup lang="ts">
/** Horizontal bars on a 0 to 100% scale, value at the tip, optional reference line. */
defineProps<{
  rows: { key: string; label: string; note?: string; value: number; color: string; detail?: string }[]
  reference?: { value: number; label: string }
  /** Off for single-series charts, where the title already says what the bars are. */
  swatches?: boolean
}>()
</script>

<template>
  <div class="chart" :class="{ 'has-reference': reference }">
    <div class="plot">
      <div v-if="reference" class="overlay">
        <div class="reference" :style="{ left: `${reference.value * 100}%` }">
          <span class="small">{{ reference.label }}</span>
        </div>
      </div>
      <div v-for="row in rows" :key="row.key" class="row" :title="row.detail">
        <div class="label">
          <span><i v-if="swatches !== false" class="swatch" :style="{ background: row.color }"></i>{{ row.label }}</span>
          <span v-if="row.note" class="small muted note">{{ row.note }}</span>
        </div>
        <div class="lane">
          <div class="bar" :style="{ width: `${row.value * 100}%`, background: row.color }"></div>
          <span class="value tabular" :style="{ left: `${row.value * 100}%` }">{{ (row.value * 100).toFixed(1) }}%</span>
        </div>
      </div>
    </div>
    <div class="axis small muted tabular"><span>0%</span><span>50%</span><span>100%</span></div>
  </div>
</template>

<style scoped>
.chart {
  --label: 150px;
}

.chart.has-reference {
  padding-top: 22px;
}

.plot {
  position: relative;
  display: grid;
  gap: 12px;
}

.row {
  display: grid;
  grid-template-columns: var(--label) minmax(0, 1fr);
  align-items: center;
  gap: 8px;
}

.label {
  display: grid;
  line-height: 1.25;
  font-size: 0.92rem;
}

.note {
  padding-left: 16px;
}

.lane {
  position: relative;
  height: 20px;
  margin-right: 52px;
  border-left: 1px solid var(--axis);
}

.bar {
  height: 100%;
  min-width: 2px;
  border-radius: 0 4px 4px 0;
}

.row:hover .bar {
  filter: brightness(1.08);
}

.value {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  margin-left: 6px;
  font-size: 0.85rem;
  font-weight: 600;
}

.overlay {
  position: absolute;
  top: -22px;
  bottom: 0;
  left: calc(var(--label) + 8px);
  right: 52px;
  pointer-events: none;
  z-index: 1;
}

.reference {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 0;
  border-left: 1px solid var(--text-muted);
}

.reference span {
  position: absolute;
  top: 0;
  left: 6px;
  white-space: nowrap;
  color: var(--text-secondary);
  line-height: 1.2;
}

.axis {
  display: flex;
  justify-content: space-between;
  margin: 6px 52px 0 calc(var(--label) + 8px);
}

@media (max-width: 520px) {
  .chart {
    --label: 96px;
  }

  .note {
    display: none;
  }
}
</style>
