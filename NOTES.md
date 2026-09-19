# Notes for the blog post

Working notes. Numbers here are copied from runs, with the command that produced them. Nothing is rounded in Jev's favour or against it.

## Prompt versions

Every version ever sent, including ones that were dropped. Wording lives in `src/QuestionBuilder.php`.

### v1 (2026-09-19)

Written before seeing any results. Not tuned.

- State always has `you_play`. `grid`: `board` is three strings such as `"X X ."`. `cells`: `board` maps `top_left` ... `bottom_right` to `X`, `O` or `empty`. `lines`: adds `lines`, the eight lines each as a map of cell name to contents. `rules`: adds a three-sentence `rules` string and `player_to_move`.
- `move` (Choice): "Choose the best cell for {me} to play next." Options are the empty cell names in reading order with `null` descriptions.
- `can_win_now` (Noul): "{me} can complete three in a row with a single move."
- `must_block` (Noul): "{opponent} will be able to complete three in a row on their next turn unless blocked."
- `outcome` (Score): "Judge the final result of this game for {me} when both players play perfectly from this position." Levels: "{me} loses the game." / "The game ends in a draw." / "{me} wins the game."

Departure from the original plan: the Nouls name the marks (`X`, `O`) where the plan said "the player to move" and "the opponent". Jev's docs say indirection costs accuracy, and working out whose turn it is was not what we wanted to measure.

### v2-priority, v2-chain-jev, v2-chain-oracle (2026-09-19)

Written after the full v1 run, in response to two findings: Jev blocks only about half the time, and its `must_block` Noul is right far more often than its move is. Run on the **dev split only**. Three arms that differ from v1 in exactly these ways, everything else identical:

- `v2-priority`: the move instruction becomes "Choose the cell for {me} to play next. When {me} has two marks in a line and the third cell of that line is empty, choose that empty cell. Otherwise, when {opponent} has two marks in a line and the third cell of that line is empty, choose that empty cell. Otherwise choose the cell that gives {me} the best chance of winning."
- `v2-chain-jev`: `v2-priority`, plus the state gains `assessment: { "{me}_can_complete_three_in_a_row_this_turn": yes|no, "{opponent}_threatens_to_complete_three_in_a_row_next_turn": yes|no }`, filled from Jev's own v1 Noul answers for the same position and representation, cut at 0.5. This is the two-call design with code as the scratchpad. The first call is exactly the v1 request, so the stored v1 answers stand in for it.
- `v2-chain-oracle`: the same, with the solver's true answers. This is the ceiling for chaining: if Jev is told the truth and still does not act on it, feeding back its own answers cannot help.

The 0.5 cut and the yes/no wording were fixed in advance and not tuned.

## Things found before any benchmark

- **Jev is not deterministic.** `bin/smoke.php` run twice, identical request: `top_right` 0.87 then 0.91, `must_block` 0.75 then 0.67, `outcome` confidence 0.42 then 0.51. Same top pick. We chose not to measure this noise yet (no repeat passes), so every benchmark figure is one sample per position.
- Probabilities come back rounded to two decimal places.
- Latency from a home connection in the UK: about 250 to 700 ms total, of which the `x-envoy-upstream-service-time` header says roughly 90 ms is TypeSafe's side. The model is fast. The network is most of the wait.
- The raw HTTP API is documented and matches the docs exactly. No SDK needed from PHP.

## Ground truth facts worth quoting

From `Solver`, asserted in `tests/SolverTest.php`:

- 5,478 reachable positions, 765 up to symmetry, 958 terminal (626 X wins, 316 O wins, 16 draws), 255,168 games.
- 4,520 non-terminal positions are the benchmark set. Only 3,191 of them are **decision positions** where some legal move is worse than another. In the other 1,329 every move is "optimal", mostly because the game is already lost.
- A uniformly random player is optimal on 40.5% of decision positions. That is the number to beat.
- `can_win_now` is true in 52% of positions. `must_block`, read literally, is true in 72%. A Noul that always says yes scores 72% on it.
- 736 positions have a forced win where some winning moves are slower than others. Those slower moves are counted as `slow_win`, not as blunders.

## Trial: 20 dev positions per representation, v1

`php bin/benchmark.php --representation=all --split=dev --limit=20` then `php bin/peek.php`. 80 requests, 51,768 input tokens, $0.0022, under 2 s per representation.

