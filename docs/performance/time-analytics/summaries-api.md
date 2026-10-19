# Time analytics: daily summaries in the API

The API now serves completed calendar days from the daily-summary table. Local full-response medians fall from **79–80 ms to about 26 ms for 2,631 sessions**, and from **537–542 ms to 29–30 ms for 20,000 sessions**, compared with the allocation optimization. All 18 full-response checksums and byte counts remain identical. A cached historical week takes about **2.2 ms** and performs **zero ledger reads and zero writes**.

These are repeatable application measurements, not production network latency estimates. Cold requests must calculate and save missing days; that cost is shown separately below.

## API behavior

`GET /api/study/activity-analytics` accepts optional `ranges[]=week&ranges[]=month`. The list must contain one to five distinct values from `today`, `week`, `month`, `year`, and `all`. Responses retain canonical order regardless of request order. Omission preserves all five ranges and the existing response shape. Scalar values, objects, blanks, unknown values, and duplicates are rejected. Internal options enforce the same list/type/count constraints.

Completed days contribute their exact category totals directly to day/month/quarter/year buckets. Their canonical UTC boundaries are compared without repeated date parsing. The hourly `today` range retains original session allocation, including when anchored to a historical date. Today's unfinished tail is read separately and clipped at local midnight before allocation, so a session crossing midnight cannot be counted twice. The original recorded end remains the proportional-allocation denominator even when the visible window ends at now.

One transaction acquires the same owner lock used by activity writers **before** reading the earliest session, loading/filling summaries, or reading raw detail. This avoids combining stale all-time boundaries with newly committed activity. It serializes requests for one user, including cold fills; lock contention is not represented by SQLite timing.

Windows exceeding 3,660 local days fall back to exact numeric raw allocation before expanding days. Validated ancient anchors that cannot be stored as PostgreSQL AD dates also use the raw path, with database queries clipped to the ledger's AD timestamp domain. Empty/future periods preserve their display boundaries and return zero totals.

## Repeat the measurements

Install the locked Composer dependencies in each checkout; do not share autoload directories across branches. Run from the repository root:

```sh
STUDY_ANALYTICS_BENCHMARK=1 php -d memory_limit=768M vendor/bin/phpunit --do-not-cache-result tests/Feature/Study/StudyActivityAnalyticsPerformanceTest.php
STUDY_ANALYTICS_BENCHMARK=1 php -d memory_limit=768M vendor/bin/phpunit --do-not-cache-result tests/Feature/Study/StudyActivitySummaryAnalyticsPerformanceTest.php
STUDY_ANALYTICS_BENCHMARK=1 php -d memory_limit=768M vendor/bin/phpunit --do-not-cache-result tests/Feature/Study/StudyActivityAnalyticsFallbackPerformanceTest.php
```

All three use the actual authenticated Laravel HTTP kernel, an isolated SQLite database, PHP 8.5.6, a fixed clock (`2026-10-19T16:00:00Z`), and deterministic six-category histories. Ordinary CI uses 120 sessions and no timing thresholds. Large runs use 2,631 and 20,000 sessions with three repetitions; setup, assertions, and cache deletion are outside measurement. No other local builds/tests ran during the recorded intervals.

The unchanged first test uses the same three anchors and fixture as [the allocation benchmark](allocation.md), allowing exact payload comparison with PR1 (`4355cbdc`, merged as `e13f81aa`). The second records cold and warm full responses plus a narrow historical week. The full cache spans 1,479 days because the all-time quarter boundary precedes the four-year fixture's first session. The fallback test moves only the first session back 100 years. Its identical test file was executed against PR1's own dependencies and production code as well as the new implementation.

| Sessions | Anchor | PR1 full median (ms) | Cached full median (ms) | Reduction |
|---:|---|---:|---:|---:|
| 2,631 | 2026-10-19 | 79.59 | 25.98 | 67.3% |
| 2,631 | 2026-10-12 | 79.02 | 26.40 | 66.6% |
| 2,631 | 2026-09-19 | 78.01 | 25.86 | 66.9% |
| 20,000 | 2026-10-19 | 538.06 | 29.07 | 94.6% |
| 20,000 | 2026-10-12 | 542.44 | 29.33 | 94.6% |
| 20,000 | 2026-09-19 | 536.78 | 29.30 | 94.5% |

