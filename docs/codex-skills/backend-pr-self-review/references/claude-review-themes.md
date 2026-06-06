# Claude Review Themes for Backend API PRs

This reference summarizes recurring Claude feedback from recent `learning-os` backend PRs. Treat these as pre-push review rules, not style preferences.

## Validated Data Is the Source of Truth

- After a FormRequest validates data, access request values from `$this->validated()` instead of raw accessors such as `$this->input()`, `$this->has()`, `$this->integer()`, or `$request->query()`.
- Keep FormRequest `authorize()` aligned with Laravel's authorization contract. Authentication/401 behavior should usually come from route middleware; throw `AuthenticationException` from `authorize()` only when the route intentionally lacks middleware coverage and the 401 path is documented and tested.
- Preserve the distinction between omitted values and explicit nullable values. Use `array_key_exists` when `null` is meaningful; use `??` only when omission and `null` should behave the same.
- For PATCH/partial-update DTOs, make `has*` or presence flags the first gate for that field. If `hasImagePrompt` or `hasPreviewAudio` is false, oversized/malformed companion input should not be normalized or rejected because the caller explicitly chose not to touch it; cover every gated field type, including strings, JSON payloads, and media refs, for direct callers as well as HTTP.
- Laravel's `integer` validation rule does not cast query-string values. Cast validated integers before passing them into actions, filters, cursors, or pagination logic.
- Add tests for malformed array-shaped query/body inputs such as `?per_page[]=10` whenever a raw accessor previously existed or could accidentally coerce the value.
- For conditional validation such as `required_without`, `required_if`, `exclude_with`, or discriminator-derived fields, cover both stale-client payloads and the normal new-client shape where the excluded field is absent.
- Keep enum validation messages and accepted-value lists derived from the enum or a shared helper when possible. Hardcoded lists drift silently when enum cases change.
- Keep invalid-enum messages distinct from cross-field consistency messages unless legacy compatibility requires the same text. If a ConvoLab-compatible message intentionally conflates malformed values, missing payloads, or mismatches, add a short durable comment and a test that locks that client-visible wording.
- Keep missing-field, malformed-type, list-versus-object, and cross-field messages distinct when clients can act on the difference. Reusing one compatibility string for structurally different failures is only acceptable when legacy clients require it and tests lock the wording for each shape.
- When HTTP validation has specific messages but direct DTO/action validation can fail for the same payload family, avoid collapsing every direct-caller failure into a generic domain error. Prefer field-aware factories or at least targeted exceptions for likely mistakes such as media-kind/source mismatches.
- Keep repeated client-visible validation strings in one place, especially when the same message is returned from both `messages()` and `after()` callbacks. Use a private constant or helper so compatibility wording cannot drift between rule-based and custom validation paths.
- When a request relies on shared validation-message helpers, verify every rule key the new request can trigger, such as `field.present`, `field.required`, and `field.array`. Test null, scalar, list-shaped, and per-attribute variants so a passing string case does not hide a nullable path falling back to Laravel's default message.
- For optional nullable fields with defaults, cover both explicit `null` and omitted-key behavior when clients can rely on both shapes. Incidental coverage through unrelated error/rate-limit tests is weaker than a focused contract assertion.
- For partial updates, explicitly test the difference between omitted fields preserving existing state and present `null` fields clearing/defaulting that state. This is especially important for enum/default fields such as media placement where `null` maps to a concrete default.
- At DTO/direct-caller boundaries, do not use `?? []` or similar defaults to make a present-but-null required payload pass shape validation unless null-clearing is the intended contract. If HTTP rejects `field: null` for a present field, the DTO/action should usually reject `hasField: true, field: null` too.
- Decide whether an empty PATCH body is a valid no-op or a validation error. If autosave intentionally returns 200 with current state and no dirty write, cover or comment that behavior so future readers do not mistake it for missing validation. For valid no-ops, prefer an early return before transactions or locks unless the endpoint intentionally needs a fresh state recheck, and explicitly state whether status guards like `Generating` still apply to pure readback.
- Inside FormRequest `after()` callbacks, prefer the callback's `$validator` safe data or captured already-normalized data over calling `$this->validated()`. Re-entering validated-data logic inside validation callbacks is harder to reason about and can obscure which rule phase owns the value.
- In `after()` cross-field checks, treat a sibling field with existing validation errors as invalid even if raw input is present and non-null. Otherwise combined error responses can falsely skip the dependent-field error for malformed sibling payloads.
- Pick one normalization/defaulting contract for request accessors, helpers, and value-object methods: either accept raw input and normalize/default inside, or require pre-normalized input and trust callers. Avoid double-normalizing/defaulting in requests plus DTOs/actions unless the redundancy protects direct callers and is documented/tested.
- When a shared validation trait has an optional mode that skips assigning cached properties, initialize those properties to safe nullable defaults or make the accessors explicitly unavailable. Future callers should not get uninitialized-property errors merely by using a supported relaxed mode.
- For JSON request fields, validate shape as well as type once the client contract is known. If a discriminator such as `creation_kind` implies different valid JSON structures, cover each shape, malformed shape, and stale-client mismatch before the API writes to storage.
- Add size/depth limits for client-provided JSON before it enters sync or retry paths. Plain `array` validation can accept deeply nested or very large payloads that are expensive to canonicalize or serialize.
- If a JSON byte cap uses a custom `json_encode` option set, compare it to the serialization that storage/model casts actually use or document that it is a soft pre-storage estimate. Tight caps should measure the authoritative representation, not a prettier or more compact proxy.
- When HTTP and action layers intentionally enforce the same JSON limit, avoid duplicated magic constants or add a focused equality/contract test. A mirrored guard is good defense-in-depth, but silent drift between layers creates incompatible direct-caller and HTTP behavior.
- Sharing constants is not enough when HTTP and DTO/action layers copy the same nontrivial validation algorithm. Recursive JSON depth/size checks, media-reference shape checks, and similar rules should usually live in a pure validator or value object that both FormRequests and direct-caller DTOs delegate to, keeping HTTP message mapping at the request edge.
- When DTOs/actions accept the same JSON arrays as a FormRequest, mirror shape/depth limits at that boundary or document why the action is HTTP-only. Direct callers should not bypass ConvoLab-compatible payload guards merely because HTTP validation caught them first.
- For shared JSON guards, cover each distinct client-visible guard through HTTP and direct action tests when both entrypoints exist. A depth test through HTTP plus a size test only at the action layer still leaves the compatibility response shape unproven.

