# Private readings

Readings are imported by a trusted operator into one existing account. There is
no frontend authoring interface. Session or personal-token authentication and
owner checks protect the document, illustrations, and sentence audio; `viewAs`
is prohibited.

```sh
php artisan readings:import /private/path/book.json \
  --user=owner@example.com --slug=stable-book-name \
  --illustration=/private/path/cover.jpg
```

The same owner and slug update the existing book. JSON `imageFile` values are
trusted local paths read by the operator command. Keep the source JSON and
photos outside source control and public web directories. Files are copied
into the `local` private disk, under `readings/<id>/`; production persists that
disk through the existing `storage/app` volume. Content-addressed old images
and audio remain after re-import, so operators can recover previous content;
the command does not automatically prune them.

Pages carry their printed page number, kind, and exact vertical columns. Each
column contains `[sentenceIndex, rubyText]` pairs. Ruby uses the existing
`漢字[かんじ]` notation. Sentences carry display `text`, English `translation`,
and optional `speechText` pronunciation overrides. Page numbers are distinct,
pages and sentences are lists, and every segment must reference a sentence.

Audio defaults to ナレーター男性 and is cached by voice and spoken text. Changing `speechText`
invalidates that sentence's cache without changing the printed text. The
generation quota counts provider attempts, including failures, to bound
retries and provider load. Cached playback does not consume this quota.

## API and CLI imports

The CLI uses the same authenticated API as clients. Create a stable ID with
`convolab new-id`, then save this manifest alongside your source images:

```json
{
  "id": "<generated ULID>",
  "document": {
    "title": "A walk in the forest",
    "author": "Author",
    "pages": [
      {"number": 24, "kind": "text", "columns": [[[0, "犬[いぬ]です。"]]]},
      {"number": 25, "kind": "illustration", "columns": [], "imageFile": "page25.png"}
    ],
    "sentences": [{"text": "犬です。", "translation": "It is a dog."}]
  },
  "coverFile": "cover.jpg"
}
```

Run `convolab reading-import --file reading.json`. Image paths resolve beside the
manifest. The CLI sends multipart image bytes, never server-readable filenames.
Uploads accept JPEG/PNG files under 10 MB each and a combined 24 MB bundle.
Reimport the same ID and content safely; changed content requires the latest
`expectedRevision` at the manifest root. A conflict requires refreshing the
reading and deliberately reconciling changes; the CLI does not retry writes.
Omitting a cover on replacement retains the existing cover.

`convolab readings --page 1` lists 20 recently added books and standalone readings.
`convolab reading ID` gets one complete reading. Books and their ordered reading
IDs use the `books`, `book`, `book-create`, `book-edit`, and `book-order` commands
(documented in the CLI README). Grouping a reading hides it from the library root
but leaves its document, images, and ID intact. Removing it from a book restores
it to the root. There is no individual reading deletion endpoint.

API endpoints are `GET /api/reading-library`, `POST /api/reading-imports`, and
`/api/reading-books`. Existing `/api/convolab/readings/{id}` document/media/audio
routes remain compatible and now also accept personal tokens. Book contents use
persisted summary columns; they never load chapter document JSON. The original
unpaginated readings endpoint remains for existing web clients.

## Narration

Run `convolab reading-narrate READING_ID` to prepare every sentence with the
ナレーター男性 narrator. Use `--voice Sato`, `--voice "ナレーター男性"`, another
catalog display name, or a full `fishaudio:<32-character reference ID>` to choose
another voice. Progress goes to stderr; the final JSON reports the narrator and
prepared sentence count. The CLI respects the per-owner generation quota, waits
on quota/lock responses with bounded retries, and stops on other failures. Rerun
the command after interruption: already generated audio is cached by voice and
spoken text. The reading's narrator is changed only after all sentences succeed,
using its revision to avoid overwriting concurrent edits. Old voice audio stays
cached; switching voices never reuses another voice's clips.

The sentence audio POST accepts optional `voiceId` to prepare a voice without
changing the current narrator. PATCH `.../readings/{id}/narration` accepts
`voiceId` and `expectedRevision`. Clients omit voiceId for normal playback and
receive the reading's chosen narrator. Narration playback remains sentence-based,
so original text selections and pronunciation overrides align with the audio.

## Reading position

`PUT /api/reading-progress/{readingId}` records `pageNumber`, nullable
`sentenceIndex`, `readingRevision`, `readAt` (ISO timestamp), and a stable UUID
`eventId`. Keep the event unchanged when retrying an offline save. The newest
reading timestamp wins; a delayed or repeated event returns the current position
without moving the reader backwards. Equal timestamps break ties by event ID.
Device clocks more than five minutes ahead are rejected. A content revision
conflict returns 409, so clients must reload and validate their local position.
The page and selected sentence must belong to the document; null sentence means
only the page is selected.

`GET /api/reading-progress/{readingId}` returns `{ "position": ... }` or null.
`GET /api/reading-progress` returns `{ "continue": ... }` with the most recently
read document summary and its position, including readings nested in books.
All position routes enforce ownership and prohibit impersonation. Clients retain
a pending local position until the API returns an acknowledgement, and scope
local storage to the signed-in account.
