# Architecture

Learning OS is a shared Laravel backend/API platform for ConvoLab, Currio, and future learning products.

The architecture should grow through small vertical slices. Add folders, services, abstractions, and shared contracts when a behavior needs them, not as placeholders for future plans.

## Goals

- Keep one shared Laravel backend/API while product surfaces can evolve independently.
- Share flashcard, review, media, sync, and course behavior across products where the behavior is genuinely common.
- Keep ConvoLab and Currio product-specific behavior out of shared domains unless there is real cross-product pressure.
- Support offline-first mobile clients and future iOS app needs from the first data-writing APIs.
- Make each PR small enough to review without reconstructing the whole system.

## Application Layers

Controllers handle HTTP concerns only: requests, responses, status codes, route parameters, and authentication context.

Actions and services handle business operations. Prefer explicit action names such as `CreateDeckAction` or `ReviewCardAction` when a use case has meaningful behavior.

Models handle persistence and relationships. Keep model methods focused on data behavior, not request orchestration.

Policies handle authorization. Avoid embedding product-specific access rules in controllers or shared actions.

Resources or transformers define API response shape. Responses should be deterministic and friendly to mobile clients.

## Identifiers

Use ULID primary keys for sync-facing domain models. Mobile clients may generate ULIDs while offline, and APIs should treat those identifiers as opaque strings.

Keep resource identifiers separate from idempotency identifiers. A deck ID identifies a deck; a future `client_event_id` identifies a client write attempt that may be retried.

## Domain Boundaries

Shared domains should hold behavior that can reasonably serve multiple products:

- `app/Domain/Flashcards`
- `app/Domain/Reviews`
- `app/Domain/Media`
- `app/Domain/Sync`
- `app/Domain/Courses`

Product domains should hold behavior that belongs to one product experience:

- `app/Domain/Products/ConvoLab`
- `app/Domain/Products/Currio`

Do not create empty domain folders just to reserve names. Introduce a domain directory with the first model, action, policy, resource, or test that gives it a real job.

## Offline And Sync Principles

Offline-first support is a core requirement, so write APIs should be designed with repeat submissions and client-side queues in mind.

- Prefer deterministic server behavior for retried requests.
- Add idempotency keys to event-like writes when duplicate delivery is plausible.
- For resource create endpoints that accept client-generated ULIDs, retry-safe creation depends on the client sending that ID. Requests without a client-generated ID can create duplicate resources after an ambiguous timeout.
- Preserve client context where useful, especially `client_event_id`, `device_id`, and `client_created_at`.
- Treat sync inputs as facts received from a client, then validate and apply them through explicit actions.
- Keep review and media events append-friendly unless a future behavior clearly needs mutation.
- Return enough identifiers and timestamps for clients to reconcile local state.

These conventions should appear in the first behavior that needs them. They do not require a broad sync framework before the first sync use case exists.

### Offline Change Feed Direction

Mobile clients will eventually need a server-ordered feed of changes they can consume after reconnecting. The first implementation should stay narrow and grow from a concrete product workflow, not a generic event bus.

- Start with one global monotonic server checkpoint as the client-facing high-water mark. Clients should store the last checkpoint they fully applied, then request changes after that checkpoint; split into per-domain checkpoints only when scale or isolation pressure makes that tradeoff worthwhile.
- Keep checkpoint identity separate from resource IDs and client idempotency keys. A checkpoint says "where the client is in the feed"; it does not identify a deck, card, media asset, or client write attempt.
- Scope feed entries to the resource owner and filter reads by the authenticated identity so clients only receive changes they are allowed to see.
- Record enough feed metadata for deterministic replay: domain name, resource type, resource ID, operation, server timestamp, and the checkpoint value. The checkpoint is the replay order key; the timestamp is for observability, retention, and display.
- Prefer compact resource snapshots over large event payloads unless a client needs historical transition details. Resource URLs are acceptable only for supplemental online fetches; tombstones and core field changes must carry enough data for the client to apply the feed entry without another request.
- Include tombstone-style delete records for resources that disappear from normal list endpoints.
- Return an explicit stale-checkpoint response when a client asks for changes older than the retained feed window, so the client knows to perform a full resource resync instead of applying a partial feed.
- Scope early feeds to the domains that need offline reconciliation first. Review events and media manifests already have offline pressure; courses can join when course writes exist.
- Keep feed reads cursor/checkpoint based. Avoid offset pagination because clients may reconnect while new writes are arriving.
- Treat the feed as an API contract, not as the only internal event mechanism. Internal domain actions can remain simple until a real background processing need appears.

## What Not To Do

- Do not copy ConvoLab prototype architecture blindly.
- Do not create duplicate flashcard, review, media, or sync implementations per product.
- Do not put product-specific rules into shared domains because it is convenient in the moment.
- Do not add broad base classes, registries, plugin frameworks, or inheritance trees before the pressure is real.
- Do not make large foundational rewrites when a vertical slice can carry the same learning.

## Near-Term PR Sequence

1. Add the first flashcard domain model: `decks` migration, `Deck` model, factory, and tests.
2. Add `cards` migration, `Card` model, deck relationship, factory, and tests.
3. Add `CreateDeckAction` and action-level tests.
4. Add `CreateCardAction` and action-level tests.
5. Add the first API route for creating decks, with controller, request validation, resource, and feature test.
6. Add review events with idempotency once there is a real review write path.
7. Add sync/media contracts only when the first concrete client behavior needs them.
