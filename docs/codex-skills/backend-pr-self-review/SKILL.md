---
name: backend-pr-self-review
description: Review backend API pull requests before pushing or updating GitHub PRs, especially Laravel API work in learning-os, using recurring Claude feedback themes around validation, ULID normalization, compatibility adapters, sync behavior, concurrency, rate limiting, upload validation, test coverage, query indexes, and Postgres-safe migrations.
---

# Backend PR Self Review

## Overview

Use this skill before pushing, opening, or updating a backend API PR when the work could receive Claude review feedback. It turns recurring comments from recent `learning-os` PRs into a local self-review pass so issues are fixed before GitHub review.

For detailed rules, read `references/claude-review-themes.md` whenever the diff touches validation, request normalization, sync/offline behavior, compatibility adapters, state transitions, uploads, rate limits, migrations, indexes, pagination, or client-visible IDs.

## Workflow

1. Establish the branch diff against the merge base, not only staged files.
   - Run `git status --short --branch`.
   - Run `git diff --stat main...HEAD` and inspect `git diff main...HEAD`.
   - Identify every touched surface: request classes, actions, DTOs, resources, migrations, models, policies, routes, and tests.

2. Classify the change before reviewing.
   - Request validation or query parameters.
   - Client-visible ULIDs or route/body IDs.
   - List filtering, cursor pagination, or stable ordering.
   - Offline sync payloads, tombstones, idempotency, or conflict behavior.
   - ConvoLab compatibility adapters, camelCase payloads, or canonical domain-action reuse.
   - Upload/header validation, byte-counting, or file persistence ordering.
   - Rate limiter registration, keying, route middleware, or test overrides.
   - Concurrent state transitions, locking, transactions, or retry-safe soft deletes.
   - Schema migrations, indexes, rollback SQL, or database grammar tests.
   - Resource serialization or cross-domain behavior across courses, decks, cards, media, and reviews.

3. Apply the recurring Claude checklist.
   - Use the hard-stop checklist below first.
   - Then inspect the detailed reference for the categories that match the diff.
   - Compare adjacent sibling endpoints and tests so a fix is not applied in only one domain.

4. Patch gaps before pushing.
   - Prefer the repository's existing helper patterns and test style.
   - Preserve existing coverage while adding focused regression coverage.
   - Keep comments durable: explain the invariant, not the class name that happens to enforce it today.

5. Verify with commands matched to the risk.
   - Always run focused tests for touched behavior.
   - Run `composer lint` and `git diff --check` before pushing.
   - If migrations or indexes changed, run the relevant migration SQL/grammar tests and ensure SQLite, MySQL, and Postgres expectations are covered where applicable.

6. Leave a concise self-review note for the PR.
   - Mention the high-risk categories checked.
   - Mention commands run, and make sure file names/paths still match the final diff after renames or test consolidation.
   - Call out intentionally unchanged sibling behavior when it is relevant to review; cite the prior PR/test or caller constraint when a nearby action is deliberately skipped.

## Hard Stops Before Push

Do not push until these are resolved or explicitly justified in the PR:

