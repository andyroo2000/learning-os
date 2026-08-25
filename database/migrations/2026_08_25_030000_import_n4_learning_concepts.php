<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CATALOGS = [
        'vocabulary' => [
            'file' => 'n4-vocabulary.csv',
            'sha256' => '3ef6b09a483ccc61fa3f2ed96552e7574b2cbba67dbd442226599947b5f64c73',
            'rows' => 640,
        ],
        'grammar' => [
            'file' => 'n4-grammar.csv',
            'sha256' => 'b736cde06c9d8d744d405340e904764c1d86cd385c98ca4a3e3e3a099ca540dd',
            'rows' => 89,
        ],
    ];

    public function up(): void
    {
        // Keep this migration self-contained: normalization is duplicated below so future
        // application matcher changes cannot alter an already-shipped catalog import.
        $now = now();
        $concepts = [];
        $aliases = [];

        foreach (self::CATALOGS as $kind => $catalog) {
            $path = base_path('resources/jlpt/v1/'.$catalog['file']);

            if (! is_file($path) || hash_file('sha256', $path) !== $catalog['sha256']) {
                throw new RuntimeException("JLPT catalog {$catalog['file']} is missing or has an unexpected checksum.");
            }

            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException("Unable to open JLPT catalog {$catalog['file']}.");
            }

            $header = fgetcsv($handle, escape: '');

            if (! is_array($header)) {
                throw new RuntimeException("JLPT catalog {$catalog['file']} has no header row.");
            }
            $rowCount = 0;

            while (($values = fgetcsv($handle, escape: '')) !== false) {
                if (count($header) !== count($values)) {
                    throw new RuntimeException("Malformed row in JLPT catalog {$catalog['file']}.");
                }

                $row = array_combine($header, $values);

                if (! is_array($row)) {
                    throw new RuntimeException("Malformed row in JLPT catalog {$catalog['file']}.");
                }

                $expression = $kind === 'vocabulary' ? $row['expression'] : $row['pattern'];
                $reading = $kind === 'vocabulary' ? ($row['reading'] ?: null) : null;
                $concepts[] = [
                    'id' => $row['concept_id'],
                    'language' => 'ja',
                    'kind' => $kind,
                    'jlpt_level' => 4,
                    'expression' => $expression,
                    'normalized_key' => $this->normalize($expression),
                    'reading' => $reading,
                    'normalized_reading' => $reading === null ? null : $this->normalize($reading),
                    'meaning' => $row['meaning'],
                    'source_name' => $row['source'],
                    'source_id' => $row['source_id'],
                    'review_status' => $row['review_status'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $keys = $kind === 'vocabulary'
                    ? array_filter([
                        'expression' => $this->normalize($expression),
                        'reading' => $reading === null ? null : $this->normalize($reading),
                    ])
                    : array_fill_keys($this->japaneseFragments($expression), 'surface');

                foreach ($keys as $aliasKind => $normalizedKey) {
                    if ($kind === 'grammar') {
                        [$aliasKind, $normalizedKey] = [$normalizedKey, $aliasKind];
                    }

                    $aliases[] = [
                        'concept_id' => $row['concept_id'],
                        'alias_kind' => $aliasKind,
                        'normalized_key' => $normalizedKey,
                    ];
                }

                $rowCount++;
            }

            fclose($handle);

            if ($rowCount !== $catalog['rows']) {
                throw new RuntimeException("JLPT catalog {$catalog['file']} contains {$rowCount} rows; expected {$catalog['rows']}.");
            }
        }

        DB::transaction(function () use ($concepts, $aliases): void {
            // Stay below SQLite's traditional 999-bind limit (14 columns x 50 rows).
            foreach (array_chunk($concepts, 50) as $chunk) {
                DB::table('learning_concepts')->insert($chunk);
            }

            foreach (array_chunk($aliases, 200) as $chunk) {
                DB::table('learning_concept_aliases')->insert($chunk);
            }
        });
    }

    public function down(): void
    {
        DB::table('learning_concepts')
            ->where('language', 'ja')
            ->where('jlpt_level', 4)
            ->delete();
    }

    private function normalize(string $value): string
    {
        $value = trim($value);

        if (function_exists('mb_convert_kana')) {
            $value = mb_convert_kana($value, 'asKV', 'UTF-8');
        }

        $value = mb_strtolower($value, 'UTF-8');

        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $value) ?? '';
    }

    /** @return list<string> */
    private function japaneseFragments(string $value): array
    {
        preg_match_all('/[ぁ-んァ-ヶー一-龠々〆ヵヶ]+/u', $value, $matches);

        return array_values(array_unique(array_filter(
            array_map(fn (string $fragment): string => $this->normalize($fragment), $matches[0] ?? []),
            fn (string $fragment): bool => mb_strlen($fragment, 'UTF-8') >= 2,
        )));
    }
};
