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

1. The production Episode/Course importer no longer truncates Learning-owned Episode
   creates, updates, or deletes.
2. Course creation and generation can consume Episodes stored in Learning OS instead of
   assuming every Episode exists in the Convo Lab source database.
3. The Convo Lab proxy preserves `blockDemoUser` behavior for create and delete.
4. Production smoke coverage creates, updates, reads, and deletes a disposable Episode
   through Convo Lab before the deployment is accepted.

The compatibility create route preserves the legacy non-idempotent contract because the
client does not send an Episode ID. A transport retry can create another Episode. Delete
is a hard delete; a retry returns a hidden `404` because ownership cannot be established
after the row is gone.

## Request Contract

The trusted proxy must send `X-Convo-Lab-User-Id` with the effective Convo Lab user UUID.
Learning OS stores that UUID as provenance and returns it as `userId`, while authorization
and ownership continue to use the mapped Learning OS user.

Create, update, and delete have separate rate-limit buckets. The API keeps Convo Lab's
camelCase payload and success-message shapes, normalizes UUID casing, and hides missing
and cross-user records behind the same `404` response.
