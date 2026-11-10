# JLPT mastery concept mapping

The mastery estimate is intentionally approximate and covers N5 through N1. Vocabulary and grammar remain separate metrics; there is no combined score.

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

- The immutable `resources/jlpt/v1` catalog has 684 N5 vocabulary concepts, 77
  N5 grammar concepts, 640 N4 vocabulary concepts, and 89 N4 grammar concepts.
  N4 is a level-specific bucket rather than a cumulative N5+N4 denominator.
  Checksums and row counts are enforced by the data migrations.
- Attribution and the data licenses are preserved beside the catalog.
- Vocabulary uses exact normalized expression or reading matches. Keys shared by
  homographs or homophones are considered ambiguous and do not match by themselves.
- Grammar uses surface fragments of at least two Japanese characters. This is
  deliberately conservative and will undercount patterns that are expressed only
  by a one-character particle or by a conjugation absent from the catalog pattern.
  Surface fragments shared by more than one catalog concept (such as `です`) are
  considered ambiguous and do not produce automatic matches.
- N4 grammar matching currently recognizes a curated set of high-signal surface
  constructions. A surface must align to complete adjacent tokenizer tokens; an
  incidental substring inside an ordinary word does not count. Form-only rules
  such as potential, passive, causative, and some
  conditionals remain in the denominator but are intentionally not auto-matched
  until their morphology can be distinguished reliably. More-specific constructions
  suppress their nested general rule (for example, `なくてもいい` does not also
  credit the ordinary `てもいい` permission rule). Ambiguous fragments such as
  `なら` and casual quotation `って` are likewise left unmatched because they also
  occur inside unrelated forms and ordinary te-form words.
- Both manual cards and cards committed from drafts converge on `CreateCardAction`.
  New-card matching runs as a best-effort post-commit analytic so a matching
  failure cannot block card creation. Content edits refresh automatic links;
  manual links are preserved. Idempotent create retries do not add duplicate links.
- Existing active cards can be processed with
  `php artisan learning-concepts:backfill`. Use `--after=<card ULID>` to resume,
  `--chunk=<1-2000>` to bound batches, or `--dry-run` to measure without writing.

## Mastery calculation

Each concept is known when an active linked card is in Review with FSRS stability
of at least seven days (Guru+), or its vocabulary has ever been passed on
WaniKani. The percentage is the known count divided by the level/type catalog
denominator. Multiple cards and overlapping WaniKani matches count once per
concept. `matched` (and legacy `covered`) counts concepts linked to an active
card, independently of whether that card is known. Deck/course scopes exclude
user-wide WaniKani knowledge.

The compatibility API shape is:

```json
{
  "jlptMastery": {
    "N5": {
      "vocabulary": {"masteryPercent": 34, "covered": 280, "total": 684},
      "grammar": {"masteryPercent": 21, "covered": 29, "total": 77}
    },
    "N4": {
      "vocabulary": {"masteryPercent": 18, "covered": 120, "total": 640},
      "grammar": {"masteryPercent": 9, "covered": 12, "total": 89}
    }
  }
}
```

Future slices can expand reviewed semantic grammar classification without
changing the existing level response contracts.

## N3–N1 reference catalogs

Six additional versioned CSVs now use the same vocabulary/grammar schemas:

| Level | Vocabulary | Grammar |
| --- | ---: | ---: |
| N3 | 1,730 | 130 |
| N2 | 1,812 | 149 |
| N1 | 3,423 | 150 |

These are level-specific buckets, not cumulative totals. Provenance, pinned
revisions, transformations, and four omitted N1 duplicates are recorded in
`resources/jlpt/v1/ATTRIBUTION.md`. The catalog test pins checksums and counts.
N5/N4 catalog bytes remain unchanged.

The N3–N1 migration verifies all six checksums before importing concepts and
vocabulary aliases in a transaction. Rollback deletes only the imported IDs.
Aggregation and vocabulary matching include all five levels; the web display
shows the levels returned by the API in N5 → N1 order and still accepts older
responses containing only N5/N4.

`AdvancedGrammarRuleMatcher` starts with ten distinctive fixed constructions per
new level. Matches require complete adjacent tokenizer tokens and cannot cross
punctuation. The remaining patterns stay in the denominator, so grammar coverage
is conservative and may substantially undercount knowledge at higher levels.
The UI explicitly describes this limitation. Full morphology and semantic
classification are not part of these estimates.

### Rollout

1. Deploy the backend and run the normal database migrations.
2. Run `php artisan learning-concepts:backfill --dry-run` to verify tokenization,
   then `php artisan learning-concepts:backfill` to refresh existing active cards.
   The command is resumable and idempotent; manual links are preserved.
3. WaniKani's matcher version is bumped to `wanikani-exact-v3`, making stored
   subjects eligible for rematching on the next sync.
4. Deploy the web client. It also works before the backend rollout; absent levels
   are omitted rather than shown as zero knowledge.

Catalog import does not run learner backfills inside the database migration.