| | optimal on 16 decision positions | random | `can_win_now` right side of 0.5 | `outcome` argmax right |
| --- | --- | --- | --- | --- |
| grid | 10/16 | 32% | 50% | 35% |
| cells | 10/16 | 32% | 60% | 30% |
| lines | 9/16 | 32% | 90% | 30% |
| rules | 9/16 | 32% | 100% | 35% |

Too small to rank anything. What it did show: Jev takes the centre nearly every time it is free, including when a win or a forced block is elsewhere. With `grid` and `cells` the Nouls sit at about 0.5. `outcome` says "draw" almost regardless.

## Full run, v1 (2026-09-19)

`php bin/benchmark.php --representation=all` then `php bin/report.php`. Every figure below is from `public/data/report.json`, **test split** (3,843 positions, 2,691 of them decision positions) unless it says otherwise. v1 was never tuned, so dev and test agree closely (for example `rules` 69.2% dev, 70.8% test).

### Cost and speed

18,080 requests in total, zero failures, zero retries. 11.7 million input tokens, **$0.49**. Each representation took 316 s for 4,500 requests, which is our own pacing (about 14 a second), not the API's ceiling.

| | tokens / request | cost for all 4,520 | median latency | p95 | median model time |
| --- | --- | --- | --- | --- | --- |
| grid | 443 | $0.084 | 267 ms | 335 ms | 96 ms |
| cells | 506 | $0.096 | 254 ms | 329 ms | 94 ms |
| lines | 791 | $0.150 | 261 ms | 332 ms | 100 ms |
| rules | 849 | $0.161 | 269 ms | 343 ms | 97 ms |

Doubling the state size made no measurable difference to latency.

### Move quality

Random play is optimal on 40.2% of the test decision positions.

| | optimal | lift over random | probability on optimal moves | missed win | missed block | allowed fork | other | slow win |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| grid | 55.4% | +15.2 | 45.8% | 22.2% | 14.5% | 3.1% | 4.8% | 4.2% |
| cells | 57.6% | +17.4 | 47.6% | 20.1% | 15.1% | 2.3% | 4.9% | 4.1% |
| lines | 69.4% | +29.2 | 57.6% | 11.0% | 12.6% | 2.4% | 4.6% | 2.4% |
| rules | 70.8% | +30.6 | 59.7% | 8.5% | 13.6% | 2.4% | 4.6% | 1.6% |

- The big step is `cells` to `lines`: spelling out the eight lines is worth 12 points. Naming the cells instead of drawing a grid is worth 2. Adding the rules is worth 1.4, which is about the size of the sampling noise (standard error near 0.9 points per figure).
- Spelling out the lines halves missed wins (22% to 9%). It barely touches missed blocks (14.5% to 13.6%). Jev sees its own two-in-a-row far better than its opponent's.
- Even at its best it throws away a win or draw in 29% of positions where that is possible.
- The centre habit: most of the high-confidence blunders are centre picks. Worst on test: `X-------O` with X to move, 85% on the centre, which loses the win that a corner keeps (`cells` and `lines`). `---X-OXOX` with O to move, 81% on the centre while X wins at top left next move (`lines`, `rules`, `cells`).

### Calibration of the move

Top-pick probability against how often the pick was optimal. `rules`: said 35% was right 45%, said 55% was right 58%, said 74% was right 82%, said 85% was right 99%, said 92% was right 100% (193 positions). `lines` is the same shape. Jev is **under-confident** almost everywhere, and when it gives a move 80% or more it is nearly always right. Part of the under-confidence at the low end is structural: with several equally good moves the probability is split between them.

`grid` is the exception: its few picks above 70% were right only 64% of the time (97 positions).

### The Nouls

| | `can_win_now` Brier (baseline 0.249) | right side of 0.5 | mean when true / false | `must_block` Brier (baseline 0.206) | right side of 0.5 (always-yes scores 71.0%) | mean when true / false |
| --- | --- | --- | --- | --- | --- | --- |
| grid | 0.226 | 65.1% | 0.54 / 0.47 | 0.213 | 69.4% | 0.58 / 0.52 |
| cells | 0.208 | 69.5% | 0.61 / 0.50 | 0.186 | 76.4% | 0.63 / 0.53 |
| lines | 0.132 | 90.0% | 0.65 / 0.34 | 0.141 | 88.5% | 0.67 / 0.44 |
| rules | 0.107 | 94.6% | 0.70 / 0.31 | 0.136 | 89.2% | 0.68 / 0.42 |

