# Next steps

Where the project stands, and what is worth doing next. Results so far are in [NOTES.md](../NOTES.md). Written 2026-09-19.

## Where things stand

- Everything is pinned to `jev-1.13.0`. Results are stored per model, prompt version and representation in `storage/results.sqlite`, which is not committed. `public/data/report.json` is, so the page works from a fresh clone.
- Run on **every position**: `v1` for all four representations, `v2-priority` for `lines`, and the four static judgements (`j1`) for `lines`.
- Run on the **dev split only**: `v2-priority` for `grid`, `cells` and `rules`, and `v2-chain-jev` and `v2-chain-oracle` for all four.
- Repeats: 300 positions, 5 asks each, `lines` + `v2-priority` only.
- The live game uses `lines` + `v2-priority`, with optional code look-ahead of 1 to 4 moves.
- Spend so far: 36,725 requests, $1.09.

## Ground rules for any new experiment

These are what make the numbers worth quoting. Keep them.

1. **Tune on dev, report on test.** `--split=dev` while trying things. One `--split=test` run at the end for whatever you settle on. If wording is changed after seeing test results, say so next to the number.
2. **New wording is a new version.** Add it to `QuestionBuilder::VERSIONS` (or bump `JUDGEMENT_VERSION`), never edit an existing version's text. Stored rows are keyed by version, so old results stay valid and comparable.
3. **Write the exact wording and the result into NOTES.md**, including the ones that did not work.
4. **Fix thresholds and wording before looking at results** where possible, and note when they were tuned instead.
5. **Trial first.** `--limit=20` and `php bin/peek.php` before any full run. The runner is resumable, so a failed run is just rerun.
6. Always show the random baseline beside an optimal rate, and count decision positions only.

## Experiments, most interesting first

### 1. Column-first board

**Question.** Asked directly, Jev sees row and column threats equally (99%). Asked for a move under `v2-priority`, it blocks 90% of row threats and 71% of column threats. Is that the order the text is written in, or something about columns?

**How.** Add a fifth representation, `lines_columns_first`: same content as `lines`, but `board` listed column by column and the `lines` object ordered `col_1..3`, then `row_1..3`, then the diagonals. Add it to `QuestionBuilder::REPRESENTATIONS` and handle it in `state()`. Run `v2-priority` on dev, then compare blocking by kind of line (`Extras::acting()` reads `lines` only at the moment, so make the representation a parameter).

**Reading the result.** If rows and columns swap places, it is text order, and the practical advice becomes "put the thing you most need noticed first". If columns stay weak, it is something else and worth a closer look at which column positions fail.

**Cost.** About $0.03 on dev, $0.15 for every position.

### 2. Shuffled option order

**Question.** The move options were always listed in reading order. How much of the symmetry disagreement (and the centre habit) is option-position bias rather than board understanding?

**How.** Already built. `php bin/benchmark.php --representation=lines --prompt=v2-priority --shuffle-seed=1`, then `php bin/report.php`. `Report::optionOrder()` compares picks with and without the shuffle on decision positions, and the result appears under `option_order` in `report.json`. It is not shown on the page yet.

**Cost.** About $0.15 for every position.

### 3. Two-step design that only trusts confident answers

**Question.** `v2-chain-jev` fed back Jev's own yes/no answers cut at 0.5. It halved missed blocks but raised missed wins, because a wrong "cannot win" was believed. The repeatability run says answers and picks above about 0.6 are solid. Does feeding back only confident answers keep the gain without the damage?

**How.** Two variants, both new versions:

- `v3-chain-confident`: write an assessment line into the state only when the Noul is at or above a high cut or at or below a low one, and leave it out otherwise. Pick the cuts on dev.
- `v3-code-acts`: when "can win" or "must block" is confident, code asks a second narrow Choice, "which cell completes the line?", over only the cells on lines Jev flagged, instead of the full move question. This is closer to how the docs suggest composing judgements.

The `j1` judgements are much more decisive than the v1 Nouls (0.02 against 0.3 when false), so use the `j1` wording for the first call.

**Cost.** A few cents on dev per variant.

### 4. Tighter wording for the two-in-a-line judgement

**Question.** `j1` false-alarms on 37% of boards where the opponent already holds the third cell, and 40% where the line is already complete. Can wording fix a literal-reading error?

**How.** New `JUDGEMENT_VERSION` (`j2`). Options to try on dev: a Noul `criteria` with explicit `true` and `false` descriptions ("false when the third cell holds any mark"); splitting into one question per line ("row_1 contains exactly two X and one empty"), which is 16 narrow questions per board instead of 2; or a Choice per line over `empty / one / two and open / two and blocked / complete`. `bin/judge.php` and `bin/search.php` take the version from the constant.

**Why it matters beyond itself.** The look-ahead player's remaining errors all trace to wrong judgements, so this lifts that too. Rerun `bin/exploits.php` afterwards to see whether the forced wins for X disappear.

**Cost.** $0.17 per full run of one question set. More for per-line questions, since the token count per request rises.

### 5. Annotated options

**Question.** How well does it play when code has done all the perception and Jev only has to choose?

**How.** New representation or version where each move option carries a description written by code: "completes row_1 for X", "blocks col_2", "creates two threats", "no immediate effect". Expected to be near perfect. It is the far end of "how much must code do", and its value is as the last bar on that chart, not as a finding.

**Cost.** About $0.03 on dev.

### 6. Confirm the remaining v2 numbers on test

`v2-priority` for `grid`, `cells` and `rules` has only been run on dev. The post and README only quote the `lines` figure as held-out, so nothing published depends on this, but the page's version table would be cleaner with test numbers throughout.

`php bin/benchmark.php --representation=grid --split=test --prompt=v2-priority`, and the same for `cells` and `rules`. About $0.30 in total.

### 7. Error bars

Repeats were run for one configuration on 300 positions. To put honest error bars on the headline table, run `--repeat=1` to `--repeat=4` for `v1` on each representation with `--limit=500`, and have `Extras::repeatability()` take the version and representation as parameters. About $0.10 per representation.

### 8. A new Jev version

When TypeSafe ship a new version: change `JevClient::MODEL`, rerun the v1 baseline and `lines` + `v2-priority`, and regenerate the report. Old rows are kept, keyed by model, so the report could grow a version-against-version comparison. About $1.10 for the lot.

Worth checking first on a new version: the column gap (experiment 1), and whether the 8% look-ahead floor moves.

## The page

- Show the `option_order` result once experiment 2 has been run.
- The version table mixes dev and test numbers depending on what has been run. Label each cell, or wait for experiment 6.
- A "replay a position" input (paste a 9-character board, see Jev's answers for it) would make the blunder examples in NOTES.md checkable by anyone. The endpoint already accepts any legal position where it is Jev's turn.
- The look-ahead mode reads judgements from `storage/`, so it silently falls back to Jev alone on a fresh clone. Either say so on the page when that happens (the response already reports `look_ahead: 0`), or commit a compact judgements file.

## The repo

- CI: a GitHub Action running `vendor/bin/phpunit` and `pnpm typecheck`. No API key needed, the tests make no network calls.
- Consider committing a compact export of the results (position, representation, version, move, probabilities) so others can analyse without spending anything. The full SQLite file with raw responses is large, so a trimmed CSV or JSON is the better fit.
- `bin/search.php` and `Benchmark\Extras` both compute perception and look-ahead tables. Make the script print from `Extras`.

## Writing

- A follow-up post if experiment 1 or 3 gives a clear answer. Each is small enough to stand alone.
- If the first post gets questions about method, the dev/test split by symmetry class and the "decision positions" definition are the two things most worth explaining further.