## Client-Visible ULID Normalization

- Normalize client-provided ULIDs before `ulid` validation in FormRequest `prepareForValidation()`.
- Normalize again at action or DTO boundaries when direct callers can bypass HTTP requests. If this double normalization looks redundant, leave a short comment explaining the caller contract.
- Preserve non-string invalid inputs. Arrays, objects, omitted optional values, and whitespace-only values should still reach validation or explicit guards in a way that produces the expected error.
- Do not merge optional IDs as `null` when the client omitted the field. Omission and explicit null are different when validation rules or action contracts depend on presence.
- Check adjacent client-visible IDs before pushing: `id`, `card_id`, `course_id`, nested batch IDs, route params, deck/card/media/review event fields, and list filters.

## Middleware-Disabled Normalization Tests

- When a FormRequest owns trimming or lowercasing, add focused tests with `withoutMiddleware(TrimStrings::class)`.
- Cover trim-only, lowercase-only, and combined padded-uppercase input when each can regress independently.
- Keep at least one full production middleware-stack test for uppercase or padded input. Do not replace stronger existing coverage with a narrower middleware-disabled test.
- For blank or whitespace-only values without `TrimStrings`, assert the expected 422 boundary behavior unless the action is intentionally responsible for rejecting blanks.

## Layer and Domain Coverage

