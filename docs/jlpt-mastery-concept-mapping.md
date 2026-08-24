# JLPT mastery concept mapping

This branch starts the backend foundation for approximate JLPT mastery coverage.
The first schema slice deliberately records concepts and auditable card-to-concept
links without changing any public API or calculating a user-facing score yet.

## Data model

- `learning_concepts` stores a stable catalog identifier, vocabulary/grammar
  kind, JLPT level, display fields, a normalized match key, provenance, and the
  catalog's review status.
- `card_learning_concepts` stores one link per card/concept pair together with
  how the match was made, an optional confidence, classifier version, and JSON
  evidence. The composite primary key supplies the conflict target for
  idempotent upserts and prevents duplicate links.
- Coverage queries can start from the concept denominator index and join through
  the concept-first link index. Card-first reads use the pivot primary key.

## Rollout order

1. Confirm the catalog redistribution/attribution plan before copying the
   CC BY-SA grammar data into this proprietary backend repository or an app
   bundle.
2. Add a versioned, idempotent catalog importer. The importer should normalize
   match keys and upsert by the stable string concept ID without deleting links
   merely because a later catalog revision omits a row.
3. Add a deterministic exact matcher for card creation. Both manual cards and
   cards committed from drafts already converge on `CreateCardAction`; matching
   should run only for newly created cards and should not duplicate links on an
   idempotent create retry.
4. Add a resumable backfill command ordered by card ULID. It should process
   bounded chunks, use the same matcher as creation, and record its classifier
   version/evidence so reruns and future remapping are explainable.
5. Add semantic grammar classification only after exact matching is measurable.
   Low-confidence results should remain reviewable and outside the displayed
   denominator until promoted.
6. Expose per-level totals only after the aggregation contract is pinned: a
   concept is covered once, regardless of how many linked cards reinforce it,
   and its mastery is the strongest current linked-card mastery state.

The catalog validator should be added to CI when the catalog importer lands,
because that is the point where catalog changes can affect production data.
