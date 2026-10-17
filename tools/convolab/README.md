# ConvoLab CLI

Dependency-free Node 24+ POSIX (macOS/Linux) client for Learning OS through the ConvoLab public API.
Install locally with `npm install --global ./tools/convolab`, or run
`node tools/convolab/cli.js help` directly. No application database access is used.

Set `CONVOLAB_PROFILE` to a mode-600 JSON file containing `baseUrl` (for example,
`https://convo-lab.com`) and `token` (a dedicated, revocable access token).
An explicit `--profile FILE` overrides the environment. Credentials are bound to
that origin; redirects are refused. Existing mobile tokens have broad account
permissions, not fine-grained agent scopes. Never commit the profile or pass its
contents in command arguments. Store it outside the checkout.

## Inspect learning evidence

```sh
convolab whoami
convolab overview
convolab search --q 'でも' --limit 20
convolab note NOTE_ID
convolab card CARD_ID
convolab reviews --card_id CARD_ID --per_page 100
convolab path CARD_ID
convolab drafts
convolab draft DRAFT_ID
```

Successful commands emit API JSON on stdout. Failures emit a JSON diagnostic on
stderr and exit nonzero, including HTTP status and Retry-After when available.
No automatic write retries are performed. Requests time out after 30 seconds.

Lists return **one page**. Preserve the search/filter arguments and pass the
returned cursor with `--cursor` until the API reports no next page. Search uses
`nextCursor`; review events use Laravel pagination metadata. A partial page must
not be treated as proof that a card or draft does not exist. Search is textual:
inspect matching meanings and review evidence before inferring mastery.

## Verification and rollout

Run `npm test --prefix tools/convolab`. Tests execute the real CLI against an
HTTP fixture and check authentication, pagination, failures, and redirects.
The following PRs add authoring/learning paths, then isolated Laravel integration
tests and deployed lifecycle checks with cleanup of explicitly recorded test IDs.

## Author cards and staged learning paths

Payload files use the existing Learning OS study API's camelCase contract.
Run `convolab new-id` and save the returned ID in each create payload **before**
sending it. Reusing that payload makes a transport retry refer to the same card.
`create`, `draft-create`, and `commit` refuse payloads without stable ULIDs.

```sh
convolab create --file card.json
convolab edit CARD_ID --file edit.json
convolab draft-create --file listening-draft.json
convolab draft DRAFT_ID
convolab draft-edit DRAFT_ID --file corrected-draft.json
convolab draft-audio DRAFT_ID
convolab commit DRAFT_ID --file card-id.json
convolab audio CARD_ID
convolab link PREDECESSOR_SYNC_ID --file successor.json
convolab path PREDECESSOR_SYNC_ID
convolab delete CARD_ID
convolab draft-delete DRAFT_ID
```

Example `card.json` (replace `id` with your saved new-id result):

```json
{
  "id": "01k7r7bs5q0t5cmq09gnq7dkcq",
  "creationKind": "text-recognition",
  "prompt": { "cueText": "犬です。", "cueReading": "犬[いぬ]です。" },
  "answer": { "expression": "犬です。", "expressionReading": "犬[いぬ]です。", "meaning": "It's a dog." }
}
```

A listening draft uses `creationKind: "audio-recognition"`,
`cardType: "recognition"`, an empty prompt object, and the complete answer.
Draft creation starts asynchronous generation: poll `draft` until `status` is
`ready`, inspect its full fields/media, and only then `commit` with `{ "id": "SAVED_CARD_ULID" }`.
A ready draft is not a created card. `draft-retry` retries failed generation;
`draft-image` requests an image preview. Generated audio may consume provider quota.

`edit` requires `expectedRevision` from a fresh `card` read; refresh and reconcile
on HTTP 409. `audio-regenerate` accepts the existing API regeneration payload.
After changing an expression, regenerate and verify its audio before reporting
completion. The CLI does not judge Japanese quality or infer semantic mastery.

`successor.json` contains `successor_card_id` and an explicit
`unlock_requirement` (`successful_retrieval`, `guru`, or `master`). Use `syncId`
from card responses for canonical learning-path and review-event operations.
The backend owns milestone evaluation and synchronizes the resulting locks.

Linking existing cards is **not an atomic create-family operation**. A newly
created standalone card is available until linked. Do not study concurrently
while assembling a path; inspect all links afterward. Keep IDs in a local plan
so interrupted work can be inspected/resumed. Do not recreate cards blindly or
bulk-delete search results. `action` accepts explicit suspend/unsuspend payloads
when you need to keep an existing card out of study during manual authoring.