- If a request and action both accept the same value, test both the HTTP path and direct action path.
- When a pattern changes in one domain, inspect sibling domains before pushing: courses, decks, flashcards/cards, media assets, review events, sync feeds, and auth/user isolation.
- For list filters, include happy path, malformed ULID, blank/whitespace, cross-user isolation, pagination query preservation, and any deleted-resource behavior relevant to the endpoint.
- For batch payloads, preserve item indexes and non-array item behavior so validation errors point to paths such as `events.0.id`.
- Server-owned fields should not be mass-assignable on the model just because request/action layers currently strip them. Use explicit assignment or a dedicated domain method, and add a direct model-boundary test when the ownership contract matters.
- When testing process-owned fillability, assert every protected field named in the PR or model contract, including ownership fields such as `user_id`, derived fields, status fields, and error/retry metadata.
- If one persisted field is derived from another, such as `card_type` from `creation_kind`, default to making the derived field process-owned: remove it from fillable input, derive it from the canonical field, and test direct model creation/update. Use a caller-supplied consistency guard only when the PR documents why derivation is not appropriate.
- If an action validates a persisted field for consistency, prefer assigning that field in the action so the invariant is self-contained. Rely on model hooks/accessors for derivation only when the action does not own the input or the PR explicitly documents why central derivation is required; tests should inspect stored attributes when persistence is the contract.
- Shared domain limits and accepted-value lists should live at the domain/model/support level, not copied across Create DTOs, Update DTOs, and FormRequests. When create and update endpoints intentionally share caps such as media sources or prompt lengths, use one named constant/helper so future changes cannot drift by layer. Put wire-format or serialization limits, such as JSON byte/depth caps, media-reference allowed keys, and source strings, in a support validator/value object when they describe client payload shape more than persistence.
- If a shared constant or enum-like list must stay in sync with migration constraints, casts, serializers, or another domain's accepted values, leave a narrow doc comment or test that points future edits to the mirror location.
- For edits to records in an error/status state, decide whether user changes preserve or clear server-owned error metadata. Preserve old `error_message` only when that is intentional and covered or commented; clear it in the same action when an edit semantically means the user corrected the failed input.
- If a model hook derives a persisted field on `saving`, avoid dirtying the derived field on unrelated updates. For existing models, put the "source field is clean" early return before validation that is only needed for derivation, and test a pure status/metadata save if enum casts or raw/original comparisons can make dirty tracking noisy.
- Test intentional model-invariant exceptions at the model boundary. If a `saving` hook throws when a required source field is missing on create, add a focused test so future lifecycle changes do not turn the invariant into an accidental 500 path; make sure the application guard wins before a NOT NULL or FK constraint if the exception shape matters.
- Test explicit programming guards on action inputs when a PR introduces them, even if controllers normally make the branch unreachable. Examples include non-positive user IDs, missing persisted model IDs, or invalid direct-caller preconditions that throw `LogicException`. Match the test data to the named boundary: a "non-positive" guard should cover both zero and a negative value, not only `0`; prefer a data provider or separate tests over manual try/catch loops so each case has clear failure attribution. When targeting one guard, pass valid values for unrelated arguments so future validation-order changes cannot make the test pass for the wrong reason. Compare sibling actions so one CRUD action does not grow a defensive direct-caller guard while create/update/delete siblings silently omit or intentionally avoid it.
- When a model helper's contract is wider than the database constraint, name that boundary. For example, if a NOT NULL enum source field can still be set to `null` in memory, decide whether the helper should throw, ignore unchanged updates, or rely on validation before future update actions call `save()`.
- Check Eloquent event ordering when multiple hooks mutate or validate the same model. `saving` runs before `creating`/`updating`, so a later exception can leave in-memory attributes changed even though nothing persisted; callers that catch such exceptions should not reuse the tainted model instance.
- Factories should not set process-owned or hook-derived fields that the model always overwrites. Dead factory values make the contract harder to see and can hide regressions where the hook stops running.
- For immutable model fields guarded by Eloquent events, choose `creating`, `updating`, or both deliberately. Test that initial server assignment is allowed and later mutation is rejected, so lifecycle-event behavior is not left implicit.
- When a controller maps several domain exception outcomes, test every branch that changes client-visible status or secrecy: cross-user conflicts should stay hidden, deleted-resource conflicts should use the intended gone/conflict shape, and normal conflicts should remain distinct.
- When a domain exception family maps named validation outcomes back to request fields, test each field-producing branch through the HTTP layer. For paired fields such as front/back, prompt/answer, or role/media, do not let the first missing side mask the second branch; construct one case where the first side is valid and the second side fails.
- User-scoped write actions should make their ownership boundary explicit. Prefer accepting the authenticated user ID/principal separately from the target model, or document and test that direct callers must pass a model already scoped to the intended user; re-querying by `$model->user_id` only proves the model exists for its owner, not that the caller is that owner.
- Avoid blind `(int)` casts of `$request->user()->getAuthIdentifier()` at controller boundaries. Prefer an existing integer user ID property/helper, validate the identifier shape before casting, or document that the guard is configured for integer IDs; otherwise a future string/UUID guard can silently become `0` and surface as a 500 from a downstream domain guard.
- When enforcing per-user caps or queue limits on soft-deletable models, state whether trashed rows count. Eloquent's default scope excludes soft-deleted records from `count()`, which may be correct, but the active-only versus lifetime-cap decision should be visible near the query and covered when it affects client-visible conflicts.
- When a controller catch or action guard is dead for normal HTTP callers because the FormRequest already rejects the input, leave a short comment that it protects direct action callers and add direct action coverage for that defensive path.
- Keep sibling domain exception hierarchy consistent. If conflict and validation exceptions use meaningful base classes, avoid introducing a not-found or input exception as a bare `RuntimeException` unless the difference is intentional and controller/action callers are prepared for it.
- Prefer typed FormRequest accessors for validated controller inputs when a request already exposes them. Avoid mixing `$request->validated()` array indexing for one field with accessors for the rest unless the asymmetry is intentional. When a controller calls custom accessors supplied by a trait, confirm they are visible in the touched trait/request, have a clear return type or PHPDoc shape, and return the payload form the controller expects.
- For PATCH pairs where one field describes another, such as `previewAudioRole` and `previewAudio`, define the invariant against existing model state. Either allow role-only updates intentionally and comment the partial-update semantics, or add a cross-field/state-aware guard so a role cannot exist without the corresponding media reference. Cover same-request clear-plus-role cases, not only role-without-existing-media cases.
- Audit cross-domain imports in new models/enums/actions. A Study class depending on Flashcards types can be correct, but call out the intended layer direction in the PR when the dependency could otherwise become a future cycle; keep the translation in one obvious location instead of spreading the dependency through the domain. When the dependency is only for persistence, consider storing a scalar at the boundary and hydrating the other domain's enum closer to that domain.
- When one domain maps to another domain's enum with `match`, add an exhaustiveness test or follow-up reminder so new/renamed cases in the source enum cannot create a hidden runtime unmatched-arm failure.
- When status/lifecycle columns imply required payload fields, make the assumption explicit near the model, migration, or factory state. For example, a `generating` draft with non-null seed JSON should say whether generation starts from user-supplied seed content or should allow empty pending content.
- Keep factory states that model public/media payload shapes synchronized with the canonical resource shape once that resource exists. Hardcoded draft/fake shapes are fine for storage slices, but should not silently become a second contract.
- Resource serializers should default to the model's cast contract via magic properties or `getAttribute()`, especially for client-facing enum values. Use `getAttributes()` only when raw storage values are the explicit contract, and test that choice; it returns database scalars and bypasses enum casts.
- Resource helpers that coerce values should state and enforce their value-shape contract. If a helper is string-only for enum/string fields, document that or guard unexpected booleans, integers, arrays, and objects so future fields do not silently serialize with the wrong JSON type.

