# Convo Lab Episode Write Cutover

Learning OS exposes Convo Lab-compatible Episode create, update, and delete routes at
`/api/convolab/episodes`. These routes are dormant until the dedicated Convo Lab proxy
token receives the `content:write` ability.

Deploying the backend routes alone is safe. The production token rotation currently
grants Study and feature-flag abilities only, so no production caller can reach these
writes.

## Activation Gates

Do not grant `content:write` or proxy Convo Lab Episode mutations until all of these are
true:

1. **Complete:** the production Episode/Course importer replaces only Convo-imported
   roots. Creates are marked as Learning-owned, updates promote imported Episodes and
   their referenced media and Course links, and deletion tombstones prevent source rows
   from returning.
2. Course creation and generation can consume Episodes stored in Learning OS instead of
   assuming every Episode exists in the Convo Lab source database.
   - **Complete:** Learning OS exposes a dormant Convo-compatible Course create route that
     atomically links imported or Learning-owned Episodes and supports inline source text.
   - **Remaining:** migrate Course generation and its status lifecycle to consume the new
     Learning-owned Course graph before proxying Course create or activating Episode writes.
3. The Convo Lab proxy preserves `blockDemoUser` behavior for create and delete.
4. Production smoke coverage creates, updates, reads, and deletes a disposable Episode
   through Convo Lab before the deployment is accepted.

The compatibility create route preserves the legacy non-idempotent contract because the
client does not send an Episode ID. A transport retry can create another Episode. Delete
is a hard delete; a retry preserves the legacy hidden `404`. Its internal tombstone exists
only to keep the source importer from recreating the row.

## Request Contract

The trusted proxy must send `X-Convo-Lab-User-Id` with the effective Convo Lab user UUID
for reads and writes. Learning OS stores that UUID as provenance and returns it as
`userId`. Episode and Course reads and Episode mutations scope ownership by both the
mapped Learning OS account and the effective Convo Lab user.

Create, update, and delete have separate per-Convo-Lab-user rate-limit buckets. Every
mutation scopes ownership by both the authenticated Learning OS account and the effective
Convo Lab user UUID. The API keeps Convo Lab's camelCase payload and success-message
shapes, normalizes UUID casing, and hides missing and cross-user records behind the same
`404` response.