- Raw request accessors like `$this->input()`, `$this->has()`, `$this->integer()`, or `$request->query()` are used after validation where `$this->validated()` should be the source of truth.
- Query-string integers rely on Laravel validation to cast values; cast validated values explicitly.
- Client-provided ULIDs are accepted without pre-validation normalization in FormRequests and action/DTO boundaries that direct callers use, or a normalizer runs before the format guard without proving it is safe for arbitrary garbage strings.
- A malformed-ID guard is buried in a private lookup helper while the public action method can run other logic before reaching it, unless that pre-helper path is proven side-effect free and intentionally differs from sibling actions.
- Normalization converts arrays, omitted optional fields, or other non-string invalid inputs into `null` or another value that hides validation errors.
- Compatibility validation messages do not cover every rule key and input shape the request can trigger, especially null/scalar/list variants for `present`, `required`, and `array` rules.
- The same client-visible validation message is reused for structurally different failures, such as missing paired payloads versus present-but-wrong JSON shape, without a compatibility note and tests.
- A shared validation trait adds a relaxed/optional mode but leaves typed cached properties or accessors with an implicit uninitialized-property failure path.
- Request-owned trimming/lowercasing lacks tests with `withoutMiddleware(TrimStrings::class)`, or new tests replaced a stronger full-middleware-stack test.
- A request-level fix lacks corresponding direct action tests when the action has a direct caller contract.
- A direct action regression test is placed only in an adjacent API/upload/controller test file instead of the action's own focused test class, making the guard hard to find when the action changes.
- Partial-update DTO `has*`/presence flags do not short-circuit validation or normalization for fields the caller explicitly marked as untouched.
- Partial-update DTOs allow `hasField: true` with `null` for fields that HTTP validation treats as required/non-null when present, causing direct callers to silently clear or default persisted state.
- FormRequest `after()` cross-field checks infer a sibling field is valid from raw presence/non-null input without checking the sibling field's validation errors.
- JSON shape, size, or depth limits exist only in the FormRequest while the DTO/action accepts the same arrays from direct callers.
- JSON shape, size, or depth limits exist in the DTO/action but lack HTTP coverage for the client-visible validation response.
- HTTP and DTO/action layers duplicate nontrivial validation algorithms instead of delegating to a shared pure validator/value object, especially for recursive JSON shape, size, depth, or media-reference checks.
- A pattern was fixed for one domain while equivalent course/deck/card/media/review endpoints were left unchecked.
- A sibling action or endpoint with the same apparent input surface is intentionally out of scope but the PR note does not cite prior coverage, caller constraints, or a concrete reason the sibling cannot receive the same bad input.
- Schema or index work lacks Postgres portability coverage, rollback/drop SQL assertions, index-name length checks, or query-pattern column-order review.
- Server-owned fields are exposed through model mass-assignment instead of explicit action assignment or a dedicated domain method.
- Enum-cast fields are serialized with assumptions that null legacy/raw rows can never happen, unless the database constraint and tests prove that invariant.
- Sync/offline behavior changed without checking idempotent retry, cross-user isolation, deleted-resource behavior, and resource payload shape.
- Sync feed recording is added without proving no-op resubmits and transport retries do not duplicate create/update/delete entries.
- A new side effect is asserted on one happy path or single no-op test while existing provider-backed terminal/missing/no-op cases lack assertions that the side effect stays absent.
- Sync feed writes happen inside the model-state transaction without deciding whether feed insert failures should roll back the model mutation or be deferred/handled as recoverable side effects.
- A worker final-exhaustion path can lose the terminal error-state write because a lower-priority sync/feed side effect runs in the same rollback boundary and can throw.
- A `DB::afterCommit()` side effect can throw after the state commit without catch/report/recovery or an explicit "fatal side effect" comment.
- A lifecycle path intentionally uses a different transaction/side-effect boundary than sibling paths but lacks a durable comment explaining why the asymmetry must be preserved.
- A hard-delete sync tombstone has caller-supplied `deleted_at` semantics but leaves clients or future maintainers to infer how it relates to pre-delete `updated_at`.
- A generic `\BackedEnum` helper returns `->value` with a `string` return type even though integer-backed enums can also satisfy the input type.
- Commit/convert endpoints do not define whether idempotency is keyed by the source, the client-provided target ID, or both, allowing the same source object to be committed multiple times with different IDs without a test/comment.
- Compatibility endpoints skip canonical domain actions, leak ConvoLab field names into shared domains, or lack tests for canonical and compat response shapes.
- Upload/header validation crosses layers incorrectly, persists caller-declared sizes instead of actual bytes, or lacks boundary tests for malformed, overflow, mismatch, and side-effect-free failure paths.
- Upload or import action error-path tests omit `Storage::fake()` because today's guard should run before storage access, leaving future regressions free to touch the real disk.
- New destructive or retryable write endpoints omit route throttle middleware when sibling create/update routes are throttled, unless a repo-wide convention and PR note make the exemption explicit.
- Rate limiter changes lack explicit route wiring, stable key fallback coverage, default-limit coverage, and isolated test buckets or cleanup for temporary overrides. For new named limiters that must have separate quotas, verify the pinned Laravel throttle path actually namespaces counters by limiter name or add an operation prefix to the raw `Limit::by()` key; do not assume key collision or isolation without checking sibling limiters and framework behavior.
- State-transition or active-record checks run outside the transaction/lock boundary used by the corresponding write path.
- A shared state-transition helper documents a lock/transaction requirement that some real call sites do not satisfy.
- A controller/action moves a model into a worker-owned pending state such as `Generating` and then dispatches the worker outside the transaction/commit boundary, allowing a queue write failure to strand user-visible state.
- A queued job owns user-visible pending state but has no final-exhaustion/`failed()` path to move the resource to an actionable error state or emit an equivalent signal.
- A final-exhaustion/`failed()` path is not proven idempotent for repeated calls, including preserving existing terminal timestamps or error metadata.
- A job lifecycle method such as `handle()` or `failed()` hides domain dependencies behind container resolution when the dependency can be declared through a framework-supported path that is safe for queued serialization.
- A job exposes Action internals as public static transition helpers solely so the worker can bypass the container or direct dependency boundary.
- Job lifecycle methods such as `handle()`, `uniqueId()`, and `failed()` apply different normalization contracts to the same persisted/client-visible ID.
- A transaction captures timestamps at a boundary that conflicts with the intended semantics, such as logical operation time versus actual locked-write time.
- A query-log test does not set up the measured section with `DB::enableQueryLog(); DB::flushQueryLog();`, assuming enabling clears stale entries or that a pre-enable flush is enough to document a clean slate.
- A test freezes time with `travelTo()`, `Carbon::setTestNow()`, or similar process-global clock state without an auto-resetting closure, `travelBack()` in `finally`/`tearDown`, or another proven cleanup path.
- A test assigns `$queries` or another assertion input only inside `finally` and consumes it after the block in a way that is hard for readers or static analysis to trust, or adds a dead fallback initializer that reads like a meaningful default.
- A helper named like a capture/extractor utility hides exception-shape, database, or side-effect assertions that the calling test name/body does not reveal.
- Surfacing helper assertions back into tests creates three or more copy-pasted query filters, failure messages, or side-effect assertions instead of extracting a thin, honestly named assertion helper that accepts already-captured data.
- A test that expects an exception puts query-log, database, or side-effect assertions inside `finally`, allowing a failed assertion to replace the expected domain exception. Capture/cleanup in `finally`, then assert after catching the expected exception, or split exception and side-effect coverage into separate focused tests.
- Base `tearDown()` clock cleanup does not choose reset-before-parent versus parent-before-reset deliberately.
- Base `tearDown()` clock cleanup relies on subclasses calling `parent::tearDown()` without verifying or documenting that contract where future overrides will see it.
- An empty PATCH/no-op branch bypasses mutable-state guards, such as `Generating` or locked status, without a test or durable comment that readback is intentionally allowed.
- Existing behavior coverage was weakened to make a new test pass.

## Review Commands

Use commands like these, adjusting test paths to the touched files:

```bash
git status --short --branch
git diff --stat main...HEAD
git diff main...HEAD
composer lint
git diff --check
php artisan test tests/Feature/... tests/Unit/...
```

When reviewing an existing PR's Claude comments:

```bash
gh api repos/<owner>/<repo>/issues/<pr-number>/comments --jq '.[] | select(.user.login=="claude" or .user.login=="claude[bot]") | {kind:"conversation", author:.user.login, created_at, body}'
gh api repos/<owner>/<repo>/pulls/<pr-number>/comments --paginate --jq '.[] | select(.user.login=="claude" or .user.login=="claude[bot]") | {kind:"inline", author:.user.login, path, line:(.line // .original_line), created_at, body}'
```

## Output Shape

When using this skill, return findings first. For each finding, include the file, line or narrow area, severity, why Claude is likely to care, and the concrete fix. If no issues remain, say that clearly and list the verification commands that passed or could not be run.
