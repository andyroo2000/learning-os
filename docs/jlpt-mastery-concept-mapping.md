# JLPT mastery concept mapping

The first vertical slice provides an intentionally approximate N5 mastery estimate.
Vocabulary and grammar remain separate metrics; there is no combined score.

## Data model

- `learning_concepts` stores a stable catalog identifier, vocabulary/grammar
  kind, JLPT level, display fields, a normalized match key, provenance, and the
  catalog's review status.
- `learning_concept_aliases` stores exact expression/reading keys and conservative
  Japanese grammar surface fragments.
- `card_learning_concepts` stores one link per card/concept pair together with
  how and when the match was made, an optional confidence, classifier version, and JSON
  evidence. The composite primary key prevents duplicate links.
- Coverage queries can start from the concept denominator index and join through
  the concept-first link index. Card-first reads use the pivot primary key.

## Catalog and matching

- The immutable `resources/jlpt/v1` catalog has 684 vocabulary concepts and 77
  grammar concepts. Checksums and row counts are enforced by the data migration.
- Attribution and the data licenses are preserved beside the catalog.
- Vocabulary uses exact normalized expression or reading matches.
- Grammar uses surface fragments of at least two Japanese characters. This is
  deliberately conservative and will undercount patterns that are expressed only
  by a one-character particle or by a conjugation absent from the catalog pattern.
  Surface fragments shared by more than one catalog concept (such as `です`) are
  considered ambiguous and do not produce automatic matches.
- Both manual cards and cards committed from drafts converge on `CreateCardAction`.
  New-card matching runs as a best-effort post-commit analytic so a matching
  failure cannot block card creation. Content edits refresh automatic links;
  manual links are preserved. Idempotent create retries do not add duplicate links.
- Existing active cards can be processed with
  `php artisan learning-concepts:backfill`. Use `--after=<card ULID>` to resume,
  `--chunk=<1-2000>` to bound batches, or `--dry-run` to measure without writing.

## Mastery calculation

Each concept contributes the strongest current state among its linked active
cards: Apprentice/new = 0%, Guru = 25%, Master = 50%, Enlightened = 75%, and
Burned = 100%, using the existing FSRS stability thresholds. The level/type
percentage is the sum of those concept weights divided by that type's catalog
denominator. `covered` counts concepts linked to at least one active card even
when their current mastery weight is zero.

The compatibility API shape is:

```json
{
  "jlptMastery": {
    "N5": {
      "vocabulary": {"masteryPercent": 34, "covered": 280, "total": 684},
      "grammar": {"masteryPercent": 21, "covered": 29, "total": 77}
    }
  }
}
```

Future slices can add reviewed semantic grammar classification and N4–N1
catalogs without changing the N5 response contract.
