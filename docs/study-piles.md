# Study piles and chapters

Piles describe a learner's sources (books, audio collections, or personal material).
They are independent of decks, card learning paths, and review scheduling.

## Catalog API

All endpoints require the existing authenticated study API session or bearer token.

- `GET /api/study/piles?cursor=0&limit=50` returns `piles`, `total`, and
  `next_cursor` (an integer offset, or null). Each pile includes its chapters in
  ascending `position`, then ID order. Pagination accepts 1–100 piles per page.
- `PUT /api/study/piles/{pileId}` creates or partially updates a pile and returns
  `{ "pile": { ... } }`.
- `PUT /api/study/piles/{pileId}/chapters/{chapterId}` creates or partially updates
  a chapter and returns `{ "chapter": { ... } }`.

Callers supply stable ULIDs and reuse them on retry. IDs normalize to lowercase.
Both writes accept `title` (1–160 characters), `source_reference` (nullable,
up to 512 characters), `position` (0–1,000,000), and `is_active` (boolean).
Title is required when creating a record. Omitted fields preserve existing values.
An empty update is a no-op; records cannot move between owners or books.

New piles **and chapters** default to inactive and position 0. A book toggle does
not rewrite any chapter's selection. Clients should show the parent pause while
retaining those selections. New imports should not opt into activation implicitly.
The title and reference can identify a specific book edition; chapter position is
an explicit numeric ordering field, not a lexical sort of the title.

Writes emit atomic `study` sync records of type `pile` or `pile_chapter` only when
the record changes. Payloads include the catalog fields and timestamps; chapter
payloads also contain `pile_id`. Listing includes inactive piles and chapters.

This initial catalog is additive: card assignment and new-card selection integrate
in subsequent changes. No existing card or review eligibility is changed by this
migration. Sources that cannot be identified confidently must remain uncategorized.

Catalog writes have a dedicated 120/minute per-user bucket, independent of session startup. Pagination is an offset over the current order; clients should reload from cursor zero after reordering. Chapters are returned inline for book-sized collections; exceptionally large collections should be split into separate piles until chapter pagination is available.

## Card source metadata

Card and draft creation accept `bookSource` with `pileId`, optional `chapterId`, numeric `position`, and optional `reference` (page, exercise, or audio timestamp). Shared Flashcards creation uses the same object under `book_source`. IDs must belong to the caller and a chapter must belong to the specified pile. This does not activate the pile, change ingestion provenance, or bypass progression locks. Generated vocabulary bundle drafts inherit the source, and committed cards preserve it. Creation retries compare the source along with content.

`PUT /api/study/card-sources` accepts `{ "cardIds": ["canonical-card-ulid"], "bookSource": { "pileId": "pile-ulid", "chapterId": "chapter-ulid", "position": 12, "reference": "p. 98" } }`. Up to 100 existing cards can be assigned atomically; `bookSource: null` clears the assignment. An identical retry emits no additional sync records. Source updates advance content revision but leave review scheduling intact. When assigning a chapter in bulk, omit position unless all cards truly share that position; use individual creation positions for textbook order.

Unassigned existing cards remain uncategorized. Do not infer a textbook from the Japanese word alone: use import deck names, tags, chapter annotations, or an explicit user decision. Keep uncertain matches for review.