## Compatibility Adapter Invariants

- Keep compatibility controllers thin: translate request/response shape at the edge and reuse the canonical domain action for business behavior.
- Keep ConvoLab-specific field names and camelCase mapping in Study request/resource/support classes. Do not push those names into shared Flashcards, Reviews, Media, Courses, or Sync actions unless the canonical contract is intentionally changing.
- Test both the compatibility shape and the canonical invariant it delegates to: idempotent retry, no-op update behavior, sync feed emission or suppression, hidden 404 ownership behavior, and deleted-resource behavior.
- For idempotent retry/conflict behavior, test both the compatible no-op case and a true mismatch that must raise the conflict. This is especially important for canonicalized JSON, enum/type fields, and legacy-null defaults where the happy path can hide a broken comparison branch.
- For hard-delete endpoints without tombstones, decide the retry contract explicitly. If already-deleted resources return 404 because ownership can no longer be proven, document that mobile/offline clients must treat that 404 as acceptable or add a tombstone/deleted-ID mechanism before promising idempotent 204 retries.
- When a compatibility response remaps resource data, prefer direct mapping from the canonical model/result over fragile round-trips through another resource's serialized array.
- Keep HTTP/form field naming in the adapter layer unless there is a deliberate, narrow reason for a domain exception to expose it. If a domain exception returns a validation field name for controller mapping, treat it as an adapter convenience and keep those factories aligned with FormRequest `messages()` keys as new cases are added. For virtual error keys that clients never submit, such as aggregate `payloads` errors, align with sibling endpoints and add a test/comment so readers know the key is intentional.
- Do not make one FormRequest class the public helper surface for another request. If store/update requests need the same message builder, enum list, or payload helper, move it to a shared trait/support class that both requests already depend on.
- If a query scope joins tables or changes projection, assert that selected columns and loaded models still contain the expected base model fields. Watch for `select cards.*` or join projections wiping out data needed by a caller.
- Create-style compatibility endpoints that clients may retry need a stable client-supplied resource ID or an explicit "not retry-safe" contract. Idempotent setup work, such as resolving a default deck, does not make the final resource create idempotent by itself.
- If a compatibility discriminator must live on a shared model, document the boundary decision, add lookup indexes for the real query shape, and test that unrelated canonical update APIs cannot flip the discriminator accidentally.

