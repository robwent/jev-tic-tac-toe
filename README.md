# Noughts and crosses against Jev

A playable game of noughts and crosses (tic-tac-toe) where the opponent is [TypeSafe's Jev](https://docs.typesafe.ai), a "System One" model that returns typed decisions and probabilities instead of text. Alongside it is a benchmark that checks Jev's answers against a perfect solver for every position that can occur in a game.

Tic-tac-toe is solved, so every answer has an exact right or wrong. That makes it a handy way to see what changes a model's decisions: how the board is described, how the question is worded, and what extra information it is given.

This is a weekend experiment, not an authoritative evaluation. One model version (`jev-1.13.0`), one game, mostly one sample per position. The working notes, including everything that did not pan out, are in [NOTES.md](NOTES.md), and the experiments not yet run are in [docs/next-steps.md](docs/next-steps.md).

## What we found

Figures are for held-out test positions unless marked. "Decision positions" are the ones where at least one legal move is worse than another. A random player gets 40% of those right.

| | best-move rate |
| --- | --- |
| Board as a drawn 3x3 grid, "choose the best cell" | 55.4% |
| Board as nine named cells | 57.6% |
| Named cells plus the eight lines spelled out | 69.4% |
| Same board, question reworded to "win if you can, otherwise block" | 83.3% |
| Same, plus the true answers to "can I win?" / "must I block?" in the state (tuning set) | 87.6% |

- **Representation first.** With a drawn grid, nothing else helped, not even telling it a win was available. It could not find the cell.
- **One sentence was worth 14 points.** "Best" is vague. Jev reads instructions literally.
- **It sees more than it acts on.** Asked directly, it spots 99% of column threats. Asked for a move with the vague wording, it blocked them 34% of the time.
- **It is consistent where it is confident.** 300 positions asked five times each: every pick it gave 60% or more was identical all five times.
- **Forks are the floor.** About 8% of positions need look-ahead, and no wording fixed those.
- **Cost:** the first full benchmark was 18,080 requests for $0.49. Everything in this repo (36,725 requests) came to $1.09. Model time is roughly 100 ms per request.

There is also a mode where code does the look-ahead and Jev only answers "is there a completed line / two in a line on this board?". That reaches about 94% and is a measure of Jev's perception plus our search, not of Jev's play. A human can still force a win against it.

## Running it

You need PHP 8.4 with curl and pdo_sqlite, Composer, Node with pnpm, and a TypeSafe API key. No particular web server.

```bash
composer install
cp .env.example .env          # then add TYPESAFE_API_KEY
vendor/bin/phpunit            # 87 tests, no API calls

php bin/smoke.php             # one real request, saved to storage/smoke-test.json

cd frontend && pnpm install && pnpm build && cd ..
```

Then serve `public/` with any PHP-capable web server. The quickest is PHP's own:

```bash
php -S localhost:8000 -t public
```

The page's charts read the committed `public/data/report.json`, so it works without running the benchmark.

**Web root.** Only `public/` should be reachable, never the project root, because `.env` and `storage/` sit beside it. With `php -S ... -t public`, nginx or Caddy, point the root at `public/` and you are done. If you are on Apache and cannot choose the root, the `.htaccess` in the project folder rewrites every request into `public/` as a safety net.

To reproduce the numbers (everything is resumable and skips what is already stored):

```bash
php bin/benchmark.php --representation=all --split=dev --limit=20   # a quick trial, under a cent
php bin/peek.php                                                    # eyeball it against the solver

php bin/benchmark.php --representation=all                          # the v1 baseline, about $0.49
php bin/benchmark.php --representation=all --split=dev --prompt=v2-priority
php bin/benchmark.php --representation=all --split=dev --prompt=v2-chain-jev
php bin/benchmark.php --representation=all --split=dev --prompt=v2-chain-oracle
php bin/benchmark.php --representation=lines --split=test --prompt=v2-priority
php bin/judge.php                                                   # the four yes/no judgements, about $0.17

php bin/compare.php --split=dev     # prompt versions side by side
php bin/search.php --split=test     # perception accuracy, and look-ahead by depth
php bin/exploits.php --human=X --depth=3   # every line that beats the look-ahead player
php bin/report.php                  # writes public/data/report.json
```

The "Jev + code look-ahead" mode in the game needs `php bin/judge.php` to have been run. Without it the game quietly falls back to Jev alone.

## How it is put together

- `src/Board.php`, `src/Solver.php`: the game and a memoised minimax with depth, tested against the known counts (5,478 positions, 765 up to symmetry, 255,168 games).
- `src/QuestionBuilder.php`: every word Jev reads. Four board representations, four prompt versions.
- `src/JevClient.php`: plain curl, `curl_multi` with pacing and backoff. No SDK, the HTTP API is simple.
- `src/Benchmark/`: runner, SQLite store, dev/test split by symmetry class, reports.
- `src/SearchPlayer.php`: look-ahead that never inspects a line itself.
- `public/api/move.php`: the only endpoint. It accepts a board, a side and a look-ahead depth, and builds the prompt server-side, so it cannot be used as a general proxy for your API key. Rate limited.
- `frontend/`: Vue 3 + Vite, built into `public/`.

Prompt wording was only ever tuned on the dev split (15% of symmetry classes). Rotations and reflections of a position always fall on the same side of the split.

## Licence

MIT. Not affiliated with TypeSafe.
