# Time analytics: daily-summary foundation

This change adds reusable storage for completed local-day totals and invalidation at activity write boundaries. The analytics API does not use it yet: **this PR claims no live API latency improvement**. API integration is a separate change.

## Reproduce

```sh
STUDY_ANALYTICS_BENCHMARK=1 php -d memory_limit=768M vendor/bin/phpunit --do-not-cache-result tests/Feature/Study/StudyActivityDailySummaryPerformanceTest.php
```

The benchmark reuses the allocation PR's deterministic four-year, six-category fixture, fixed at `2026-10-19T16:00:00Z`, in an isolated SQLite in-memory database. For 2,631 and 20,000 sessions it measures three repetitions of the same completed-day window (1,460 local days in America/New_York): direct raw-ledger calculation, cold cache fill, and warm cache read. Each size gets an unmeasured service warmup. Seeding, cache reset, normalization, and assertions are outside measurement; date expansion, database work, allocation, and service serialization are inside. Ordinary CI runs a 120-session correctness workload without timing thresholds.

## Results

Local PHP 8.5.6 on macOS. The iOS build/test agent paused during the measurement window. All three phases produce identical date/category payload checksums and conserve the fixture's total duration. Every warm request performs two database queries, with **zero ledger reads and zero writes**.

| Sessions | Raw median (ms) | Cold median (ms) | Warm median (ms) | Warm reduction vs raw |
|---:|---:|---:|---:|---:|
| 2,631 | 75.70 | 87.94 | 10.86 | 85.6% |
| 20,000 | 516.06 | 529.24 | 11.00 | 97.9% |

Cold fill adds approximately 12–13 ms here because it computes and persists missing days; it is not faster than the raw builder. Once cached, the same window costs about 11 ms at either history size. Zero-activity days are represented too, so empty dates do not repeatedly trigger ledger scans.

[All 18 raw measurements](daily-summaries.json) retain elapsed time, database timing, query and write counts, payload checksums, and process peak memory. [Comparisons](daily-summaries-comparison.json) retain all three repetitions and medians. This is a service benchmark, not an HTTP or production latency measurement; PostgreSQL lock contention and network latency are not represented. Process peak memory is cumulative, not a per-request allocation measure.

The regression asserts identical raw/cold/warm payloads, exact duration conservation, one raw-ledger read for raw/cold calculation, and zero raw-ledger reads or writes for warm requests. Full invalidation, concurrency, timezone, and migration coverage belongs to the accompanying foundation tests.
