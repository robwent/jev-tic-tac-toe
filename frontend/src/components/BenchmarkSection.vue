<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  BLUNDERS, pct, REPRESENTATION_NOTES, REPRESENTATIONS, seriesColor,
  type Report, type Representation, type RepresentationReport, type SplitMetrics,
} from '../report'
import CalibrationChart from './CalibrationChart.vue'
import FollowUps from './FollowUps.vue'
import HBarChart from './HBarChart.vue'
import StackedBars from './StackedBars.vue'

const report = ref<Report | null>(null)
const failed = ref(false)

onMounted(async () => {
  try {
    const response = await fetch('data/report.json', { cache: 'no-cache' })
    if (!response.ok) throw new Error(String(response.status))
    report.value = await response.json()
  } catch {
    failed.value = true
  }
})

interface Entry {
  name: Representation
  full: RepresentationReport
  test: SplitMetrics
}

/** Headline numbers come from the test split only. */
const entries = computed<Entry[]>(() =>
  REPRESENTATIONS.flatMap((name) => {
    const full = report.value?.representations[name]
    const test = full?.splits.test
    return full && test ? [{ name, full, test }] : []
  }),
)

const first = computed(() => entries.value[0])

const optimalRows = computed(() =>
  entries.value.map(({ name, test }) => ({
    key: name,
    label: name,
    note: REPRESENTATION_NOTES[name],
    value: test.optimal_rate.decisions,
    color: seriesColor(name),
    detail: `${test.decisions.toLocaleString('en-GB')} decision positions`,
  })),
)

const symmetryRows = computed(() =>
  entries.value.map(({ name, full }) => ({
    key: name,
    label: name,
    note: REPRESENTATION_NOTES[name],
    value: full.symmetry.mean_agreement_with_modal_pick ?? 0,
    color: seriesColor(name),
    detail: `${full.symmetry.classes_compared} groups of mirrored and rotated positions`,
  })),
)

const blunderRows = computed(() =>
  entries.value.map(({ name, test }) => ({
    key: name,
    label: name,
    segments: BLUNDERS.map((b) => ({ ...b, value: (test.quality_on_decisions[b.key] ?? 0) / test.decisions })),
  })),
)

const calibrationSeries = computed(() =>
  entries.value.map(({ name, test }) => ({
    key: name,
    label: name,
    color: seriesColor(name),
    buckets: test.move_calibration.top_pick_probability,
  })),
)

const opponents = [
  { key: 'perfect', label: 'a perfect player' },
  { key: 'random', label: 'a random player' },
] as const

const totalCost = computed(() => entries.value.reduce((sum, e) => sum + e.full.cost.usd, 0))
const totalRequests = computed(() => entries.value.reduce((sum, e) => sum + e.full.cost.requests, 0))
</script>

