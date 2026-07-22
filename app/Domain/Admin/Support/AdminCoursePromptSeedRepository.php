<?php

namespace App\Domain\Admin\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

class AdminCoursePromptSeedRepository
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $cache = [];

    /** @return list<array{word: string, reading?: string, translation: string}> */
    public function sampleVocabulary(string $language, string $level, int $count = 30): array
    {
        $words = $this->load($language, $level, 'vocabulary');
        $unique = [];
        foreach ($words as $word) {
            $text = $word['word'] ?? null;
            $translation = $word['translation'] ?? null;
            if (! is_string($text) || ! is_string($translation) || isset($unique[$text])) {
                continue;
            }

            $unique[$text] = [
                'word' => $text,
                'reading' => is_string($word['reading'] ?? null) ? $word['reading'] : '',
                'translation' => $translation,
            ];
        }

        return $this->sample(array_values($unique), $count);
    }

    /** @return list<array{pattern: string, meaning: string, example: string, exampleTranslation: string}> */
    public function sampleGrammar(string $language, string $level, int $count = 5): array
    {
        $points = [];
        foreach ($this->load($language, $level, 'grammarPoints') as $point) {
            if (! is_string($point['pattern'] ?? null)
                || ! is_string($point['meaning'] ?? null)
                || ! is_string($point['example'] ?? null)
                || ! is_string($point['exampleTranslation'] ?? null)) {
                continue;
            }

            $points[] = [
                'pattern' => $point['pattern'],
                'meaning' => $point['meaning'],
                'example' => $point['example'],
                'exampleTranslation' => $point['exampleTranslation'],
            ];
        }

        return $this->sample($points, $count);
    }

    /** @return list<array<string, mixed>> */
    private function load(string $language, string $level, string $collection): array
    {
        $file = $this->file($language, $level, $collection);
        if ($file === null) {
            return [];
        }

        $cacheKey = "{$file}:{$collection}";
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        try {
            $json = file_get_contents($file);
            if ($json === false) {
                return $this->cache[$cacheKey] = [];
            }
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $items = is_array($decoded) ? ($decoded[$collection] ?? null) : null;

            return $this->cache[$cacheKey] = is_array($items) && array_is_list($items) ? $items : [];
        } catch (Throwable $exception) {
            Log::warning('Unable to load admin course prompt seeds.', [
                'language' => $language,
                'level' => $level,
                'collection' => $collection,
                'exception' => $exception,
            ]);

            return $this->cache[$cacheKey] = [];
        }
    }

    private function file(string $language, string $level, string $collection): ?string
    {
        $levelFile = [
            'N5' => 'n5.json',
            'N4' => 'n4.json',
            'N3' => 'n3.json',
            'N2' => 'n2.json',
            'N1' => 'n1.json',
        ][$level] ?? null;
        if ($language !== 'ja' || $levelFile === null) {
            return null;
        }

        $type = $collection === 'vocabulary' ? 'vocabulary' : 'grammar';

        return resource_path("data/admin-course-prompts/{$type}/ja/{$levelFile}");
    }

    /** @template T @param list<T> $items @return list<T> */
    private function sample(array $items, int $count): array
    {
        if ($items === [] || $count <= 0) {
            return [];
        }

        shuffle($items);

        return array_slice($items, 0, min($count, count($items)));
    }
}