## Upload and Header Validation

- FormRequests own HTTP header syntax and should report malformed headers through Laravel validation errors, not domain exceptions. Domain actions own semantic checks and direct-caller defense.
- Persist and compare authoritative values from actual received bytes. Caller-declared sizes such as `Content-Length` are inputs to validate, not source-of-truth fields to store.
- Count binary payload bytes with `mb_strlen($contents, '8bit')` when file/archive content is involved.
- Cover all load-bearing upload guards: empty content, actual bytes over max, declared size over max with small actual payload, declared/actual mismatch, malformed header, native-integer overflow with longer digit count, equal-length value just above `PHP_INT_MAX`, and success with matching declared size.
- For failure paths, assert no DB mutation and no storage write when validation happens before persistence.

## Concurrency and Transaction Boundaries

- Expire-then-check, claim-then-process, and active-record guards should run under the same transaction and per-user or per-resource lock pattern as the write path they protect.
- For update/autosave endpoints that first resolve a model in the request and later reject mutable states in the action, review the stale-read window. If a background job can flip status between authorization and save, either lock/refresh inside the write transaction or document that the next retry safely observes the conflict.
- Move validation that can be decided from request data plus already-authorized model state before `lockForUpdate()`. A 422 that does not need a fresh locked row should not acquire a write lock; keep locked-action checks for invariants that truly depend on the current committed row.
- If an empty PATCH/no-op path skips the normal write transaction, review it as a separate race path. It should still re-check ownership, soft deletion, and mutable status when those outcomes are client-visible, with focused action coverage if the branch has its own query.
- Defensive re-queries outside the main write path should state the race they protect, such as a delete between `authorize()` and action handling, so they do not look like redundant ownership checks.
- Durable behavior comments belong near the invariant owner. Prefer an action docblock or domain method comment for retry/idempotency/delete semantics rather than burying the only explanation in route definitions.
- If a read endpoint performs state mutation such as expiring stale jobs, review it like a write: transaction boundary, lock ordering, cross-user isolation, and race behavior.
- Avoid nested transaction surprises around actions that already require an outer transaction. If an action must be called inside a transaction, assert the transaction-level contract or fail fast with a clear exception.
- For double-check locking patterns, test both first-create and race/adopt paths, including soft-deleted rows that must not be resurrected or adopted accidentally.
- Keep side effects such as storage writes, sync feed entries, and job state transitions after all validation and conflict checks that can fail.
- Server-derived fields should not produce user-visible updates merely because a legacy nullable row is being backfilled in memory. If a nullable migration window exists, test that unchanged user content does not emit sync/feed entries only to fill a derived column.
- Treat mid-request deletion of users, decks, cards, jobs, or other locked rows as a runtime race, not a programming error. Prefer a domain-appropriate 404/403/409-style exception over `LogicException` or another path that surfaces as a 500.
- When a shared or exclusive lock protects a later action only because the caller keeps an outer transaction open, make that caller contract explicit in the action docblock and tests. Locks acquired inside Laravel nested transactions/savepoints are held to the outer commit on MySQL/Postgres, but that dependency is easy to miss.
- Keep steady-state paths from taking broader locks than the invariant needs. A parent/user `lockForUpdate()` may be right for first-use creation, but existing-resource paths should usually prefer a narrower resource lock, shared lock, or no lock when the later write is already protected.
- Choose `sharedLock()` versus `lockForUpdate()` intentionally and comment the concurrency tradeoff when it is not obvious: shared locks can block concurrent updates/deletes while permitting concurrent reads/creates, whereas exclusive locks serialize more than necessary.
- Remember SQLite does not exercise row-lock semantics like MySQL/Postgres. If correctness depends on row locks, pair SQLite feature tests with transaction-level assertions, grammar/SQL checks, or narrow comments explaining what local tests cannot prove.