<template>
  <section class="benchmark" aria-labelledby="benchmark-title">
    <h2 id="benchmark-title">How good is it, asked the obvious way?</h2>

    <p v-if="failed" class="muted">The benchmark has not been run yet, so there is nothing to show here.</p>
    <p v-else-if="!report" class="muted">Loading the benchmark…</p>

    <template v-else-if="first">
      <p class="muted intro">
        Noughts and crosses is solved, so every answer can be checked. Jev ({{ report.model }}) was asked about all
        {{ report.positions.non_terminal.toLocaleString('en-GB') }} positions that can occur in a game, four times over, with
        the board described in four different ways. That was {{ totalRequests.toLocaleString('en-GB') }} requests and cost
        ${{ totalCost.toFixed(2) }}. Figures below come from a held-out set of {{ first.test.n.toLocaleString('en-GB') }} positions
        that were never used to adjust the wording of the questions. This first part uses the first wording we wrote, “Choose the best cell”,
        untouched.
      </p>

      <div class="grid">
        <div class="card">
          <h3>How often Jev's move was one of the best</h3>
          <p class="small muted">
            Only positions where a worse move exists ({{ first.test.decisions.toLocaleString('en-GB') }} of them). A move counts if it
            keeps the result a perfect player would get.
          </p>
          <HBarChart
            :rows="optimalRows"
            :reference="{ value: first.test.optimal_rate.random_baseline_decisions, label: `picking at random: ${pct(first.test.optimal_rate.random_baseline_decisions, 1)}` }"
          />
        </div>

        <div class="card">
          <h3>What the mistakes were</h3>
          <p class="small muted">Share of the same positions where Jev's move threw away a win or a draw, by kind of mistake.</p>
          <StackedBars :rows="blunderRows" :legend="BLUNDERS" />
        </div>

        <div class="card">
          <h3>Does Jev know when it is right?</h3>
          <p class="small muted">
            The probability Jev gave its chosen move, against how often that move really was one of the best. Points with fewer
            than 20 positions are left out.
          </p>
          <CalibrationChart :series="calibrationSeries" x-label="Probability Jev gave its move" y-label="How often it was a best move" />
        </div>

        <div class="card">
          <h3>Same position, turned around</h3>
          <p class="small muted">
            Rotating or mirroring a board changes nothing about the game. This is how often Jev's pick agreed with its own most
            common pick across the up to eight versions of each position.
          </p>
          <HBarChart :rows="symmetryRows" />
        </div>
      </div>

      <div class="card">
        <h3>The yes or no questions, and the forecast</h3>
        <p class="small muted">
          Brier score is the mean squared error of the probability: 0 is perfect, and the baseline is what you would score by always
          answering with the overall rate. For the forecast, the baseline is always guessing the most common result.
        </p>
        <div class="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Board given as</th>
                <th>“I can win now” Brier</th>
                <th>baseline</th>
                <th>“Opponent threatens” Brier</th>
                <th>baseline</th>
                <th>Forecast right</th>
                <th>baseline</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="{ name, test } in entries" :key="name">
                <td><i class="swatch" :style="{ background: seriesColor(name) }"></i>{{ name }}</td>
                <td>{{ test.nouls.can_win_now.brier.toFixed(3) }}</td>
                <td class="muted">{{ test.nouls.can_win_now.brier_of_always_base_rate.toFixed(3) }}</td>
                <td>{{ test.nouls.must_block.brier.toFixed(3) }}</td>
                <td class="muted">{{ test.nouls.must_block.brier_of_always_base_rate.toFixed(3) }}</td>
                <td>{{ pct(test.outcome.accuracy, 1) }}</td>
                <td class="muted">{{ pct(test.outcome.majority_class_accuracy, 1) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="entries.some((e) => e.full.tournament)" class="card">
        <h3>Whole games</h3>
        <p class="small muted">
          Jev's recorded move in every position, played out exactly over every possible game. Shown as Jev's win / draw / loss.
          A perfect player never loses, so any loss column above zero is a real weakness.
        </p>
        <div class="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Board given as</th>
                <template v-for="o in opponents" :key="o.key">
                  <th>X vs {{ o.label }}</th>
                  <th>O vs {{ o.label }}</th>
                </template>
              </tr>
            </thead>
            <tbody>
              <tr v-for="{ name, full } in entries.filter((e) => e.full.tournament)" :key="name">
                <td><i class="swatch" :style="{ background: seriesColor(name) }"></i>{{ name }}</td>
                <template v-for="o in opponents" :key="o.key">
                  <td v-for="side in ['jev_as_X', 'jev_as_O'] as const" :key="side">
                    {{ pct(full.tournament![o.key][side].win) }} / {{ pct(full.tournament![o.key][side].draw) }} / {{ pct(full.tournament![o.key][side].loss) }}
                  </td>
                </template>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <h3>The numbers behind the charts</h3>
        <div class="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Board given as</th>
                <th>Best-move rate</th>
                <th>Probability on best moves</th>
                <th>Missed win</th>
                <th>Missed block</th>
                <th>Allowed fork</th>
                <th>Other</th>
                <th>Slow win</th>
                <th>Symmetry</th>
                <th>Tokens / request</th>
                <th>Median ms</th>
                <th>of which model</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="{ name, full, test } in entries" :key="name">
                <td><i class="swatch" :style="{ background: seriesColor(name) }"></i>{{ name }}</td>
                <td>{{ pct(test.optimal_rate.decisions, 1) }}</td>
                <td>{{ pct(test.optimal_mass_decisions, 1) }}</td>
                <td v-for="b in BLUNDERS" :key="b.key">{{ pct((test.quality_on_decisions[b.key] ?? 0) / test.decisions, 1) }}</td>
                <td>{{ pct((test.quality_on_decisions.slow_win ?? 0) / test.decisions, 1) }}</td>
                <td>{{ pct(full.symmetry.mean_agreement_with_modal_pick, 1) }}</td>
                <td>{{ full.cost.mean_input_tokens }}</td>
                <td>{{ full.cost.latency_ms.median ?? '–' }}</td>
                <td>{{ full.cost.upstream_ms.median ?? '–' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <ul class="small muted caveats">
          <li v-for="caveat in report.caveats" :key="caveat">{{ caveat }}</li>
          <li>Question wording {{ report.prompt_version }}. Report generated {{ report.generated_at.slice(0, 10) }}.</li>
        </ul>
      </div>
    </template>
  </section>

  <FollowUps v-if="report?.extras" :extras="report.extras" />
</template>

<style scoped>
.benchmark {
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

.caveats {
  margin: 0;
  padding-left: 18px;
}
</style>
