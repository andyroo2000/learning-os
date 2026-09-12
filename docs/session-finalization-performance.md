# Desktop session finalization performance

Investigated September 12, 2026 against ConvoLab `e7b5f08f` and Learning OS
`0c58fd7d`.

## What the desktop is waiting for

`useStudySessionCompletion.refreshCompletion` forces a fresh achievement
evaluation through `POST /api/achievements/evaluate`. The desktop disables Done
until that request settles. Individual reviews have already been committed before
the wrap-up starts. The request is serialized behind any previous achievement
refresh, and this fetch has no application-level timeout.

The normal backend path processes reviews added since the previous achievement
projection. Undo, out-of-order history, certain study-session edits, and a missing
or outdated projection can instead rebuild lifetime history. That rebuild scans
review history multiple times for metrics, per-card facts, and earned dates.

## Implemented optimization

- Select only the review columns used by achievement calculations. Full review
  rows also contain undo snapshots and import payloads that these calculations
  never use. Apply this to incremental evaluation and historical backfills.
- Replace sorting all new reviews to locate the newest creation cursor with a
  single pass. This avoids repeatedly converting timestamps during comparisons.
  Continue processing reviews in reviewed-at order, and preserve the creation
  timestamp plus ID tie-break used to fetch the next batch.

Ownership filtering, archived-history inclusion, transactions, award semantics,
and the API response remain the same.

## Local measurements

PHP 8.5.6, SQLite in memory, 500 cards, an initial 10,000 historical reviews, and
synthetic 2 KB undo snapshots. Fixture creation is outside the timed evaluation.
These are single-run diagnostic measurements, not production latency estimates.

| Evaluation | Before | After |
| --- | ---: | ---: |
| Initial 10,000-review bootstrap | 1,782 ms | 1,429 ms |
| 25 new reviews | 12 ms | 12 ms |
| 250 new reviews | 61 ms | 53 ms |
| 1,000 new reviews | 225 ms | 162 ms |
| Rebuild 11,275 historical reviews | 1,967 ms | 1,578 ms |

The benchmark asserts review totals at every step. Run it explicitly; it is
outside the default test suites:

```sh
php -d memory_limit=768M vendor/bin/phpunit --do-not-cache-result tests/Performance/Achievements/SessionFinalizationBenchmarkTest.php
```

Verification: all 24 achievement feature tests and 10 achievement unit tests pass.
The added regression covers creation order differing from review order, equal
creation timestamps, cursor advancement, and exact review/correct-run totals.

## Desktop wait removed

The companion [ConvoLab PR #658](https://github.com/andyroo2000/convo-lab/pull/658)
keeps Done available while achievements update in the background. Dismissed
sessions persist only their achievement baseline and pending award IDs, separately
from review records. Late responses and a subsequent Study visit can recover
badges without reopening the old summary. Session IDs and refresh revisions
protect a new session and undo/re-end flows; acknowledging a badge removes
pending duplicates. Tests cover late responses, reload, failure recovery, account
isolation, legacy saved sessions, and immediate Done with 1,000 reviewed cards.

## Further opportunities

1. Consolidate the full-history rebuild passes, or rebuild only the affected
   metric family when study-time data changes. Preserve exact first-earned dates
   and chronological correct-run behavior.
2. Consider occasional progress-only refreshes during long sessions, with
   throttling and final-request ordering, to spread incremental work across the
   session without prematurely awarding badges.

Production timing logs were not available in this investigation. Existing
`Achievement metric projection updated.` and `Achievement progress evaluated.`
logs already record incremental/rebuild mode, row counts, and projection versus
award-reconciliation time. Correlate those with a slow desktop request before
claiming the cause of a particular session or prioritizing a larger backend change.
