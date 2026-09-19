<script setup lang="ts">
import { computed } from 'vue'
import { BLUNDERS, pct, type Extras, type VersionRow } from '../report'
import HBarChart from './HBarChart.vue'
import StackedBars from './StackedBars.vue'

const props = defineProps<{ extras: Extras }>()

const ACCENT = 'var(--accent)'
const KINDS = ['row', 'column', 'diagonal'] as const

const VERSION_LABELS: Record<string, string> = {
  v1: '“Choose the best cell”',
  'v2-priority': '“Win if you can, otherwise block”',
  'v2-chain-jev': '…plus its own yes/no answers',
  'v2-chain-oracle': '…plus the true yes/no answers',
}

const SHORT_LABELS = ['“best cell”', '“win, else block”', '+ its own yes/no answers', '+ the true answers']

const find = (representation: string, version: string, split: string): VersionRow | undefined =>
  props.extras.prompt_versions.find((r) => r.representation === representation && r.version === version && r.split === split)

/** lines on the test split: the two wordings that were run on it. */
const wordingRows = computed(() =>
  ['v1', 'v2-priority'].flatMap((version) => {
    const row = find('lines', version, 'test')
    return row ? [{ key: version, label: VERSION_LABELS[version]!, value: row.optimal, color: ACCENT, detail: `${row.decisions} decision positions` }] : []
  }),
)

const wordingMistakes = computed(() =>
  ['v1', 'v2-priority'].flatMap((version) => {
    const row = find('lines', version, 'test')
    return row ? [{ key: version, label: version === 'v1' ? 'before' : 'after', segments: BLUNDERS.map((b) => ({ ...b, value: row[b.key] })) }] : []
  }),
)

const devTable = computed(() =>
  (['grid', 'cells', 'lines', 'rules'] as const).map((representation) => ({
    representation,
    cells: Object.keys(VERSION_LABELS).map((version) => find(representation, version, 'dev')?.optimal ?? null),
  })),
)

const seeing = computed(() => {
  const rows = props.extras.perception.rows ?? {}
  return KINDS.map((kind) => ({ key: kind, label: `on a ${kind}`, value: rows[`threat:yes:${kind}`]?.right ?? 0, color: ACCENT, detail: `${rows[`threat:yes:${kind}`]?.n ?? 0} boards` }))
})

const blocking = (version: 'v1' | 'v2-priority') =>
  KINDS.map((kind) => {
    const cell = props.extras.acting[version].blocked[kind]
    return { key: kind, label: `on a ${kind}`, value: cell.rate ?? 0, color: ACCENT, detail: `${cell.n} positions` }
  })

const falseAlarms = computed(() => {
  const rows = props.extras.perception.rows ?? {}
  return [
    { key: 'no_pair', label: 'No pair on any line' },
    { key: 'third_cell_taken', label: 'A pair, but the opponent holds the third cell' },
    { key: 'already_three', label: 'The line is already complete' },
  ].map((c) => ({ ...c, n: rows[`threat:no:${c.key}`]?.n ?? 0, wrong: 1 - (rows[`threat:no:${c.key}`]?.right ?? 1) }))
})

const repeatRows = computed(() =>
  (props.extras.repeatability.by_confidence ?? []).map((b) => ({
    key: b.confidence,
    label: b.confidence,
    value: b.same_every_time ?? 0,
    color: ACCENT,
    detail: `${b.n} positions`,
  })),
)

const lookAheadRows = computed(() =>
  (props.extras.look_ahead.depths ?? []).map((d) => ({
    key: String(d.depth),
    label: d.depth === 0 ? 'Jev alone' : d.depth === 9 ? 'to the end' : `${d.depth} ${d.depth === 1 ? 'move' : 'moves'} ahead`,
    value: d.optimal,
    color: ACCENT,
    detail: `${d.decisions} decision positions`,
  })),
)
</script>

