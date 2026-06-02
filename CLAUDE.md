# Claude Review Guidance

This repository is a Laravel backend/API platform for shared learning product domains such as flashcards, reviews, media, and sync.

When reviewing pull requests:

- Prioritize correctness, bugs, security, performance, and missing tests.
- Respect the small-PR strategy. Avoid asking for broad rewrites or speculative abstractions unless the current slice creates real drift.
- Controllers should stay focused on HTTP concerns.
- Actions and services should contain business operations.
- Models should stay focused on persistence and relationships.
- Resources should define API response shape.
- Product-specific behavior should not leak into shared domains.
- Offline/mobile sync paths should be idempotent and retry-safe when relevant.
- Prefer explicit naming and Laravel conventions already used in the repo.
- Domain actions that need to signal created/updated/deleted/no-op outcomes should prefer small domain-specific result objects in `app/Domain/<Domain>/Results`. Avoid shared base classes or interfaces until multiple concrete callers need them.
- Retry-safe soft delete actions should preserve idempotency at both layers: HTTP callers look up models with `withTrashed()` when retries should succeed, and actions guard already-trashed models as successful no-ops.
- Keep review comments concise and actionable.