## Existing Coverage Must Not Weaken

- Do not change an existing test from a stronger input to an easier one just to add another scenario. Add a new test or keep the old assertion.
- When replacing helper code, ensure all previous edge cases still have named tests.
- If a PR removes a guard, assertion, migration fixture, or branch, explicitly verify that another test still covers the invariant.

## Migration and Postgres Portability

Postgres compatibility is first-class for this project. Take migration portability comments seriously even if the current local database is SQLite.

- For schema or index changes, include grammar fixture tests for SQLite, MySQL, and Postgres when the generated SQL can differ by grammar.
- Assert rollback/drop SQL, not just create SQL.
- Pin exact compiled SQL only when the SQL text is the real risk, such as Postgres identifier length, dialect-specific clauses, or rollback grammar. Prefer structural schema/index assertions when a Laravel grammar upgrade could fail a full-snapshot test without a semantic schema change.
- Migration data backfills should state their operational shape when row counts may be large: chunk size, one-update-per-row behavior, expected runtime/lock impact, and why set-based SQL is not being used.
- Keep migrations self-contained even when that duplicates application helpers, but leave a short comment when duplicated parsing/normalization logic is intentional so future edits do not accidentally couple old migrations to evolving app code.
- Keep index names under Postgres's 63-byte identifier limit and test the actual names generated by the migration.
- Verify column order against the real query pattern. Put equality predicates before range/order columns when that is what the query uses.
- For low-cardinality filters such as type/status, confirm the index starts with the most selective real predicate. A filter-only index may be dead weight when every query is user-scoped through another table; use `EXPLAIN` or a production-shaped query analysis when in doubt.
- Use one source of truth for expected index names and fixture SQL where possible.
- If SQLite and Postgres expectations are intentionally identical, leave a concise comment so future maintainers know the duplication is deliberate.
- When migration tests intentionally duplicate a migration blueprint or DDL snapshot, add a concise comment pointing future editors back to the migration as the source of truth so schema changes are updated in both places.
- If a grammar fixture test uses a lightweight or mismatched PDO only to compile SQL for another dialect, keep the test strictly compile-only and comment that it must not exercise connection/PDO execution paths.
- Confirm the migration file actually exists, is loaded by the test, and matches the column/table names used by production queries.
- For enum-like state columns, decide whether the invariant lives at the database layer. Laravel `enum()` can compile to check-backed SQLite/Postgres SQL and native MySQL enum on create, but `change()` migrations can be SQLite-rewrite-heavy; do not introduce enum/check-constraint DDL unless SQLite, MySQL, Postgres, and rollback behavior are proven.
- Finite role/status/type strings should usually have a backed enum and Eloquent cast before public APIs depend on them. Leaving a role as a bare string invites invalid values and undocumented contracts.
- When a DB check constraint is deferred, keep the application serializer defensive around nullable legacy/raw rows and state the tradeoff in the PR note.

## Query, Cursor, and Ordering Invariants

