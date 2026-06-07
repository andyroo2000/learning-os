# Codex Guidance

This repository is a Laravel backend/API platform for shared learning domains such as flashcards, reviews, media, sync, courses, and ConvoLab compatibility surfaces.

Before pushing, opening, or updating backend API PRs, use the local `$backend-pr-self-review` skill when it is available. It captures recurring Claude review feedback for this repo and should be applied especially when a diff touches:

- FormRequests, request normalization, validated data, or client-visible ULIDs.
- ConvoLab compatibility adapters, camelCase payloads, or canonical domain-action reuse.
- Offline sync behavior, tombstones, idempotency, retry paths, or hidden 404 ownership behavior.
- Migrations, indexes, query ordering, cursor pagination, facets, or Postgres portability.
- Upload/header validation, byte-counting, file persistence, rate limits, transactions, or locks.

When the skill applies, inspect the branch diff against `main...HEAD`, patch real gaps before pushing, and include a concise PR self-review note naming the risky categories checked and the tests or lint commands run.

The repo-tracked copy of this skill lives in `docs/codex-skills/backend-pr-self-review/`. If the local skill is missing or appears stale, read that copy directly and update both the local skill and the repo copy when harvesting new Claude feedback.

When harvesting Claude feedback into repo skills, keep the guidance scannable: prefer a new short bullet or named clause for each distinct lesson instead of appending multiple clauses to an already-long bullet. If Claude flags discoverability or long bullets, refactor the nearby guidance while preserving concrete examples.

Keep changes small and consistent with the existing architecture: controllers handle HTTP, requests validate and normalize, actions/services own business behavior, models own persistence, and resources define response shape.
