# ConvoLab CLI

Dependency-free Node 24+ POSIX (macOS/Linux) client for Learning OS through the ConvoLab public API.
Install from a packaged release as described below, or run
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
The integration suite below exercises Laravel over HTTP and verifies cleanup
of explicitly recorded test IDs.

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
Draft creation starts asynchronous **text enrichment**: poll `draft` until
`status` is `ready` (`error` means generation failed). Inspect and correct the
expression, reading, and meaning before generating audio. For listening cards:

1. Run `draft-audio DRAFT_ID`, then fetch `draft DRAFT_ID` again.
2. Require a nonempty `previewAudio` and `previewAudioRole: "prompt"`.
3. Run `draft-edit` with `expectedRevision` from that fresh draft, a prompt
   containing only `cueAudio: previewAudio`, and the complete answer object plus
   `answerAudio: previewAudio`. Preserve expression, reading, meaning, and notes.
   The preview is separate from the final card fields; commit does not apply it.
4. Refetch and verify the fields, then `commit` with `{ "id": "SAVED_CARD_ULID" }`.
   On HTTP 409, re-read and reconcile instead of replaying a stale edit.

For other card types, apply answer-role preview audio to `answer.answerAudio`.
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

## End-to-end verification

The default credential path is `~/.config/convolab/profile.json`; `--profile`
and `CONVOLAB_PROFILE` override it. Install from a clean merged checkout using
`npm pack ./tools/convolab`, then `npm install --global PATH_TO_TARBALL` so the
installed executable does not depend on a temporary Git worktree.

`npm run test:integration --prefix tools/convolab` creates a temporary SQLite
schema, two isolated accounts, and a real local Laravel HTTP server. It invokes
CLI subprocesses for create/read/search/edit/link/draft/delete, checks retries,
revision conflicts, pagination and cross-account isolation, and posts simulated
review events to verify that the backend unlocks the correct stage. It removes
its test database afterward. External media generation is not run in CI.

For a deployed smoke test, provision a **disposable account** (never a learner
whose review statistics matter) and save its credentials in a private profile:

```sh
node tools/convolab/integration/live.js --profile /private/test-profile.json \
  --directory /private/new-smoke-run --execute --media
```

`--media` additionally waits for real listening draft generation, commits it,
checks prompt audio/answer fields, retries the commit, and prepares answer audio.
It streams the generated audio through the authenticated API and checks a positive
duration with `ffprobe` (required only for `--media`). Downloaded test audio is removed.
The operator-only `integration/provision.php` can be streamed to `php` in the
backend container from its app root. Capture its output privately; it includes
an expiring fixture token and a random password for deleting the disposable
account via `DELETE /api/me` with `current_password` after the run. Add the exact
public API `baseUrl` to the profile before running the CLI.

The live harness writes a manifest **before** creating cards. It deletes only
those exact card/draft IDs in `finally`, checks that reads return 404, and retains
the manifest and payloads for diagnosis. It verifies the cleanup account matches
the original account. For a killed process or failed cleanup, use:

```sh
node tools/convolab/integration/live.js --profile /private/test-profile.json \
  --directory /private/existing-smoke-run --cleanup
```

Do not reuse a run directory for new tests. A machine crash cannot run `finally`;
retain the manifest until cleanup is confirmed. Deleting cards alone need not
reverse analytics, so remove the disposable account too. Production smoke tests
are explicit operator actions, never automatic CI jobs against personal data.

## Organize reading books

Books contain ordered readings; a reading may remain outside a book. These
commands use the same private account token as the card commands:

```sh
convolab books --page 1
convolab book-create --file book.json
convolab book BOOK_ID
convolab book-edit BOOK_ID --file title.json
convolab book-order BOOK_ID --file contents.json
```

`book.json` contains a saved `new-id` result as `id` and a `title`.
Creation retries with the same ID and title return the existing book. A
conflicting title returns 409. `title.json` contains `title` and
`expectedRevision` from the latest book response.

`contents.json` contains `expectedRevision` and `readingIds`, the complete
ordered list of reading IDs for that book. An empty list removes every reading
from the book without deleting content. Remove a reading from its old book
before assigning it to another. On 409, fetch the book and reconcile; do not
blindly repeat a stale update. This initial two-step move is not atomic.

Book responses include ordered readings and their original printed page numbers.
`books` returns 20 books per page, newest created first; follow `next_page_url`
by incrementing `--page`. No title, type, or count labels are imposed on clients.

### Importing readings

Use `convolab reading-import --file /private/reading.json` to upload a document
and its illustrations. The manifest contains a stable `id`, `document`, optional
`coverFile`, and `expectedRevision` for a replacement. See
[the readings manifest documentation](../../docs/readings.md) for the complete
format. `convolab readings --page 1` lists the mixed library; `convolab reading ID`
returns the full reading. Original ruby annotations are supplied by the importer;
the server does not generate or replace furigana.

### Preparing narration

`convolab reading-narrate READING_ID` prepares every sentence using ナレーター男性.
Pass `--voice Sato`, another catalog name, or `--voice fishaudio:REFERENCE_ID`
to select a voice. The command prints progress to stderr and a final JSON result
to stdout. It waits on quota responses; rerunning resumes through the audio cache.
Only a completed run changes the reading's selected narrator.
