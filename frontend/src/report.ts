export const REPRESENTATIONS = ['grid', 'cells', 'lines', 'rules'] as const
export type Representation = (typeof REPRESENTATIONS)[number]

export const REPRESENTATION_NOTES: Record<Representation, string> = {
  grid: 'three rows of text',
  cells: 'nine named cells',
  lines: 'cells plus the eight lines',
  rules: 'lines plus the rules',
}

export const BLUNDERS = [
  { key: 'missed_win', label: 'Missed a win', color: 'var(--blunder-missed-win)' },
  { key: 'missed_block', label: 'Missed a block', color: 'var(--blunder-missed-block)' },
  { key: 'allowed_fork', label: 'Allowed a fork', color: 'var(--blunder-allowed-fork)' },
  { key: 'other_blunder', label: 'Other losing move', color: 'var(--blunder-other)' },
] as const

export interface ReliabilityBucket {
  bucket: string
  n: number
  mean_predicted: number
  observed: number
}

export interface NoulMetrics {
  base_rate: number
  brier: number
  brier_of_always_base_rate: number
  accuracy_at_half: number
  accuracy_of_majority_answer: number
  mean_when_true: number | null
  mean_when_false: number | null
  reliability: ReliabilityBucket[]
}

export interface SplitMetrics {
  n: number
  decisions: number
  optimal_rate: {
    decisions: number
    random_baseline_decisions: number
    lift_over_random: number
    all_positions: number
    random_baseline_all: number
  }
  optimal_mass_decisions: number
  quality_on_decisions: Record<string, number>
  by_pieces: { pieces: number; n: number; decisions: number; optimal_rate: number | null; random_baseline: number | null }[]
  move_calibration: {
    top_pick_probability: ReliabilityBucket[]
    confidence: ReliabilityBucket[]
  }
  nouls: { can_win_now: NoulMetrics; must_block: NoulMetrics }
  outcome: { accuracy: number; majority_class_accuracy: number; brier: number }
}

interface Record3 {
  win: number
  draw: number
  loss: number
}

export interface RepresentationReport {
  rows: number
  complete: boolean
  splits: Partial<Record<'test' | 'dev' | 'all', SplitMetrics>>
  symmetry: { classes_compared: number; fully_consistent_rate: number | null; mean_agreement_with_modal_pick: number | null }
  tournament: Record<'perfect' | 'random' | 'itself', Record<'jev_as_X' | 'jev_as_O', Record3>> | null
  cost: {
    requests: number
    input_tokens: number
    mean_input_tokens: number
    usd: number
    latency_ms: { median: number | null; p95: number | null }
    upstream_ms: { median: number | null; p95: number | null }
  }
}

export interface VersionRow {
  representation: Representation
  version: string
  split: 'dev' | 'test'
  decisions: number
  optimal: number
  missed_win: number
  missed_block: number
  allowed_fork: number
  other_blunder: number
}

type Kind = 'row' | 'column' | 'diagonal'
type ByKind = Record<Kind, { n: number; rate: number | null }>

export interface Extras {
  prompt_versions: VersionRow[]
  perception: { available: boolean; positions?: number; rows?: Record<string, { n: number; right: number }> }
  acting: Record<'v1' | 'v2-priority', { rows: number; took_win: ByKind; blocked: ByKind }>
  repeatability: {
    available: boolean
    positions?: number
    asks?: number
    same_every_time?: number
    change_mattered?: number
    by_confidence?: { confidence: string; n: number; same_every_time: number | null }[]
  }
  look_ahead: { available: boolean; depths?: { depth: number; decisions: number; optimal: number }[] }
}

export interface Report {
  generated_at: string
  model: string
  prompt_version: string
  positions: { non_terminal: number; decision: number }
  caveats: string[]
  representations: Partial<Record<Representation, RepresentationReport>>
  extras?: Extras
}

export const seriesColor = (name: Representation): string => `var(--series-${name})`
export const pct = (value: number | null | undefined, digits = 0): string =>
  value == null ? '–' : `${(value * 100).toFixed(digits)}%`