With a drawn grid the Nouls carry almost no information: `must_block` on `grid` is worse than always answering with the base rate. With the lines spelled out `can_win_now` is right 95% of the time, but the probabilities stay timid (0.70 when true, 0.31 when false).

A curiosity: on `rules` Jev answers "I can win now" correctly in 94.6% of positions yet still fails to play the winning move in 8.5% of decision positions. The questions are answered independently and the move does not benefit from the Noul. A two-step design (ask the Nouls, let code pick the move when they are confident) would very likely beat the single Choice. Not tested.

### Outcome forecast

Useless, and worse with more information. Accuracy 49.8% (`grid`), 40.3% (`cells`), 33.6% (`lines`), 33.8% (`rules`) against 63.2% for always saying "win". With `lines` and `rules` it answers "draw" for 78% and 81% of positions and almost never says "win" at all (286 and 317 times in 3,843, nearly all of them correct). This needs lookahead, and the docs say multi-hop questions cost accuracy, so it is the expected failure, but it is a clean example of one.

### Symmetry

Mean agreement with its own most common pick across the rotations and reflections of a position: `grid` 65.3%, `cells` 78.2%, `lines` 80.2%, `rules` 81.1%. Fully consistent across all variants: 14.9%, 39.7%, 42.9%, 45.9%. Two caveats: option order was always reading order, which is itself not symmetric, and Jev is not deterministic, so some disagreement is noise. Neither control has been run.

### Whole games (one sampled policy, both splits)

Jev's win / draw / loss.

| | as X vs perfect | as O vs perfect | as X vs random | as O vs random |
| --- | --- | --- | --- | --- |
| grid | 0 / 25 / 75 | 0 / 9 / 91 | 70 / 15 / 15 | 35 / 12 / 52 |
| cells | 0 / 38 / 63 | 0 / 10 / 90 | 72 / 16 / 12 | 40 / 10 / 50 |
| lines | 0 / 25 / 75 | 0 / 19 / 82 | 77 / 11 / 12 | 63 / 8 / 29 |
| rules | 0 / 38 / 63 | 0 / 15 / 85 | 70 / 8 / 22 | 60 / 7 / 32 |

"Perfect" spreads evenly over every value-preserving move. Against that opponent Jev loses most games with every representation: a 70% per-move rate compounds badly over a game, and a perfect opponent steers into the positions Jev gets wrong. The "as X vs perfect" figures are multiples of one eighth, so 25 vs 38 is a single branch of the tree going differently. Against itself the result is a single deterministic game per representation, not worth reporting.

The human beat it on the first evening (Rob, playing against `lines`).

### Does it know which side it is on? (test split, decision positions)

Rob's question after beating it as X. The state says `you_play` and every question names the mark, so it is told. Whether it uses it:

| | optimal as X (random 41.9%) | optimal as O (random 38.2%) | win available: took it, X / O | win available: played the opponent's winning cell instead, X / O | only a block needed: blocked, X / O |
| --- | --- | --- | --- | --- | --- |
| grid | 57.7% | 52.6% | 57.5% / 53.9% | 11.3% / 20.1% | 42.6% / 44.0% |
| cells | 60.1% | 54.4% | 61.1% / 57.8% | 8.2% / 14.9% | 40.1% / 41.3% |
| lines | 71.0% | 67.4% | 76.0% / 80.4% | 6.7% / 8.3% | 52.9% / 49.5% |
| rules | 72.8% | 68.4% | 82.6% / 84.2% | 2.7% / 5.7% | 46.7% / 46.9% |

- O looks 4 points worse, but O's positions are harder by the same 4 points (the random baseline drops from 41.9% to 38.2%). Lift over random with `rules` is +30.9 as X and +30.2 as O. No side effect.
- With `lines` and `rules` it takes an available win slightly more often as O than as X. It knows whose marks are whose.
- With `grid` and `cells` there is a trace of confusion: as O, with a win on the board, it plays the cell that would complete X's line 15 to 20% of the time, about twice the rate it does as X.
- The real hole is the same for both sides: **when the only job is to block, it blocks about half the time** (47% with `rules`). That is how a human wins: make two in a row and it is close to a coin flip. Meanwhile the `must_block` Noul on the same request is on the right side of 0.5 in 89% of positions. It sees the threat and does not act on it.

