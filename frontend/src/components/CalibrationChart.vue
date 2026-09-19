<script setup lang="ts">
import { computed, ref } from 'vue'
import type { ReliabilityBucket } from '../report'

/** Predicted probability against what actually happened. On the diagonal is perfectly calibrated. */
const props = defineProps<{
  series: { key: string; label: string; color: string; buckets: ReliabilityBucket[] }[]
  xLabel: string
  yLabel: string
  minN?: number
}>()

const W = 360
const H = 300
const M = { top: 12, right: 14, bottom: 40, left: 44 }
const x = (v: number) => M.left + v * (W - M.left - M.right)
const y = (v: number) => H - M.bottom - v * (H - M.top - M.bottom)
const ticks = [0, 0.25, 0.5, 0.75, 1]

const lines = computed(() =>
  props.series.map((s) => {
    const points = s.buckets.filter((b) => b.n >= (props.minN ?? 20))
    return { ...s, points, path: points.map((b, i) => `${i ? 'L' : 'M'}${x(b.mean_predicted).toFixed(1)},${y(b.observed).toFixed(1)}`).join(' ') }
  }),
)

const hovered = ref<{ label: string; bucket: ReliabilityBucket; cx: number; cy: number } | null>(null)
</script>

<template>
  <figure class="chart">
    <div class="legend small">
      <span v-for="s in series" :key="s.key"><i class="swatch" :style="{ background: s.color }"></i>{{ s.label }}</span>
      <span><i class="dash"></i>perfectly calibrated</span>
    </div>
    <div class="frame">
      <svg :viewBox="`0 0 ${W} ${H}`" role="img" :aria-label="`${yLabel} against ${xLabel}, one line per board representation`">
        <g v-for="t in ticks" :key="t">
          <line :x1="x(0)" :x2="x(1)" :y1="y(t)" :y2="y(t)" class="grid" />
          <line :x1="x(t)" :x2="x(t)" :y1="y(0)" :y2="y(1)" class="grid" />
          <text :x="x(0) - 8" :y="y(t) + 4" text-anchor="end" class="tick">{{ t * 100 }}%</text>
          <text :x="x(t)" :y="y(0) + 16" text-anchor="middle" class="tick">{{ t * 100 }}%</text>
        </g>
        <line :x1="x(0)" :y1="y(0)" :x2="x(1)" :y2="y(1)" class="diagonal" />
        <text :x="x(0.5)" :y="H - 4" text-anchor="middle" class="axis-label">{{ xLabel }}</text>
        <text :transform="`translate(11 ${y(0.5)}) rotate(-90)`" text-anchor="middle" class="axis-label">{{ yLabel }}</text>

        <g v-for="line in lines" :key="line.key">
          <path :d="line.path" fill="none" :stroke="line.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
          <circle
            v-for="b in line.points"
            :key="b.bucket"
            :cx="x(b.mean_predicted)"
            :cy="y(b.observed)"
            r="4"
            :fill="line.color"
            class="dot"
          />
        </g>
        <!-- Larger invisible hit targets on top of everything. -->
        <g v-for="line in lines" :key="`hit-${line.key}`">
          <circle
            v-for="b in line.points"
            :key="b.bucket"
            :cx="x(b.mean_predicted)"
            :cy="y(b.observed)"
            r="11"
            fill="transparent"
            tabindex="0"
            :aria-label="`${line.label}, predicted ${(b.mean_predicted * 100).toFixed(0)}%, observed ${(b.observed * 100).toFixed(0)}%, ${b.n} positions`"
            @mouseenter="hovered = { label: line.label, bucket: b, cx: x(b.mean_predicted), cy: y(b.observed) }"
            @mouseleave="hovered = null"
            @focus="hovered = { label: line.label, bucket: b, cx: x(b.mean_predicted), cy: y(b.observed) }"
            @blur="hovered = null"
          />
        </g>
      </svg>
      <div v-if="hovered" class="tip small" :style="{ left: `${(hovered.cx / W) * 100}%`, top: `${(hovered.cy / H) * 100}%` }">
        <strong>{{ hovered.label }}</strong><br />
        Jev said <span class="tabular">{{ (hovered.bucket.mean_predicted * 100).toFixed(0) }}%</span>,
        right <span class="tabular">{{ (hovered.bucket.observed * 100).toFixed(0) }}%</span> of the time<br />
        <span class="muted tabular">{{ hovered.bucket.n.toLocaleString('en-GB') }} positions</span>
      </div>
    </div>
  </figure>
</template>

<style scoped>
.chart {
  margin: 0;
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 14px;
  margin-bottom: 8px;
  color: var(--text-secondary);
}

.dash {
  display: inline-block;
  width: 14px;
  margin-right: 6px;
  vertical-align: middle;
  border-top: 1px solid var(--text-muted);
}

.frame {
  position: relative;
  max-width: 460px;
}

svg {
  display: block;
  width: 100%;
  height: auto;
  overflow: visible;
}

.grid {
  stroke: var(--grid);
  stroke-width: 1;
}

.diagonal {
  stroke: var(--text-muted);
  stroke-width: 1;
}

.tick,
.axis-label {
  fill: var(--text-muted);
  font-size: 10px;
  font-variant-numeric: tabular-nums;
}

.axis-label {
  fill: var(--text-secondary);
  font-size: 11px;
}

.dot {
  stroke: var(--surface);
  stroke-width: 2;
}

circle:focus-visible {
  outline: 2px solid var(--accent);
}

.tip {
  position: absolute;
  transform: translate(-50%, calc(-100% - 12px));
  z-index: 2;
  padding: 6px 10px;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: var(--surface-raised);
  white-space: nowrap;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
  pointer-events: none;
  line-height: 1.4;
}
</style>
