<?php

namespace App\Domain\Admin\Services;

use App\Domain\Admin\Models\AdminPronunciationDictionary;

final class AdminJapanesePronunciationOverrides
{
    private const MAX_TEXT_LENGTH = 10_000;

    /** @var array<string, list<string>|array<string, string>> */
    private array $cache = [];

    public function apply(string $text, ?string $reading = null, ?string $furigana = null): string
    {
        if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT_LENGTH) {
            return $text;
        }

        $reading = is_string($reading) ? trim($reading) : null;
        $furigana = is_string($furigana) ? trim($furigana) : null;
        $cache = $this->dictionaryCache();
        $bracketOverride = $this->bracketOverride($reading, $furigana, $cache);
        if ($bracketOverride !== null) {
            return $bracketOverride;
        }

        $normalizedText = $this->normalizeMatchText($text);
        /** @var list<string> $keepKanji */
        $keepKanji = $cache['keep'];

        $directOverride = $this->directTextOverride($text, $normalizedText, $cache);
        if ($directOverride !== null) {
            return $directOverride;
        }

        if ($this->containsKeptWord($normalizedText, $keepKanji)) {
            return $this->applyForceKanaToText($text, $cache);
        }

        $normalizedReading = $this->usableReading($reading);
        if ($normalizedReading !== null) {
            return $normalizedReading;
        }

