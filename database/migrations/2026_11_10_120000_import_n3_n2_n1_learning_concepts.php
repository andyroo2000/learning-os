<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CATALOGS = [
        ['file' => 'n3-vocabulary.csv', 'kind' => 'vocabulary', 'level' => 3, 'sha256' => '5a4d967b9051fc914da4404694bc04cb786ab3cfc1919a8fcbdd308dd9d583be', 'rows' => 1730],
        ['file' => 'n3-grammar.csv', 'kind' => 'grammar', 'level' => 3, 'sha256' => '53bbe15a89992512cd25e8247920fa3dca1add0ac742baf9bc5b8cb949b99c66', 'rows' => 130],
        ['file' => 'n2-vocabulary.csv', 'kind' => 'vocabulary', 'level' => 2, 'sha256' => '64b1e947cab90fe13d0be8bc9062ed689995b2a29d150324b5b5714716260c83', 'rows' => 1812],
        ['file' => 'n2-grammar.csv', 'kind' => 'grammar', 'level' => 2, 'sha256' => '015f631a8ae5ee407bac66617aea51fc5cf33f25d7185b9ac4245b6d5f85dc22', 'rows' => 149],
        ['file' => 'n1-vocabulary.csv', 'kind' => 'vocabulary', 'level' => 1, 'sha256' => '899a790e196d3829a374b74f85e1482ee7950b883143eaf53e29f5fd5256c66e', 'rows' => 3423],
        ['file' => 'n1-grammar.csv', 'kind' => 'grammar', 'level' => 1, 'sha256' => '3732ed6862309dd3e50d64c53188521ab718f57c8f131606dfae5c74460d70bd', 'rows' => 150],
    ];

    public function up(): void
    {
        // Validate every immutable file before writing any catalog rows.
        $catalogs = array_map(fn (array $catalog): array => [$catalog, $this->rows($catalog)], self::CATALOGS);
        DB::transaction(function () use ($catalogs): void {
            foreach ($catalogs as [$catalog, $rows]) {
                $this->importCatalog($catalog, $rows);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (self::CATALOGS as $catalog) {
                foreach (array_chunk(array_column($this->rows($catalog), 'concept_id'), 200) as $ids) {
                    DB::table('learning_concepts')->whereIn('id', $ids)->delete();
                }
            }
        });
    }

    private function rows(array $catalog): array
    {
        $handle = $this->openCatalog($catalog);
        try {
            $header = fgetcsv($handle, escape: '');
            $rows = [];
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                $rows[] = array_combine($header, $values);
            }
        } finally {
            fclose($handle);
        }
        if (count($rows) !== $catalog['rows']) {
            throw new RuntimeException('Invalid JLPT catalog row count: '.$catalog['file']);
        }

        return $rows;
    }

    private function openCatalog(array $catalog): mixed
    {
        $path = base_path('resources/jlpt/v1/'.$catalog['file']);
        if (! is_file($path) || hash_file('sha256', $path) !== $catalog['sha256']) {
            throw new RuntimeException('Invalid JLPT catalog checksum: '.$catalog['file']);
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to read JLPT catalog: '.$catalog['file']);
        }

        return $handle;
    }

    private function importCatalog(array $catalog, array $rows): void
    {
        $now = now();
        $concepts = [];
        $aliases = [];
        foreach ($rows as $row) {
            $concept = $this->concept($catalog, $row, $now);
            $concepts[] = $concept;
            // Grammar matching uses curated rules, never arbitrary catalog fragments.
            if ($catalog['kind'] === 'vocabulary') {
                $aliases[] = ['concept_id' => $concept['id'], 'alias_kind' => 'expression', 'normalized_key' => $concept['normalized_key']];
                $aliases[] = ['concept_id' => $concept['id'], 'alias_kind' => 'reading', 'normalized_key' => $concept['normalized_reading']];
            }
        }
        foreach (array_chunk($concepts, 50) as $chunk) {
            DB::table('learning_concepts')->insert($chunk);
        }
        foreach (array_chunk($aliases, 200) as $chunk) {
            DB::table('learning_concept_aliases')->insert($chunk);
        }
    }

    private function concept(array $catalog, array $row, mixed $now): array
    {
        $expression = $row['expression'] ?? $row['pattern'];
        $reading = $row['reading'] ?? null;

        return [
            'id' => $row['concept_id'],
            'language' => 'ja',
            'kind' => $catalog['kind'],
            'jlpt_level' => $catalog['level'],
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
    }

    private function normalize(string $value): string
    {
        // Frozen with this migration; application normalization may evolve later.
        $value = trim($value);
        if (function_exists('mb_convert_kana')) {
            $value = mb_convert_kana($value, 'asKV', 'UTF-8');
        }
        $value = mb_strtolower($value, 'UTF-8');

        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $value) ?? '';
    }
};