### Rows, columns, diagonals, and Rob's three-move win (test split unless stated)

Positions with exactly one winning cell, or exactly one threat and no win, where that cell completes one kind of line:

| | took the win: row / column / diagonal | blocked: row / column / diagonal |
| --- | --- | --- |
| grid | 65.0% / 45.8% / 55.3% | 57.4% / 27.5% / 46.6% |
| cells | 74.6% / 44.9% / 54.6% | 55.8% / 27.9% / 42.0% |
| lines | 83.7% / 65.8% / 83.9% | 54.3% / 33.7% / 64.4% |
| rules | 92.4% / 74.6% / 80.5% | 48.1% / 32.2% / 60.6% |

(n = 603 / 603 / 447 for wins, 258 / 258 / 264 for blocks.)

- Across all positions the blind spot is **columns**, not diagonals. Column threats are blocked about 30% of the time in every representation, below the rate a random move would manage in many of these positions. Rows are read best, which fits a model that reads left to right: a row is three adjacent tokens, a column is three tokens far apart. This holds even in `lines`, where every column is spelled out as its own object.
- Rob's observation is still right for the opening. His line: X takes the centre, Jev replies in a corner (correct), X takes an adjacent corner, which threatens the diagonal through the centre. Jev's stored answers for `O-X-X----` (O must play bottom left): `grid`, `cells` and `rules` all play **top middle**, the cell between its own mark and X's new one. `lines` blocks, at 28% against 27% for top middle. `must_block` on the same requests: 0.64, 0.48, 0.62, 0.64.
- All positions where X holds the centre and one other cell, O has one mark, and there is a single threat (both splits, 24 diagonal and 24 row/column positions per representation): diagonal threat blocked 2/24 (`grid`), 2/24 (`cells`), 12/24 (`lines`), 7/24 (`rules`). Row or column threat through the centre blocked 11, 10, 17 and 16 out of 24. So early diagonal threats through the centre are blocked far less often than straight ones, and the live game (`rules`) loses to centre-then-corner about 7 times in 10.

### v2 arms on the dev split (2026-09-19)

`php bin/benchmark.php --representation=all --split=dev --prompt=<arm>` for each arm, then `php bin/compare.php --split=dev`. 8,124 requests, no failures, $0.25. **Dev split: 677 positions, 500 decision positions. These numbers chose the prompt, so they are not headline numbers.** Random baseline 41.7%.

| | arm | optimal | missed win | missed block | forks + other |
| --- | --- | --- | --- | --- | --- |
| grid | v1 | 56.6% | 20.8% | 13.8% | 8.8% |
| grid | v2-priority | 52.4% | 21.8% | 14.2% | 11.6% |
| grid | v2-chain-jev | 53.8% | 24.0% | 13.0% | 9.2% |
| grid | v2-chain-oracle | 56.0% | 21.6% | 12.6% | 9.8% |
| cells | v1 | 55.0% | 20.8% | 14.8% | 9.4% |
| cells | v2-priority | 54.2% | 19.0% | 15.4% | 11.4% |
| cells | v2-chain-jev | 58.6% | 18.6% | 13.2% | 9.6% |
| cells | v2-chain-oracle | 62.6% | 17.6% | 10.8% | 9.0% |
| lines | v1 | 68.2% | 10.8% | 11.8% | 9.2% |
| lines | v2-priority | 83.8% | 1.6% | 5.8% | 8.8% |
| lines | v2-chain-jev | 83.8% | 5.4% | 2.6% | 8.2% |
| lines | v2-chain-oracle | 87.6% | 2.6% | 1.2% | 8.6% |
| rules | v1 | 69.2% | 8.6% | 12.6% | 9.6% |
| rules | v2-priority | 81.4% | 3.6% | 5.6% | 9.4% |
| rules | v2-chain-jev | 85.2% | 4.6% | 1.6% | 8.6% |
| rules | v2-chain-oracle | 87.8% | 2.2% | 0.8% | 9.2% |

What this says:

