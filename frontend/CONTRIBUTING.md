# Contributing — Frontend

## Test infrastructure

`vitest.config.ts` caps `maxWorkers` at `"25%"` of logical cores. This is a
deliberate, measured setting — not a default left in place, and not an
arbitrary guess.

**Why:** the frontend suite is CPU-heavy (jsdom environment setup per file +
`userEvent`/MUI Autocomplete interactions). With no cap, Vitest's default
`forks` pool spawns roughly one worker per logical core, which on a typical
dev/CI machine oversubscribes the CPU the moment anything else is running
alongside it (Docker, the local DB, a browser). The symptom was a shifting
set of test files failing with `Test timed out` on any given run — never the
same files twice, never an assertion mismatch, always a timeout.

**Evidence, not a guess:** a controlled experiment ran the full suite 10
consecutive times at three worker levels:

| Workers | Avg runtime | Clean runs (0 failures) | Avg timeout failures |
|---|---|---|---|
| Unrestricted (default) | 91.4s | 0 / 8 | 11.4 |
| ~50% of cores | 68.4s | 2 / 10 | 7.4 |
| ~25% of cores | 49.0s | **10 / 10** | **0** |

Reducing parallelism didn't just improve stability — it was also faster on
average, because the unrestricted configuration was spending real time on
OS-level scheduling contention rather than useful test execution.

**The goal of this setting is deterministic, repeatable execution — not
maximum theoretical parallelism.** If you're tempted to raise `maxWorkers`
back up because CI or your machine "feels like it has more cores to spare,"
re-run the same kind of experiment (10 consecutive full-suite runs) before
changing it — a single fast run proves nothing about stability.
