# Jev Tic-Tac-Toe

A playable noughts and crosses game where the opponent is TypeSafe's Jev model, plus a benchmark that checks Jev's moves and confidence against a perfect minimax solver across every legal position. The output is a demo page and the data for a blog post.

The point of the project is the evaluation, not the game. Tic-tac-toe is solved, so it gives exact ground truth for a model whose published benchmarks have none.

## Environment

- PHP 8.4 with curl and pdo_sqlite. Plain PHP, no framework. Composer for autoloading (PSR-4, namespace `JevTtt\`) and PHPUnit only.
- SQLite via PDO for benchmark results.
- Front end: Vue 3 + Vite + TypeScript, pnpm, source in `frontend/`, built into `public/`.
- API key lives in `.env` as `TYPESAFE_API_KEY`. Never commit it, never send it to the browser, never log it. `.env` and `storage/` are in `.gitignore`.
- **Web root:** only `public/` may be reachable over HTTP, because `.env` and `storage/` sit beside it. No particular server is needed: `php -S localhost:8000 -t public`, nginx, Caddy or Apache all work with the root pointed at `public/`. The root `.htaccess` is a safety net for Apache setups that serve the project folder itself: it rewrites every request into `public/`. Never remove it. URLs have no `/public` prefix (`/api/move.php`).
- If HTTPS calls fail with SSL errors, fix PHP's CA bundle (`curl.cainfo`). Do not disable peer verification.
- Machine-specific notes (paths, how to run commands on this machine) belong in `CLAUDE.local.md`, which is not committed.

## About Jev (checked against docs.typesafe.ai and a live call on 2026-09-19)

Jev returns typed decisions, not text. One endpoint, documented raw HTTP (https://docs.typesafe.ai/api.md):

```
POST https://api.typesafe.ai/v1/systemone
Authorization: Bearer <TYPESAFE_API_KEY>
Content-Type: application/json
```

Request body:

```json
{
  "model": "jev-1.13.0",
  "state": "string | object | array",
  "questions": {
    "<id>": { "type": "choice", "instructions": "...", "criteria": { "<option>": "description | null" } },
    "<id>": { "type": "score",  "instructions": "...", "criteria": ["lowest level", "...", "highest level"] },
    "<id>": { "type": "noul",   "instructions": "...", "criteria": { "true": "...", "false": "..." } }
  }
}
```

Response body:

```json
{
  "model": "jev-1.13.0",
  "answers": {
    "<id>": { "type": "choice", "choice": "<option>", "probabilities": { "<option>": 0.0 }, "confidence": 0.0 },
    "<id>": { "type": "score", "score": 1.3, "legend": { "0": "..." }, "probabilities": { "0": 0.0 }, "confidence": 0.0 },
    "<id>": { "type": "noul", "noul": 0.92 }
  },
  "usage": { "input_tokens": 312, "output_tokens": 48 }
}
```

Details that matter here:

- Question IDs are not sent to the model. Option keys and descriptions are. A `null` description is allowed when the key is self-explanatory. Max 255 options.
- Score levels are ordered low to high, 2 to 10 of them, indexed from 0. `score` is the probability-weighted mean of the level indices. The docs say not to read meaning into the value between levels, so evaluate `outcome` on the level probabilities, not the float.
- Choice and Score `confidence` is derived from how spread the probabilities are. It is not a separate estimate of correctness. Several equally good options will lower it legitimately.
- Noul instructions may be a question or a statement (docs: test both). Noul `criteria` is optional. Noul has no `confidence`.
- `instructions` and option descriptions may be strings, objects or arrays. Instructions can reference state by backticked path, e.g. `board.top_left`.
- Models: `jev-1.13.0` is the only version; `jev-latest` and `jev-preview` both alias it today. Pin `jev-1.13.0` and store the `model` field from every response.
- Limits: 64k tokens per request, and 32k for `state` plus the longest single question. Text only.
- Pricing: $0.042 per million input tokens, output free. `usage.input_tokens` is reported per response, so cost is exact.
- Rate limits: 1,200 requests/minute, 250k tokens/second, "can change without notice". Errors: 401, 422 (body names the offending field), 429, 529 overloaded. Docs say use exponential backoff on 429 and 529. A `retry-after` header is **not documented**: honour it if present, do not rely on it.
- All questions in one request are evaluated in parallel over the same state and cannot see each other's answers.

Confirmed by `bin/smoke.php` (raw exchange in `storage/smoke-test.json`):

- The live response matches the documented shape exactly. The response `model` field echoes `jev-1.13.0`.
- **Jev is not deterministic.** Two identical requests a minute apart gave `top_right` 0.87 then 0.91, `must_block` 0.75 then 0.67, `outcome` confidence 0.42 then 0.51. The top pick was the same both times. Any metric built on one sample per position carries this noise, so it has to be measured (see Repeats below).
- **Probabilities are rounded to two decimal places.** Anything under 0.005 arrives as 0. Probability-mass sums can be off by a few hundredths.
- `probabilities` keys for a Choice come back in arbitrary order, not request order. Always read by key.
- Score `legend` and `probabilities` are JSON objects keyed `"0"`, `"1"`, ... In PHP `json_decode(..., true)` turns these into list arrays. Keep the raw body string in storage, not a re-encoded copy.
- One position with all four questions in a `cells`-style state costs 547 input tokens. `usage.output_tokens` is reported (108) but not billed.
- Latency from this machine: 630 to 670 ms total, of which `x-envoy-upstream-service-time` says 75 to 90 ms is the model. The rest is network. Store both.
- Every response carries `x-typesafe-request-id`. Store it.
- No rate-limit headers and no `retry-after` on a 200.

The `typesafe-ai` agent skill is already installed at `~/.claude/skills/typesafe-ai`. It is a pointer to the live docs; start from https://docs.typesafe.ai/llms.txt and append `.md` to page paths.

### Documented weaknesses that affect this project

- It reads instructions literally. Write instructions as plain statements with no negations or implied conditions.
- It does not count reliably and does no arithmetic.
- Accuracy drops as state fills with irrelevant material. Keep state minimal.
- A Noul where "true" means "no" underperforms. Phrase every Noul so that true is the positive case.
- Do not ask it anything code can compute exactly, except where that is the experiment (see below).

## The experiment

### Board representations (the independent variable)

The same position is sent in four forms, from pure intuition to code doing most of the work:

1. `grid`: raw 3x3 text grid
2. `cells`: JSON object with named cells (`top_left`, `top_middle`, ... `bottom_right`), values `X`, `O`, `empty`
3. `lines`: as `cells`, plus the eight lines spelled out (`row_1`, `col_1`, `diag_main`, ...)
4. `rules`: as `lines`, plus one sentence of rules and an explicit statement of whose turn it is

All four always include which mark Jev is playing.

### Questions (one request per position)

- `move`: Choice over **only the empty cells**, keyed by cell name. Illegal moves are impossible by construction.
- `can_win_now`: Noul, "The player to move can complete three in a row with a single move."
- `must_block`: Noul, "The opponent will be able to complete three in a row on their next turn unless blocked."
- `outcome`: Score with three levels for the player to move under perfect play: loses, draws, wins.

Prompt versions now in `QuestionBuilder::VERSIONS`: `v1` (the wording above), `v2-priority`, `v2-chain-jev`, `v2-chain-oracle`, plus the static judgement wording `j1`. `NOTES.md` has the exact text and results of each. The live game uses `lines` + `v2-priority`. Only v1 has been run on the test split.

Exact wording of instructions is part of the experiment. Keep all wording in `QuestionBuilder` so it can be changed in one place, and record a `prompt_version` string with every result.

### Ground truth

`Solver` provides, for any position:

- The minimax value for the player to move **and the depth**: plies to the end under best play, where the winner plays for the fastest win and the loser for the slowest loss.
- Per legal move: resulting value and depth.
- `optimalMoves`: moves that preserve the value. `bestMoves`: the subset that also has the best depth.
- `immediateWins`: cells that complete three in a row for the player to move.
- `opponentThreats`: cells where the opponent would complete three in a row if it were their turn. This is the literal truth for the `must_block` Noul and is true even when the player to move can win first.
- `blockForced`: there is a threat, there is no immediate win. Used for blunder classification only.
- Whether a move allows a fork (opponent gets two or more threats after their best reply).
- `isDecision`: at least one legal move is not optimal. In lost positions and dead draws every move is "optimal", so those positions say nothing about skill.
- The uniform-random baseline for the position: optimal moves divided by legal moves.

### Metrics

- Top-pick optimal rate. The headline figure is over **decision positions only**, always shown next to the uniform-random baseline for the same set. Also report over all positions, and by number of pieces on the board.
- Blunders by type, value-losing moves only: missed immediate win, missed forced block, allowed a fork, other. A move that keeps the win but takes longer is a **slow win**, reported separately and not counted as a blunder.
- Probability mass placed on optimal moves (not just top pick)
- Move calibration: bucket the **top-pick probability** into deciles and compare to actual optimal rate, stratified by number of optimal moves. `confidence` measures spread across options, so it is legitimately low when several moves are equally good; chart it as a secondary curve, do not treat it as a correctness estimate.
- Noul calibration: Brier score plus reliability table for each Noul against its exact truth (`can_win_now` vs `immediateWins`, `must_block` vs `opponentThreats`).
- `outcome`: graded on the three level probabilities (argmax accuracy and multi-class Brier), never on the `score` float.
- Symmetry consistency: for each position, all 8 rotations/reflections should yield the equivalent move. Report the agreement rate.
- Option-order control: on a subset, repeat with the Choice keys shuffled. Choice keys are otherwise always in reading order, so without this the symmetry metric mixes board understanding with option-position bias.
- Repeats: Jev is not deterministic, so re-ask a fixed subset several times and report top-pick flip rate and probability spread. This is the noise floor for every other number.
- Tournament: Jev vs perfect play, vs random, vs itself, both as X and O, per representation. Computed offline by walking the game tree over stored benchmark picks (all 4,520 non-terminal positions are already there), exact expectation vs random, all optimal replies vs perfect. No extra API calls. Because Jev is not deterministic this is the result for one sampled policy; say so.

Every metric is reported per representation so the four can be compared.

### Dev/test split

The 765 symmetry classes are split once, by seeded shuffle, into a small dev set and a test set. A position belongs to the set of its canonical form, so rotations and reflections never straddle the split. Prompt wording is tuned on dev only. Headline numbers come from test only. Store the split name with each result row.

## Architecture

```
src/
  Board.php            immutable 9-char string position, legality check, symmetries
  Solver.php           memoised minimax with depth, position enumerator, blunder classification
  Evaluation.php       value, depth, per-move results, optimal and best moves for one position
  MoveQuality.php      enum: optimal, slow_win, missed_win, missed_block, allowed_fork, other_blunder
  QuestionBuilder.php  Board + representation => state + questions. Holds PROMPT_VERSION
  JevClient.php        curl_multi rolling window, paced to 1,000 requests/minute, backoff, model pinning
  JevResponse.php      status, raw body, latency, upstream ms, request id
  ParsedAnswers.php    validated answers for one position, shared by the runner and move.php
  Env.php              minimal .env reader
  SearchPlayer.php     code look-ahead over Jev's stored line judgements. Never inspects a line itself
  MoveEndpoint.php     everything behind public/api/move.php, testable without HTTP
  RateLimiter.php      SQLite-backed per-client and daily limits
  Benchmark/Split.php  seeded dev/test split by symmetry class
  Benchmark/Store.php  SQLite
  Benchmark/Runner.php
  Benchmark/Report.php
bin/
  smoke.php            one call, dumps raw response
  benchmark.php        --representation=grid|cells|lines|rules|all  --split=dev|test|all  --limit=N  --shuffle-seed=N  --repeat=N
  peek.php             row-by-row results next to the solver's truth, for small runs
  compare.php          prompt versions side by side on one split
  judge.php            asks the four static line judgements for every position (table `judgements`)
  search.php           perception accuracy, and look-ahead play by depth, from stored judgements
  report.php           reads SQLite, writes public/data/report.json
frontend/              Vue 3 + Vite + TS source, builds into public/
public/
  index.html, assets/  build output
  api/move.php         the only endpoint exposed to the browser
  data/report.json
storage/
  results.sqlite
tests/
```

### Board

Positions are 9-character strings of `X`, `O`, `-`, index 0 = top left, reading order. X always moves first. A position is legal if counts are valid (X count equals O count or O count plus one), at most one side has won, and the winner made the last move.

### Solver tests (write these first)

Known counts to assert: 5,478 legal reachable positions including the empty board; 765 after removing symmetric duplicates; 958 terminal positions (626 X wins, 316 O wins, 16 draws); 255,168 possible games; value of the empty board is a draw. If the enumerator disagrees with these, the enumerator is wrong.

### Benchmark storage

One row per (position, representation, prompt_version, model, option_order, repeat). `option_order` is `reading` or a shuffle seed; `repeat` is 0 for the main run. Also store the dev/test split, the request id and the upstream service time header. Store the full raw response JSON as well as parsed fields, plus latency and input token count if the API reports it. The runner is always resumable: rows that already exist for the same configuration are skipped, so rerunning the same command picks up failures. `--limit=N` takes a fixed seeded sample, the same positions every time. Use `curl_multi` with a concurrency cap (start at 10) and stay under the rate limit.

Run with `--limit=20` first and eyeball the results before any full run.

### public/api/move.php (security matters here)

- Accepts only: `board` (exactly 9 chars of `X`, `O`, `-`), `jev_plays` (`X` or `O`) and an optional `look_ahead` (integer 0 to 4). Reject anything else with 400.
- Validates the position is legal, non-terminal, and that it is Jev's turn.
- Builds state and questions **server-side**. The client never supplies instructions, criteria, or state, so the endpoint cannot be used as a general Jev proxy.
- Per-IP rate limit and a global daily request cap, both file- or SQLite-backed. Return 429 when hit.
- Returns: chosen move, per-cell probabilities, confidence, both Nouls, outcome score, latency ms, and the solver's optimal moves for the same position.
- Representation used by the live game is `MoveEndpoint::REPRESENTATION`. It is `rules`, the v1 benchmark winner (70.8% vs 69.4% for `lines`, within noise).

## The page

- Board with a probability heatmap over empty cells (percentage in each cell)
- Gauges for `can_win_now`, `must_block`, and `outcome`
- Latency in ms and a running cost counter for the session
- Toggle, on by default, that overlays the solver's optimal moves so the player can see when Jev's intuition is wrong
- Move log that flags Jev blunders by type
- Player picks X or O. Counter of human wins, since beating it is the game
- Benchmark section underneath, charts drawn from `data/report.json`: optimal rate by representation, calibration curve, blunder breakdown, symmetry agreement
- Responsive, works on a phone, dark and light themes

Local-only for now. Do not add deployment config until the benchmark is complete.

## Build order

1. Read official API docs, write `bin/smoke.php`, confirm the raw request/response shape. Update the "About Jev" section of this file with what you find.
2. `Board` and `Solver` with tests passing against the known counts
3. `QuestionBuilder` (all four representations) and `JevClient`
4. Benchmark runner, a `--limit=20` trial, then full runs, then `Report`
5. `move.php` and the front end
6. Notes for the blog post in `NOTES.md`: surprising results, failure examples worth screenshotting, exact costs and timings

Stop and check in with Rob after steps 1, 2 and 4.

## Conventions

- `declare(strict_types=1);` everywhere, typed properties and return types, `final` classes by default, readonly value objects
- PSR-12
- No dependencies beyond PHPUnit and a dotenv loader. No HTTP client library, use curl directly.
- Metric units, UK English in user-facing text ("noughts and crosses" on the page is fine, keep "tic-tac-toe" in code and the repo name)
- Ask before writing long or complex code where the requirements are ambiguous. Rob prefers a clarifying question to a wrong assumption.
- Honest reporting: if Jev does badly, or well, the numbers go in as they are. Do not tune prompts against the full set and then report on the same set without saying so. Keep a note of every prompt_version tried.