- **Perception first.** With `grid` and `cells` nothing helps, not even being told the truth. With the oracle's "you can win this turn: yes" in the state, `grid` still misses the win in 21.6% of decision positions. It has been told a win exists and cannot find the cell. Instructions and chaining only pay off once the lines are spelled out.
- **Wording was the biggest single lever.** `lines`: 68.2% to 83.8% from changing one sentence. "Best" was too vague for a literal reader. Missed wins drop from 10.8% to 1.6%.
- **Chaining is real but smaller than wording, and it cuts both ways.** Feeding back Jev's own answers takes missed blocks from 5.8% to 2.6% (`lines`) and 5.6% to 1.6% (`rules`). But it raises missed wins on `lines` from 1.6% to 5.4%: when the first call wrongly says "cannot win", the second call believes it. Net: no change on `lines`, +3.8 points on `rules`.
- **The oracle ceiling is about 88%.** Perfect hints remove nearly all missed wins and blocks. What remains is 8 to 9% of forks and quiet positional errors, and that number does not move in any arm, in any representation that can see lines. That is the look-ahead floor for a single-pass model on this game.
- So the answer to "perception or chaining?" is: both, in that order, and then a third thing neither fixes.

The live game was switched to `lines` + `v2-priority` after the first arm finished. Rob could no longer win in three moves (the centre-then-corner diagonal is now blocked 10 times out of 10 at about 90%), but still beats it, by forking.

Remaining blunders for `lines` + `v2-priority` by pieces on the board: 19% at 3 pieces, 22% at 4, 12% at 5, 20% at 6, 7% at 7. Typical: `-X-X----O`, O to move, centre at 73%, which allows a fork.

### Perception on its own: the four static judgements (j1, `lines`, 2026-09-19)

`php bin/judge.php` then `php bin/search.php --split=dev`. Every reachable position including finished games, 5,478 requests, no failures, 3.95 million tokens, $0.17. Each board is asked four Nouls with nothing about whose turn it is or what to play: "{X|O} has completed a line: all three cells of one line contain {mark}." and "{X|O} has two marks in one line and the third cell of that line is empty." State is `board` plus `lines`, no `you_play`. Wording fixed before any results. Figures are for dev positions plus all finished games, two marks per board.

| question | truth | n | right side of 0.5 |
| --- | --- | --- | --- |
| completed line | yes, row / column / diagonal | 327 / 327 / 267 | 100% / 100% / 100% |
| completed line | no | 2,328 | 99.1% |
| two in a line, third empty | yes, on a row | 582 | 98.5% |
| two in a line, third empty | yes, on a column | 582 | 97.8% |
| two in a line, third empty | yes, on a diagonal | 288 | 92.7% |
| two in a line, third empty | no | 1,582 | 73.3% |

- **The column blind spot is not perception.** Asked directly, Jev sees a column threat 97.8% of the time, the same as a row. Yet in the v1 move question it blocked column threats about 30% of the time and row threats about 50%. It sees the column and does not act on it. The weakness is in turning what it sees into a choice of cell.
- Asked plainly and concretely, the Nouls are decisive: 0.01 to 0.03 on empty-ish boards, where v1's vaguer "can complete three in a row with a single move" sat around 0.3 when false. Same model, same board, different sentence.
- Its errors are nearly all false alarms, and they are literal-reading errors. It says "yes, two in a line with the third empty" for 43 to 48% of boards where that mark already has all three, for 26 to 37% of boards where the mark has two in a line but the opponent holds the third cell, and for 32% of boards with three scattered marks and no pair at all. With two marks or fewer on the board it never false-alarms (0 of 282). "And the third cell is empty" is exactly the kind of attached condition the docs warn it reads loosely.

### Jev's perception plus a code search (NOT a measure of Jev's play)

Rob's objection, which is right: once code does the look-ahead, the decisions are the code's. This table measures whether Jev's perception is good enough to build on. `SearchPlayer` knows how to place marks and whose turn it is. It never inspects a line: finished or threatened lines come only from the stored judgements above, cut at 0.5. Ties are broken by Jev's own `v2-priority` move probabilities. With perfect judgements the same search never blunders (tested), so every remaining error here traces to a wrong judgement. Dev split, 500 decision positions.