<template>
  <section class="follow" aria-labelledby="follow-title">
    <h2 id="follow-title">What changed the result</h2>
    <p class="muted intro">
      Nothing below changes the model. It changes what Jev is told and how it is asked. New wording was tried on a small
      tuning set first and then confirmed once on the held-out positions. Unless it says otherwise these use the “lines” board.
    </p>

    <div class="grid">
      <div v-if="wordingRows.length === 2" class="card">
        <h3>One clearer sentence</h3>
        <p class="small muted">
          “Best” turned out to be too vague. Spelling out the priorities in the question lifted the best-move rate by
          {{ Math.round((wordingRows[1]!.value - wordingRows[0]!.value) * 100) }} points on the held-out positions.
        </p>
        <HBarChart :rows="wordingRows" :swatches="false" />
        <StackedBars :rows="wordingMistakes" :legend="BLUNDERS" />
      </div>

      <div class="card">
        <h3>The wording only helps if it can see the lines</h3>
        <p class="small muted">
          Best-move rate on the tuning set (500 decision positions, so rougher numbers). With a drawn grid or bare cells nothing
          helps, not even being handed the true answers.
        </p>
        <div class="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Board given as</th>
                <th v-for="label in SHORT_LABELS" :key="label" class="wrap">{{ label }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in devTable" :key="row.representation">
                <td>{{ row.representation }}</td>
                <td v-for="(cell, i) in row.cells" :key="i">{{ pct(cell, 1) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div v-if="extras.perception.available" class="card">
      <h3>It sees more than it acts on</h3>
      <p class="small muted">
        The opponent has two in a line and the third cell is empty. Left: asked only “is that the case?”, over all
        {{ extras.perception.positions?.toLocaleString('en-GB') }} boards. Middle and right: asked to choose a move when blocking
        is the only job, before and after the clearer sentence.
      </p>
      <div class="triple">
        <div>
          <h4>Sees it when asked</h4>
          <HBarChart :rows="seeing" :swatches="false" />
        </div>
        <div>
          <h4>Blocks it: “best cell”</h4>
          <HBarChart :rows="blocking('v1')" :swatches="false" />
        </div>
        <div>
          <h4>Blocks it: “win, otherwise block”</h4>
          <HBarChart :rows="blocking('v2-priority')" :swatches="false" />
        </div>
      </div>
      <p class="small muted">
        Its mistakes on the yes/no question are nearly all false alarms, and they read like taking the sentence loosely. Share of
        boards with no such threat where it still said yes:
        <template v-for="(c, i) in falseAlarms" :key="c.key">{{ i ? '; ' : ' ' }}{{ c.label.toLowerCase() }} {{ pct(c.wrong) }}</template>.
        It spotted every completed line ({{ pct(extras.perception.rows?.['line:yes:row']?.right) }}).
      </p>
    </div>

    <div class="grid">
      <div v-if="extras.repeatability.available" class="card">
        <h3>Same question, same answer?</h3>
        <p class="small muted">
          {{ extras.repeatability.positions }} positions asked {{ extras.repeatability.asks }} times each. How often the pick was
          identical every time, by the probability Jev gave that pick. Overall {{ pct(extras.repeatability.same_every_time) }}.
          It only wavers where it has already said it is unsure.
        </p>
        <HBarChart :rows="repeatRows" :swatches="false" />
      </div>

      <div v-if="extras.look_ahead.available" class="card aside">
        <h3>Aside: Jev's eyes, our look-ahead</h3>
        <p class="small muted">
          Not a measure of Jev's play. Code tries moves and replies, and the only thing it knows about lines is Jev's yes/no answer
          for each board. Held-out positions. It tops out short of 100% because a search goes looking for the rare board Jev
          misjudged, and a human can still force a win as X at every depth.
        </p>
        <HBarChart :rows="lookAheadRows" :swatches="false" />
      </div>
    </div>
  </section>
</template>

<style scoped>
.follow {
  display: grid;
  gap: 16px;
  margin-top: 48px;
}

.intro {
  max-width: 75ch;
}

.grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 16px;
}

@media (min-width: 860px) {
  .grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

.card {
  display: grid;
  gap: 12px;
  align-content: start;
  min-width: 0;
}

.wrap {
  white-space: normal;
  min-width: 5.5em;
}

.aside {
  border-style: dashed;
}

.triple {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 20px;
}

h4 {
  margin: 0 0 8px;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--text-secondary);
}

.triple :deep(.chart) {
  --label: 96px;
}
</style>
