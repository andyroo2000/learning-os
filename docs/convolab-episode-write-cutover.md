# Convo Lab Content Compatibility

Learning OS exposes Convo Lab-compatible content routes under `/api/convolab`. The
Convo Lab frontend calls these routes directly with its first-party Laravel session.
The former service-token proxy and its `X-Convo-Lab-User-Id` trust contract have been
retired.

## Identity Contract

- Requests must use a stateful browser session from an allowed Convo Lab origin.
- Learning OS resolves provenance from the authenticated user's canonical
  `users.convolab_id`.
- Client-supplied identity headers and body fields are not authoritative.
- Mobile and other bearer tokens cannot access the Convo Lab compatibility surface.
- Admin impersonation remains explicit through validated `viewAs` query parameters and
  is available only to a live admin browser session.

Content reads and mutations scope ownership by both the authenticated Learning OS user
and the canonical Convo Lab UUID. Missing and cross-user records retain the legacy
hidden `404` behavior.

## Write Behavior

Episode and Course creates are Learning OS-owned. Updates promote imported content and
its referenced graph before mutation. Deletes create internal tombstones so a later
source import cannot recreate removed content.

The compatibility create route preserves the legacy non-idempotent contract because
the client does not send an Episode ID. A transport retry can create another Episode.
Create, update, delete, and generation operations use separate, operation-scoped rate
limit buckets keyed by canonical session identity.

## Deployment Checks

Before deploying compatibility changes:

1. Run the backend feature and migration suites on SQLite and Postgres.
2. Exercise read, create, update, generation, streaming, and delete flows through a
   Convo Lab browser session.
3. Confirm bearer requests receive `403`, unauthenticated requests receive `401`, and
   spoofed identity headers do not change the effective user.
4. Confirm imported content promotion and deletion tombstones prevent a subsequent
   import from overwriting or recreating Learning OS-owned state.