| look-ahead by code | optimal | missed win | missed block | fork | other |
| --- | --- | --- | --- | --- | --- |
| none (Jev's own `v2-priority` pick) | 83.8% | 1.6% | 5.8% | 4.0% | 4.8% |
| 1 move | 87.6% | 0.0% | 3.4% | 3.4% | 5.6% |
| 2 moves | 93.0% | 0.0% | 0.8% | 2.8% | 3.4% |
| 3 moves | 97.2% | 0.0% | 0.8% | 1.2% | 0.8% |
| 4 moves | 98.4% | 0.0% | 0.8% | 0.4% | 0.4% |
| to the end | 98.6% | 0.0% | 0.8% | 0.4% | 0.2% |

Why it stops at 98.6%, with a worked example. `-X-X----O`, O to move: the right moves are top right or bottom left, the centre allows a fork. With 3 or 4 moves of look-ahead the search still plays the centre. After O takes the centre and X blocks at top left, O's diagonal is dead, but Jev says "O has two in a line with the third empty" at 0.67 (`XX-XO---O`). The search believes it. Two general points:

- **A stricter cut-off does not rescue it.** True threats and false alarms overlap. At 0.5 Jev sees 97.5% of real threats with 26.7% false alarms; at 0.8, 83.1% and 3.8%; at 0.9, 65.6% and 0.4%. Optimal rate at 3 moves of look-ahead: 97.2% (0.5), 96.4% (0.7), 96.0% (0.8), 95.8% (0.9). Swept on dev only. 0.5 kept.
- **Search amplifies perception errors.** It takes the best-looking branch, so it goes looking for the 1 board in 100 where Jev wrongly sees a completed line. A 99% accurate judge does not give a 99% accurate player when an optimiser is choosing which judgements to rely on.

The live game has this as a switch, "Jev alone" or "Jev + code looking 1 to 4 moves ahead". It makes one live call per move for Jev's own pick and probabilities, and reads the look-ahead judgements from the stored table (they are Jev's answers, asked once, not recomputed by code). Rob's centre-then-corner line against 4 moves of look-ahead: blocked, and Jev won.

**97% per position is not 97% per game.** `php bin/exploits.php --human=X --depth=3` walks the game tree against the look-ahead player. Rob could not find a win at 3 moves of look-ahead by hand, but one exists from six of the nine openings (every corner, top middle, bottom middle), whatever Jev's tie-break does: 522 winning lines, 5 of which involve no tie-break at all and so play out identically every game. Shortest, confirmed against the live endpoint:

> X top left, O centre, X top middle, O top right (forced block), X bottom left (threatens the left column), O **middle right**, X middle left wins.

O ignores the block because Jev scores `XXO-OOX--` as "O has completed a line" at 0.56. O has two in the middle row there. The search thinks middle right wins on the spot. Jev alone picks the same wrong cell in that position, for its own reasons.

An adversary only needs one bad judgement on one reachable board, and a game tree gives them thousands to choose from.