        return $this->applyForceKanaToText($text, $cache);
    }

    /** @param array{keep: list<string>, force: array<string, string>, keepSorted: list<string>, forceSorted: array<string, string>} $cache */
    private function bracketOverride(?string $reading, ?string $furigana, array $cache): ?string
    {
        $source = $this->bracketSource($reading, $furigana);
        if ($source === null) {
            return null;
        }

        $overridden = trim($this->applyToUnits($this->parseFuriganaUnits($source), $cache));

        return $overridden === '' ? null : $overridden;
    }

    private function bracketSource(?string $reading, ?string $furigana): ?string
    {
        if (str_contains((string) $reading, '[')) {
            return $reading;
        }
        if (str_contains((string) $furigana, '[')) {
            return $furigana;
        }

        return null;
    }

    /**
     * @param  array{keep: list<string>, force: array<string, string>, keepSorted: list<string>, forceSorted: array<string, string>}  $cache
     */
    private function directTextOverride(
        string $text,
        string $normalizedText,
        array $cache,
    ): ?string {
        if ($normalizedText === '') {
            return null;
        }
        if (in_array($normalizedText, $cache['keep'], true)) {
            return $this->applyForceKanaToText($text, $cache);
        }

        return $cache['force'][$normalizedText] ?? null;
    }

    /** @param  list<string>  $keepKanji */
    private function containsKeptWord(string $normalizedText, array $keepKanji): bool
    {
        foreach ($keepKanji as $word) {
            if ($word !== '' && str_contains($normalizedText, $word)) {
                return true;
            }
        }

        return false;
    }

    private function usableReading(?string $reading): ?string
    {
        if ($reading === null || $reading === '') {
            return null;
        }

        $normalized = $this->normalizeJapaneseReading($reading);

        return trim($normalized) === '' ? null : $normalized;
    }

    /** @return array{keep: list<string>, force: array<string, string>, keepSorted: list<string>, forceSorted: array<string, string>} */
    private function dictionaryCache(): array
    {
        if ($this->cache !== []) {
            /** @var array{keep: list<string>, force: array<string, string>, keepSorted: list<string>, forceSorted: array<string, string>} */
            return $this->cache;
        }

        $dictionary = AdminPronunciationDictionary::query()->findOrFail('ja');
        $keep = array_values(array_filter(
            array_map(fn (mixed $word): string => is_string($word) ? $this->normalizeMatchText($word) : '', $dictionary->keep_kanji),
        ));
        $force = [];
        foreach ($dictionary->force_kana as $word => $kana) {
            $entry = $this->forceEntry($word, $kana);
            if ($entry !== null) {
                $force[$entry['word']] = $entry['kana'];
            }
        }
        foreach ($this->derivedVerbEntries($dictionary->verb_kana) as $word => $kana) {
            $force[$word] ??= $kana;
        }

        $keepSorted = $keep;
        usort($keepSorted, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        $forceSorted = $force;
        uksort($forceSorted, function (string $left, string $right): int {
            return (mb_strlen($right) <=> mb_strlen($left)) ?: strcmp($left, $right);
        });

        $this->cache = compact('keep', 'force', 'keepSorted', 'forceSorted');

        return $this->dictionaryCache();
    }

    /** @return array{word: string, kana: string}|null */
    private function forceEntry(mixed $word, mixed $kana): ?array
    {
        if (! is_string($word) || ! is_string($kana)) {
            return null;
        }

        $word = $this->normalizeMatchText($word);
        $kana = trim($kana);
        if ($word === '' || $kana === '') {
            return null;
        }

        return compact('word', 'kana');
    }

    /** @param array<string, string> $verbs
     * @return array<string, string>
     */
    private function derivedVerbEntries(array $verbs): array
    {
        $rows = [
            'う' => ['わ', 'い', 'う', 'え', 'お'], 'く' => ['か', 'き', 'く', 'け', 'こ'],
            'ぐ' => ['が', 'ぎ', 'ぐ', 'げ', 'ご'], 'す' => ['さ', 'し', 'す', 'せ', 'そ'],
            'つ' => ['た', 'ち', 'つ', 'て', 'と'], 'ぬ' => ['な', 'に', 'ぬ', 'ね', 'の'],
            'ぶ' => ['ば', 'び', 'ぶ', 'べ', 'ぼ'], 'む' => ['ま', 'み', 'む', 'め', 'も'],
            'る' => ['ら', 'り', 'る', 'れ', 'ろ'],
        ];
        $derived = [];
        foreach ($verbs as $word => $kana) {
            if (! is_string($word) || ! is_string($kana)) {
                continue;
            }
            $wordEnding = mb_substr($word, -1);
            $kanaEnding = mb_substr($kana, -1);
            if ($wordEnding !== $kanaEnding || ! isset($rows[$wordEnding])) {
                continue;
            }
            foreach ($rows[$wordEnding] as $ending) {
                $derived[mb_substr($word, 0, -1).$ending] = mb_substr($kana, 0, -1).$ending;
            }
        }

        return $derived;
    }

    /** @param list<array{surface: string, reading: string}> $units
     * @param  array{keep: list<string>, force: array<string, string>, keepSorted: list<string>, forceSorted: array<string, string>}  $cache
     */
    private function applyToUnits(array $units, array $cache): string
    {
        $output = [];
        for ($index = 0; $index < count($units); $index++) {
            $index = $this->applyUnit($units, $index, $cache, $output);
        }

        return implode('', $output);
    }

    /**
     * @param  list<array{surface: string, reading: string}>  $units
     * @param  array{keep: list<string>, force: array<string, string>, keepSorted: list<string>, forceSorted: array<string, string>}  $cache
     * @param  list<string>  $output
     */
    private function applyUnit(array $units, int $index, array $cache, array &$output): int
    {
        $keepMatch = $this->findMatch($units, $index, $cache['keepSorted']);
        if ($keepMatch !== null) {
            $output[] = $keepMatch['surface'];

            return $keepMatch['end'];
        }

        $forceMatch = $this->findMatch($units, $index, array_keys($cache['forceSorted']));
        if ($forceMatch !== null) {
            $output[] = $cache['forceSorted'][$forceMatch['word']];
            if ($forceMatch['trailing'] !== null) {
                $output[] = $this->normalizeParticle($forceMatch['trailing'], $forceMatch['trailing']);
            }

            return $forceMatch['end'];
        }

        $reading = $this->normalizeNumericYear(
            $units[$index]['surface'],
            $this->normalizeParticle($units[$index]['surface'], $units[$index]['reading']),
        );
        $this->pushReadingWithOverlapCollapse($output, $units[$index]['surface'], $reading);

        return $index;
    }

    /** @param list<array{surface: string, reading: string}> $units
     * @param  list<string>  $words
     * @return array{word: string, end: int, surface: string, trailing: ?string}|null
     */
    private function findMatch(array $units, int $start, array $words): ?array
    {
        foreach ($words as $word) {
            $match = $this->matchWord($units, $start, $word);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param  list<array{surface: string, reading: string}>  $units
     * @return array{word: string, end: int, surface: string, trailing: ?string}|null
     */
    private function matchWord(array $units, int $start, string $word): ?array
    {
        $remaining = $word;
        $surface = '';
        for ($end = $start; $end < count($units); $end++) {
            $unitSurface = $units[$end]['surface'];
            if (str_starts_with($remaining, $unitSurface)) {
                $surface .= $unitSurface;
                $remaining = mb_substr($remaining, mb_strlen($unitSurface));
                if ($remaining === '') {
                    return compact('word', 'end', 'surface') + ['trailing' => null];
                }

                continue;
            }
            if (str_starts_with($unitSurface, $remaining) && $units[$end]['reading'] === $unitSurface) {
                $surface .= $remaining;

                return compact('word', 'end', 'surface') + [
                    'trailing' => mb_substr($unitSurface, mb_strlen($remaining)),
                ];
            }
            break;
        }

        return null;
    }

    /** @return list<array{surface: string, reading: string}> */
    private function parseFuriganaUnits(string $value): array
    {
        $units = [];
        $buffer = '';
        $characters = $this->characters($value);
        for ($index = 0; $index < count($characters); $index++) {
            if ($characters[$index] !== '[') {
                $buffer .= $characters[$index];

                continue;
            }
            $reading = '';
            while (++$index < count($characters) && $characters[$index] !== ']') {
                $reading .= $characters[$index];
            }
            if ($buffer !== '') {
                array_push($units, ...$this->splitSurfaceForReading($buffer, $reading));
                $buffer = '';
            }
        }
        if ($buffer !== '') {
            $units[] = ['surface' => $buffer, 'reading' => $buffer];
        }

        return $units;
    }

    /** @return list<array{surface: string, reading: string}> */
    private function splitSurfaceForReading(string $surface, string $reading): array
    {
        $characters = $this->characters($surface);
        $start = $this->annotatedSurfaceStart($characters);
        if ($start === count($characters)) {
            return [['surface' => $surface, 'reading' => preg_match('/[0-9０-９]/u', $surface) ? $reading : $surface]];
        }
        $prefix = implode('', array_slice($characters, 0, $start));
        $annotated = implode('', array_slice($characters, $start));

        return array_values(array_filter([
            $prefix === '' ? null : ['surface' => $prefix, 'reading' => $prefix],
            ['surface' => $annotated, 'reading' => $reading],
        ]));
    }

    /** @param list<string> $characters */
    private function annotatedSurfaceStart(array $characters): int
    {
        $numericSuffixStart = $this->numericSuffixStart($characters);
        if ($numericSuffixStart !== null) {
            return $numericSuffixStart;
        }

        $lastKanji = $this->lastKanjiStart($characters);
        if ($lastKanji === null) {
            return count($characters);
        }

        $digitStart = $this->precedingDigitStart($characters, $lastKanji);
        if ($digitStart === $lastKanji) {
            return $lastKanji;
        }
        if ($digitStart === 0 || preg_match('/[A-Za-z]/', $characters[$digitStart - 1]) !== 1) {
            return $digitStart;
        }

        return $lastKanji;
    }

    /** @param  list<string>  $characters */
    private function numericSuffixStart(array $characters): ?int
    {
        if ($characters === []) {
            return null;
        }
        if (! preg_match('/[0-9０-９]/u', end($characters))) {
            return null;
        }

        $start = count($characters) - 1;
        while ($start > 0 && preg_match('/[0-9０-９]/u', $characters[$start - 1])) {
            $start--;
        }
        if ($start > 0 && preg_match('/[A-Za-z]/', $characters[$start - 1]) === 1) {
            return null;
        }

        return $start;
    }

    /** @param  list<string>  $characters */
    private function lastKanjiStart(array $characters): ?int
    {
        for ($index = count($characters) - 1; $index >= 0; $index--) {
            if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $characters[$index])) {
                while ($index > 0 && preg_match('/[\x{4E00}-\x{9FFF}]/u', $characters[$index - 1])) {
                    $index--;
                }

                return $index;
            }
        }

        return null;
    }

    /** @param  list<string>  $characters */
    private function precedingDigitStart(array $characters, int $lastKanji): int
    {
        $start = $lastKanji;
        while ($start > 0 && preg_match('/[0-9０-９]/u', $characters[$start - 1])) {
            $start--;
        }

        return $start;
    }

    private function normalizeJapaneseReading(string $reading): string
    {
        if (! str_contains($reading, '[')) {
            return trim($reading);
        }

        return implode('', array_map(
            fn (array $unit): string => $unit['reading'],
            $this->parseFuriganaUnits(trim($reading)),
        ));
    }

    /** @param array{keep: list<string>, force: array<string, string>, keepSorted: list<string>, forceSorted: array<string, string>} $cache */
    private function applyForceKanaToText(string $text, array $cache): string
    {
        foreach ($cache['forceSorted'] as $word => $kana) {
            if (! in_array($word, $cache['keep'], true)) {
                $text = str_replace($word, $kana, $text);
            }
        }

        return $text;
    }

    /** @param list<string> $output */
    private function pushReadingWithOverlapCollapse(array &$output, string $surface, string $reading): void
    {
        $kanjiCount = preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $surface);
        if ($kanjiCount === 0) {
            $output[] = $reading;

            return;
        }
        for ($length = min(mb_strlen($reading), count($output)); $length >= 1; $length--) {
            $suffix = implode('', array_slice($output, -$length));
            if (str_starts_with($reading, $suffix) && mb_strlen($reading) - $length >= $kanjiCount) {
                array_splice($output, -$length);
                break;
            }
        }
        $output[] = $reading;
    }

    private function normalizeParticle(string $surface, string $reading): string
    {
        if (preg_match('/^は([、。！？!?]|$)/u', $surface)) {
            return preg_replace('/^は/u', 'わ', $reading) ?? $reading;
        }
        if (preg_match('/^へ([、。！？!?]|$)/u', $surface)) {
            return preg_replace('/^へ/u', 'え', $reading) ?? $reading;
        }

        return $reading;
    }

    private function normalizeNumericYear(string $surface, string $reading): string
    {
        if (preg_match('/^([0-9０-９]{1,4})年$/u', $surface, $matches) !== 1
            || preg_match('/^(?:ねん|年)$/u', $reading) !== 1) {
            return $reading;
        }
        $number = (int) strtr($matches[1], ['０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4', '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9']);
        $value = $this->numberBelowTenThousand($number);

        return $value === null ? $reading : $value.'年';
    }

    private function numberBelowTenThousand(int $value): ?string
    {
        if ($value < 0 || $value > 9999) {
            return null;
        }
        if ($value === 0) {
            return 'ぜろ';
        }
        $ones = ['', 'いち', 'に', 'さん', 'よん', 'ご', 'ろく', 'なな', 'はち', 'きゅう'];
        $thousands = ['', 'せん', 'にせん', 'さんぜん', 'よんせん', 'ごせん', 'ろくせん', 'ななせん', 'はっせん', 'きゅうせん'];
        $hundreds = ['', 'ひゃく', 'にひゃく', 'さんびゃく', 'よんひゃく', 'ごひゃく', 'ろっぴゃく', 'ななひゃく', 'はっぴゃく', 'きゅうひゃく'];
        $tens = ['', 'じゅう', 'にじゅう', 'さんじゅう', 'よんじゅう', 'ごじゅう', 'ろくじゅう', 'ななじゅう', 'はちじゅう', 'きゅうじゅう'];

        return $thousands[intdiv($value, 1000)]
            .$hundreds[intdiv($value % 1000, 100)]
            .$tens[intdiv($value % 100, 10)]
            .$ones[$value % 10];
    }

    private function normalizeMatchText(string $value): string
    {
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        $value = preg_replace('/^[「『（(【［["\'“”]+/u', '', $value) ?? $value;

        return preg_replace('/[」』）)】］\]"\'“”]+$/u', '', $value) ?? $value;
    }

    /** @return list<string> */
    private function characters(string $value): array
    {
        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
