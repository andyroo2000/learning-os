# Time analytics: allocation performance

The first optimization preserves the five-range API and exact response payloads while reducing repeated timestamp and bucket allocation work. It prepares numeric UTC microsecond boundaries once, locates the first intersecting bucket, and streams only the four ledger columns needed for aggregation. The weekly recap retains its existing allocator.

## Repeatable measurement

Run from the repository root with the locked Composer dependencies installed:

```sh
STUDY_ANALYTICS_BENCHMARK=1 php -d memory_limit=768M vendor/bin/phpunit --do-not-cache-result tests/Feature/Study/StudyActivityAnalyticsPerformanceTest.php
```

The test uses an isolated SQLite in-memory database and the real authenticated Laravel HTTP kernel, controller, query, aggregation, and JSON response. It fixes time at `2026-10-19T16:00:00Z`, seeds deterministic four-year histories of 2,631 and 20,000 sessions across all six categories, warms up once per size, then makes three requests for each of three anchors. Setup and correctness assertions are outside each measured interval. Ordinary CI runs the same correctness path with 120 sessions and no timing threshold.

Baseline production source is `6399105e37bab2b849483cea43bd1bbce07861b7`. To repeat it, copy only `tests/Support/StudyActivityBenchmarkHistory.php` and `tests/Feature/Study/StudyActivityAnalyticsPerformanceTest.php` into a separate checkout of that commit, install its locked dependencies there, and run the command above. Do not share Composer autoload files between checkouts.

## Results

Local PHP 8.5.6 on macOS, three measured repetitions per size/anchor. All 18 fixture checksums, response checksums, and response byte counts match between implementations. Existing contract fixtures also remain unchanged.

| Sessions | Anchor | Baseline median (ms) | Revised median (ms) | Reduction |
|---:|---|---:|---:|---:|
| 2,631 | 2026-10-19 | 344.6 | 79.6 | 76.9% |
| 2,631 | 2026-10-12 | 349.8 | 79.0 | 77.4% |
| 2,631 | 2026-09-19 | 355.1 | 78.0 | 78.0% |
| 20,000 | 2026-10-19 | 2704.7 | 538.1 | 80.1% |
| 20,000 | 2026-10-12 | 2699.8 | 542.4 | 79.9% |
| 20,000 | 2026-09-19 | 2741.3 | 536.8 | 80.4% |

Raw measurements retain every repetition, database timing, query count, response size, payload checksum, and process peak memory: [baseline](allocation-baseline.json), [revised](allocation-revised.json), and [comparison](allocation-comparison.json).

The third 20,000-session baseline repetition was slower (3.34–5.93 seconds across the three anchors); those samples were retained, not discarded. The reported comparisons use medians. These local measurements establish a repeatable application-work improvement; they do not predict production network latency. SQLite query timings and process peak memory are also not PostgreSQL execution plans or per-request allocation measurements.

This change still reads the user's full history and computes all five ranges. It introduces no caching, schema changes, or eventual consistency. Daily summaries and narrower range requests remain separate follow-ups.

## Correctness gates

Coverage retains cross-user isolation, complete calendar periods, historical anchors, DST spring/fall boundaries, process timezone independence, future-ended session caps, cross-midnight allocation, and exact millisecond conservation. New differential coverage compares 800 fractional-boundary cases against the unchanged allocator, including pre-epoch timestamps and fractional-offset timezones. The microsecond conversion preserves relative rounding instead of rounding each absolute endpoint first.

The internal action groups clock/anchor options in a DTO; the HTTP parameters, defaults, and response shape are unchanged. Existing test fixture setup was extracted only to resolve CodeScene findings in touched files; assertions and canonical JSON fixtures remain intact.

Validation: the full PHP suite passed (4,675 passed, 40 environment-gated skips; 33,205 assertions), Composer lint passed, and all ten changed PHP files received CodeScene 10 with no findings. An independent source review found no blockers. Backend self-review covered ownership predicates, raw UTC timestamp handling, unchanged HTTP compatibility, database portability, future-session clipping, and preservation of canonical fixture assertions. No migrations, write paths, or recap cache behavior changed.