- Cursor pagination and access token lists need deterministic ordering, including tie-breakers for equal timestamps.
- Cursor tokens and query binding must preserve the precision of every ordered column. The cursor value used in `>` and `=` keyset branches must round-trip at the same precision the DB stores; if the column is second-precision, encode/truncate to seconds deliberately, and if it is microsecond-precision, test page edges with non-zero microseconds.
- Cursor precision comments should explain the pagination consequence, not just the column type. State why truncation or exact precision is required for the equality tiebreaker branch to find same-timestamp rows.
- Cursor decoders should validate every required field before parsing or constructing domain values. Guard empty timestamp strings explicitly, and prefer exact-format parsing such as `createFromFormat()` for server-generated opaque tokens; permissive `Carbon::parse()` / `CarbonImmutable::parse()` can accept relative or partial strings that should not be valid cursors.
- Cursor encoders with persisted-model preconditions should have guard tests too. If `encode()` throws when `id` or `created_at` is missing, add a focused test for a new/unpersisted model.
- Define pagination metadata semantics clearly. If `total` is the full filtered count rather than remaining count after the cursor, keep it stable across pages and test or document the client-facing contract.
- Choose default and max page sizes with payload weight in mind. If each item can include large JSON blobs or media metadata, document the intended response-size tradeoff or a known safe bound instead of only validating the numeric limit.
- For list endpoints, include the empty result contract in either action or API coverage: empty items array, null next cursor, and the expected total/count semantics.
- For user-scoped cursor endpoints, test cursor reuse across users. A valid cursor produced for user A must not leak, skip into, or shape user B's result set beyond B's own scoped records.
- Avoid duplicate cursor decoding on the HTTP path when a FormRequest already validates and parses the cursor. Prefer a typed accessor/DTO from the request if the action can accept it without weakening direct-caller validation; if the action deliberately decodes again to protect direct callers, leave a short comment so HTTP 422 vs action exception boundaries stay clear.
- Normalize collection keys consistently after pagination slicing. Even if `get()` currently returns zero-based keys, call `values()` on all paginated branches when the response contract is a JSON array.
- When adding an index for a future keyset-paginated list, include the deterministic tiebreaker column such as `id` in the intended ordering/index plan, or explicitly defer it until the query layer lands.
- If adding or changing a filter predicate, confirm there is an index that supports the new query shape.
- Keep pagination query strings stable when filters are present; tests should confirm filters survive next/previous links.
- Avoid hidden assumptions such as integer primary key ordering unless that is part of the documented model contract.
- Helper methods that build query patterns should make blank rejection and wildcard escaping explicit, including `@throws` docs or direct tests when direct action callers can bypass HTTP validation.
- For facet/filter option endpoints, pin the cross-filter contract in tests: selected filters should remain visible when appropriate, unfiltered/search-only paths should return the same options as the SQL fallback, and query-count optimizations must preserve semantics.
- For aggregate subqueries and `leftJoinSub` refactors, test zero-count rows, cross-user isolation, deleted cards/decks, and the exact fields needed by detail endpoints.
- When optimizing query count, assert the count against the behavior being optimized and keep a semantic regression test beside it; a faster wrong result is still wrong.

## Sync and Offline Invariants

- Client-provided IDs should preserve idempotent retry behavior.
- Cross-user conflicts should stay hidden as 404/empty responses when that is the API contract.
- Deleted resources, tombstones, and conflict responses must remain consistent with existing sync behavior.
- Resource payload keys should remain client-facing and include context such as `course_id` consistently across equivalent sync resources.
- Enum-cast fields in resource or sync payloads should serialize the client-facing value, but include a default or explicit guard when null legacy/raw fixtures can reach the serializer.
- If a resource passes JSON-cast fields through directly, assert representative `prompt`/`answer` or media JSON payloads in API tests, not only surrounding scalar fields.
- For sync feeds, check ordering, checkpoint behavior, and payload shape together; Claude often flags only one symptom of a broken invariant.

## Rate Limiting

