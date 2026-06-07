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
   - Call out intentionally unchanged sibling behavior only when it is relevant to review.

## Hard Stops Before Push

Do not push until these are resolved or explicitly justified in the PR:

- Raw request accessors like `$this->input()`, `$this->has()`, `$this->integer()`, or `$request->query()` are used after validation where `$this->validated()` should be the source of truth.
- Query-string integers rely on Laravel validation to cast values; cast validated values explicitly.
- Client-provided ULIDs are accepted without pre-validation normalization in FormRequests and action/DTO boundaries that direct callers use.
- Normalization converts arrays, omitted optional fields, or other non-string invalid inputs into `null` or another value that hides validation errors.
- Compatibility validation messages do not cover every rule key and input shape the request can trigger, especially null/scalar/list variants for `present`, `required`, and `array` rules.
- The same client-visible validation message is reused for structurally different failures, such as missing paired payloads versus present-but-wrong JSON shape, without a compatibility note and tests.
- A shared validation trait adds a relaxed/optional mode but leaves typed cached properties or accessors with an implicit uninitialized-property failure path.
- Request-owned trimming/lowercasing lacks tests with `withoutMiddleware(TrimStrings::class)`, or new tests replaced a stronger full-middleware-stack test.
- A request-level fix lacks corresponding direct action tests when the action has a direct caller contract.
- Partial-update DTO `has*`/presence flags do not short-circuit validation or normalization for fields the caller explicitly marked as untouched.
- Partial-update DTOs allow `hasField: true` with `null` for fields that HTTP validation treats as required/non-null when present, causing direct callers to silently clear or default persisted state.
- FormRequest `after()` cross-field checks infer a sibling field is valid from raw presence/non-null input without checking the sibling field's validation errors.
- JSON shape, size, or depth limits exist only in the FormRequest while the DTO/action accepts the same arrays from direct callers.
- JSON shape, size, or depth limits exist in the DTO/action but lack HTTP coverage for the client-visible validation response.
- HTTP and DTO/action layers duplicate nontrivial validation algorithms instead of delegating to a shared pure validator/value object, especially for recursive JSON shape, size, depth, or media-reference checks.
- A pattern was fixed for one domain while equivalent course/deck/card/media/review endpoints were left unchecked.
- Schema or index work lacks Postgres portability coverage, rollback/drop SQL assertions, index-name length checks, or query-pattern column-order review.
- Server-owned fields are exposed through model mass-assignment instead of explicit action assignment or a dedicated domain method.
- Enum-cast fields are serialized with assumptions that null legacy/raw rows can never happen, unless the database constraint and tests prove that invariant.
- Sync/offline behavior changed without checking idempotent retry, cross-user isolation, deleted-resource behavior, and resource payload shape.
- Commit/convert endpoints do not define whether idempotency is keyed by the source, the client-provided target ID, or both, allowing the same source object to be committed multiple times with different IDs without a test/comment.
- Compatibility endpoints skip canonical domain actions, leak ConvoLab field names into shared domains, or lack tests for canonical and compat response shapes.
- Upload/header validation crosses layers incorrectly, persists caller-declared sizes instead of actual bytes, or lacks boundary tests for malformed, overflow, mismatch, and side-effect-free failure paths.
- New destructive or retryable write endpoints omit route throttle middleware when sibling create/update routes are throttled, unless a repo-wide convention and PR note make the exemption explicit.
- Rate limiter changes lack explicit route wiring, stable key fallback coverage, default-limit coverage, and isolated test buckets or cleanup for temporary overrides.
- State-transition or active-record checks run outside the transaction/lock boundary used by the corresponding write path.
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