One move of look-ahead matches what perfect hints achieved (87.6% vs the oracle arm's 87.6%). Three moves is where forks go away. It never reaches 100% because about 1 in 100 "is there a completed line" answers is wrong, and a search trusts them. In a live game this would cost one Jev call per board examined: up to 8 for one move of look-ahead, up to about 400 for three on an empty-ish board, unless answers are cached.

### Test-split confirmation (2026-09-19)

`php bin/benchmark.php --representation=lines --split=test --prompt=v2-priority` (3,843 requests, no failures, $0.14), then `compare.php --split=test` and `search.php --split=test`. 2,691 decision positions, random 40.2%. **These are the quotable numbers.**

| `lines` | optimal | missed win | missed block | fork | other |
| --- | --- | --- | --- | --- | --- |
| v1, Jev alone | 69.4% | 11.0% | 12.6% | 2.4% | 4.6% |
| v2-priority, Jev alone | 83.3% | 2.2% | 6.9% | 3.2% | 4.4% |
| + code looking 1 move ahead | 87.4% | 0.3% | 4.4% | 3.3% | 4.6% |
| + 2 moves | 91.0% | 0.3% | 2.0% | 2.5% | 4.2% |
| + 3 moves | 93.8% | 0.3% | 2.0% | 1.5% | 2.4% |
| + 4 moves | 94.7% | 0.3% | 2.0% | 0.8% | 2.2% |
| + to the end | 95.4% | 0.3% | 2.0% | 0.8% | 1.4% |

- The wording gain held up exactly: 83.8% on dev, 83.3% on test.
- The look-ahead numbers did not: 97.2% on dev at 3 moves, 93.8% on test; 98.6% to the end on dev, 95.4% on test. Nothing in the search was tuned on dev (the 0.5 cut-off was set in advance and the sweep did not change it), so this is dev having been a kinder sample, and a reminder of why the split exists. Quote the test figures.
- Can a human force a win? (`bin/exploits.php`, whole tree, both splits.) As X: yes at every depth from 0 to 4, regardless of tie-breaks. As O: yes against Jev alone and at 4 moves; at 1 to 3 moves only when Jev's tie-break falls a particular way, which against the stored sample of its picks it does.

### How repeatable is it? (2026-09-19)

Rob noticed he could replay a winning line and Jev made the same moves every time, and argued that is a point in its favour: same information in, same decision out, so the information you give it is what matters. Measured: `php bin/benchmark.php --representation=lines --prompt=v2-priority --limit=300 --repeat=1` through `--repeat=4`, so 300 positions (a seeded sample across both splits) each asked 5 times. 1,200 extra requests, $0.04.

- Same pick all 5 times: **270 of 300 (90.0%)**.
- When Jev gave its pick 0.6 or more: 193 positions, **193 identical, 100%**. Between 0.4 and 0.6: 79%. Under 0.4: 40%.
- Probabilities wobble a little: the usual pick's probability varies by a median of 0.06 across 5 asks (95th percentile 0.16, worst 0.26). `can_win_now` varies by a median of 0.05.
- The pick changed between an optimal move and a blunder in 16 of 300 positions (5.3%). Every one was a near coin-flip inside the model, e.g. `--X-O--XO`: picks 0, 0, 6, 6, 6 at 30 to 36%.

So it is not bit-for-bit deterministic, but the decision is stable exactly when it is confident, and only flips when its own probabilities already say "close call". That is what a calibrated decision component should do, and it means the low-probability picks are the ones to route elsewhere (more context, a second question, code, a person). It also means the benchmark's single sample per position is a fair picture: noise lives in the under-0.6 picks.

Caveat on the replayed games: in look-ahead mode the line judgements come from the stored table, asked once, so that part cannot vary by construction. Only Jev's own pick is live.

### Total spend

From the stored token counts: 31,247 benchmark requests (21.97 million tokens, $0.92) plus 5,478 judgement requests (3.96 million tokens, $0.17). **36,725 requests, $1.09**, not counting a few hundred live game moves at about $0.00003 each.

### Not done

Nothing here changes a published number. They are the open questions, roughly in order of how interesting the answer would be.

- **Column-first board.** Rows are read far better than columns when choosing a move (blocks: 90% rows, 71% columns under `v2-priority`), yet asked directly it sees both at 99%. Writing the board or the lines column-first would show whether that is text order or something else. If rows and columns swap, it is layout.
- **Shuffled option order.** The move options were always listed in reading order, so the symmetry figures mix board understanding with option-position bias. The runner supports it: `--shuffle-seed=1`. About $0.15 for `lines` on every position.
- **A two-step design that only trusts confident answers.** `v2-chain-jev` cut its own answers at 0.5 and was hurt by wrong "cannot win" hints. The repeatability result says picks and answers above about 0.6 are solid. Feeding back only confident answers, or letting code take the win or block directly when the yes/no answer is confident, is the obvious next version. Tune on dev.
- **Tighter wording for the two-in-a-line judgement.** 37% false alarms when the opponent holds the third cell. A `criteria` with explicit true and false descriptions, or splitting it into two questions, might fix it, and that would lift the look-ahead player too.
- **Annotated options.** Code describes each empty cell ("completes row_1 for X", "blocks col_2"). Expected to be near perfect. It is the far end of "how much must code do", so worth one run for the chart.
- **`v2-priority` on the test split for `grid`, `cells` and `rules`.** Only `lines` was confirmed on test. The others are dev-only numbers.
- **Repeats on more than 300 positions and on v1**, to put error bars on the headline table. Differences under about 2 points should still not be read into.
- **A newer Jev version when one ships.** Everything is pinned to `jev-1.13.0` and stored per model, so a rerun is a one-line change and about $1.10.

### Screenshot candidates

- `X-------O`, X to move: 85% on the centre, which throws away a forced win. Only the two free corners keep it.
- `---X-OXOX`, O to move: 81% on the centre while `must_block` is asking about the very threat it ignores.
- The calibration chart: `cells`, `lines` and `rules` sit above the diagonal throughout. `grid` drops below it at the top end.
- The Noul table next to the missed-win column.