- Keep named limiter policy out of `AppServiceProvider` when the keying/defaults belong to a domain. Providers should wire the limiter; domain support classes should own the key formula and default limit.
- Use one source of truth for limiter names across `RateLimiter::for()`, route middleware, tests, and comments.
- When multiple endpoints reuse the same named limiter, state whether they intentionally share one quota bucket. If the interaction is user-visible, add a cross-endpoint test or PR note so later route additions do not accidentally throttle sibling workflows. Place route comments so they visibly cover every route sharing the quota, not only the route immediately below the comment.
- High-frequency write endpoints such as autosave should have an explicit throttle decision. Add permissive per-user throttling, reuse a documented shared bucket, or explain why the endpoint is safe without route middleware; do not leave rapid PATCH/retry paths unbounded by accident.
- Destructive endpoints such as DELETE are often manual gestures, but client bugs and retry loops can still hammer them. If sibling create/update routes have throttle middleware, default to adding a generous route limiter to DELETE too; rely on global API throttling only when that is an established repo convention and the PR calls it out.
- Test key fallbacks for missing user and missing or empty IP. If tests need key internals, expose the smallest stable key-format method rather than duplicating the formula in feature tests.
- Choose rate-limit key dimensions deliberately. Combining user ID and IP gives the same user fresh buckets when networks change; if the policy is truly per-user, use user ID when present and reserve IP only as an unauthenticated fallback, then test/comment the chosen behavior.
- Avoid sentinel collisions in limiter keys. Prefix typed identities such as `user:123` and `anon:1.2.3.4` instead of using a bare value like `missing-user` as both a placeholder and a branch condition.
- When tests temporarily override a named limiter, isolate hit counters with a unique per-run bucket or clear the exact keys before and after. Restoring only the limiter definition is not enough because cached attempts can leak.
- Assert the throttled response status and default limit values directly when a PR changes limiter behavior or its tests.
- If a limiter helper accepts an override such as `$perMinute` for tests, either cover the override with a focused unit assertion or keep the override local to the test so it does not look like an untested production contract.
- If a test mirrors a Laravel middleware internal such as `md5($limiterName.$key)`, isolate that coupling in test support or comment the dependency so a framework upgrade fails loudly.

## Style and Maintainability Nits That Recur

- Avoid no-op `merge([])` calls or comments that describe a temporary implementation detail.
- Remove unreachable defensive catches that make normal data look more dangerous than it is. If a `JsonException`, domain exception, or other catch exists only for impossible values through the HTTP path, either prove the direct-caller path can reach it with a focused test/comment or simplify the code.
- Do not duplicate private helper methods across sibling tests or request classes when a local helper/trait already exists.
- Prefer static helpers for fixture/index-name builders that do not depend on instance state.
- Remove redundant casts, `forceFill` calls, or assignments unless they protect a real invariant.
- When embedding a Laravel resource or resource collection inside a custom response shape, prefer an approach that preserves the intended Laravel resource pipeline, and test the exact envelope. Inline `AnonymousResourceCollection` values can serialize cleanly inside `response()->json(...)` without a top-level `data` wrapper, but that is non-obvious enough to cover with tests or comments; direct `toArray()`/`resolve()` calls should be deliberate because they may bypass hooks, metadata, wrappers, wrapping, `additional()` data, or filtering behavior. For show endpoints, compare adjacent controllers before choosing `return Resource::make(...)` versus `response()->json(Resource::make(...)->resolve($request))`.
- If a short helper centralizes repeated guard and assignment logic, use it consistently across the touched fields.
- When extracting shared traits/helpers, preserve existing PHPDoc generic shapes and static-analysis contracts unless widening them is intentional and covered by callers. A runtime-equivalent type widening can still be a regression for tooling.
- If a recursive validator is bounded by a payload-size cap, keep that cap nearby in tests or comments so future increases do not turn an O(total nodes) walk into an accidental performance issue.
- Avoid creating thousands of Eloquent factory records just to reach a max-count guard. If tests only need aggregate count, use chunked bulk inserts, a small injectable/overridden limit, or a direct setup helper so the test proves the invariant without making every CI run pay for production-scale fixtures. Do not replace slow factories with one huge `insert()` that can exceed SQLite's bind-parameter limit; choose chunk sizes from the actual row width and leave margin for future columns instead of sitting at SQLite's 999-parameter edge. If the chosen chunk size is a non-obvious value like 90, comment the verified bind-limit arithmetic near the helper, such as rows times the real inserted column count versus SQLite's 999-parameter ceiling; incorrect arithmetic is worse than no comment.
- Match local Laravel model conventions unless there is a reason to introduce a newer mechanism. For example, use `#[Fillable]` only when the repo already does or the PR explains the move from `$fillable`.
- Do not add convenience helpers such as enum `values()` methods speculatively when local validation uses `Rule::enum()` or enum cases directly. Add them when a caller needs them, or note the intended follow-up use.
- Keep factories in the repository's established namespace/location for domain models; if a new domain suggests a different factory organization, call that out instead of letting conventions split silently.

## Pre-Push Self-Review Note

Before pushing, write a short internal note or PR body paragraph covering:

- Which categories from this reference matched the diff.
- What sibling endpoints/domains were checked.
- Which tests and lint commands ran.
- Any intentional portability, sync, or validation tradeoffs that reviewers should not have to rediscover.
