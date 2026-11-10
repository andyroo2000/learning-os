# Data attribution

## N5 vocabulary

`n5-vocabulary.csv` is derived from Jonathan Waller's community JLPT vocabulary
list, distributed by `stephenmk/yomitan-jlpt-vocab` under CC BY 4.0.

- Repository: https://github.com/stephenmk/yomitan-jlpt-vocab
- Pinned revision: `b062d4e38c4bdd0950ae1d4ec55f04b176182e03`
- Source file: `original_data/n5.csv`

## N4 vocabulary

`n4-vocabulary.csv` is derived from the same Jonathan Waller community list,
at the same pinned CC BY 4.0 revision.

- Repository: https://github.com/stephenmk/yomitan-jlpt-vocab
- Pinned revision: `b062d4e38c4bdd0950ae1d4ec55f04b176182e03`
- Source file: `original_data/n4.csv`

## N5 grammar

`n5-grammar.csv` is derived from the Japanese Language Data grammar dataset and
retains its CC BY-SA 4.0 terms.

- Repository: https://github.com/jkindrix/japanese-language-data
- Pinned revision: `04014e06019fc9d4af76e6dbb64ec709fe863c4d`
- Source file: `data/grammar/grammar.json`
- Upstream review status: all imported N5 rows are currently marked `draft`

## N4 grammar

`n4-grammar.csv` is derived from the same Japanese Language Data dataset and
retains its CC BY-SA 4.0 terms.

- Repository: https://github.com/jkindrix/japanese-language-data
- Pinned revision: `04014e06019fc9d4af76e6dbb64ec709fe863c4d`
- Source file: `data/grammar/grammar.json`
- Upstream review status: all imported N4 rows are currently marked `draft`

The share-alike terms apply to the grammar data and direct derivatives, not to
unrelated application source code that reads the data. Preserve this attribution
if the catalog is redistributed.

## Photographed study-book indexes

The book-derived candidate files contain only short vocabulary expressions and
grammar-pattern identifiers extracted for concept classification. They do not
include the book's explanations, translations, exercises, or example sentences.
The source is recorded by local image filename because the title and edition are
not visible in the supplied photos.

## N3, N2, and N1 vocabulary

The corresponding `n3-vocabulary.csv`, `n2-vocabulary.csv`, and
`n1-vocabulary.csv` files derive from Jonathan Waller's community list at the
same pinned revision and with the same CC BY terms as N5/N4 above.

- Source files: `original_data/n3.csv`, `original_data/n2.csv`, `original_data/n1.csv`
- Expression uses `kanji` when present, otherwise `kana`; reading uses `kana`.
- Meanings and JMdict source identifiers are preserved verbatim.
- Stable IDs use the level, JMdict identifier, and the first eight hexadecimal
  characters of SHA-256 over `expression + TAB + reading` (UTF-8).
- Four N1 rows repeat the same source identifier, expression, and reading at an
  earlier level: いいえ and それでは (N5), しみじみ and 三日月 (N2).
  These are omitted from N1 so coverage does not count the same item twice.
- Imported row counts: N3 1,730; N2 1,812; N1 3,423 (3,427 upstream).

## N3, N2, and N1 grammar

The corresponding grammar CSVs derive from `data/grammar/grammar.json` in
Japanese Language Data at revision `04014e06019fc9d4af76e6dbb64ec709fe863c4d`,
under CC BY-SA 4.0, as above. Each level is filtered using the source `level`;
`pattern`, `meaning_en`, `id`, and `review_status` are preserved. Concept IDs
prefix the source ID with `n3-grammar-`, `n2-grammar-`, or `n1-grammar-`.

All imported rows are marked `draft` upstream: N3 130, N2 149, N1 150.
These are community estimates, not official exam specifications or reviewed
grammar classifications. Share-alike terms apply to these data derivatives.