Relative to the original pre-optimization implementation, the same full responses are 92.5–92.7% faster at 2,631 sessions and 98.9% faster at 20,000 sessions. Those comparisons use the earlier recorded baseline, not a new production claim.

| Sessions | Full cold (ms) | Full warm (ms) | Historical week cold (ms) | Historical week warm (ms) |
|---:|---:|---:|---:|---:|
| 2,631 | 103.96 | 26.17 | 3.07 | 2.22 |
| 20,000 | 562.19 | 30.06 | 6.75 | 2.24 |

Full cold fill is slower than PR1's approximately 80/538 ms because it persists missing days. It performs four ledger queries (earliest-history metadata, cold day calculation, selected hourly day, current-day tail) and 17 batched inserts. Full warm performs the indexed minimum plus two bounded detail reads, with no writes. Historical week cold performs one bounded ledger query and one insert; warm performs neither. Narrow responses contain the exact corresponding full-response range, but comparing their latency with five-range requests changes the requested workload.

The century-history fallback remains essentially unchanged: 76.73 → 76.05 ms for 2,631 sessions and 533.27 → 530.33 ms for 20,000 sessions. All six fallback response hashes match; these sub-1% differences are not claimed as a meaningful speedup.

All repetitions, ranges, query counts, write counts, payload checksums, and response sizes are retained in [full warm results](summaries-api-full-warm.json), [cold/warm phases](summaries-api-phases.json), [fallback baseline](allocation-fallback.json), [fallback revised](summaries-api-fallback.json), and [comparisons](summaries-api-comparison.json). Process peak memory is cumulative, not per-request allocation. An earlier integration repeatedly parsed normalized day strings and regressed the smaller full-response workload; those diagnostic runs are preserved externally with an `-interim` suffix and are not final results.

## Query shape and PostgreSQL evidence

The ledger retains its `(user_id, started_at, id)` index and `(user_id, ended_at)` overlap index. Raw detail requests filter `ended_at > lowerBound` and `started_at < upperBound`, select only four columns, and order by start. Summary reads constrain `(user_id, timezone, algorithm_version, local_date)` in the same order as the existing unique index. No new schema or index is needed in this change.

[Local PostgreSQL 15 plans](summaries-api-postgres-plans.json) use the same 2,631-session synthetic fixture inside a rolled-back transaction. The minimum uses an index-only scan (one row); current-day and historical-hourly reads use the end-time index (zero and two result rows). PostgreSQL chooses a sequential scan for the full summary window because the isolated table contains exactly that user's 1,479 requested rows. This is an appropriate small-table plan, not evidence of a production full-table scan. The plans confirm bounded detail queries and available indexes; they are not production execution plans or a multi-user load test.

## Correctness and operations

The final full suite passed **4,720 tests, with 44 environment-gated skips and 33,488 assertions**. PostgreSQL semantic, mutation, and concurrency coverage passed **7 tests / 99 assertions**, including a writer adding earlier history while the analytics action waits on its owner lock. The PostgreSQL concurrency test is explicitly included in CI.

An independent original-Carbon oracle checks cold/warm numbers across spring and fall DST, fractional timestamps, historical hourly detail, process timezone changes, and future-ended sessions. Tests also cover cross-user isolation, cached zero days, creation, moving sessions between periods, category changes, deletion, long-history fallback, and year-zero anchors without PostgreSQL errors. Existing canonical payload fixtures remain unchanged. Composer lint passes; all 15 changed PHP files have CodeScene 10 with no findings. Backend self-review covered validated input, direct caller constraints, ownership, transaction ordering, raw UTC interpretation, cache invalidation, query/index alignment, and compatibility. The foundation owns invalidation; this change does not alter the separate weekly-recap allocator or cache.

Daily summaries are disposable derived state. A timezone database update can change historical local-day boundaries without changing UTC ledger records. During a tzdata rollout, operators must purge/rebuild the derived summary table or bump `StudyActivityDailySummaries::ALGORITHM_VERSION` and retire obsolete-version rows. Do not use `timezone_version_get()` as an automatic version key: system-backed PHP can report `0.system`. Coordinate a rebuild because the next request incurs cold-fill work; the raw UTC ledger remains authoritative.

The 3,660-day bound limits one cache fill, not lifetime storage across all requested timezones or algorithm versions. Monitor summary-row growth and prune obsolete versions/timezone contexts operationally if usage warrants it. Current-day data is deliberately uncached, and very long histories still use raw aggregation. Production latency after deployment should be measured separately.
