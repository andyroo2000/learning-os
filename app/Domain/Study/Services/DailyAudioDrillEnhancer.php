<?php

namespace App\Domain\Study\Services;

use App\Domain\Study\Results\DailyAudioDrillEnhancement;
use App\Domain\Study\Results\DailyAudioDrillVariation;
use App\Domain\Study\Results\DailyAudioLearningAtom;
use App\Domain\Study\Support\DailyAudioJapaneseText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

final class DailyAudioDrillEnhancer
{
    public const BATCH_SIZE = 5;

    public const MAX_VARIATIONS_PER_ATOM = 4;

    private const MAX_PROVIDER_VARIATIONS_PER_KIND = 12;

    private const VARIATION_KINDS = ['grammar_substitution', 'form_transform'];

    public function __construct(
        private readonly OpenAiStudyCardGenerator $openAi,
    ) {}

    /**
     * @param  Collection<int, DailyAudioLearningAtom>  $atoms
     * @return array<string, DailyAudioDrillEnhancement>
     */
    public function enhance(Collection $atoms): array
    {
        $enhancements = [];

        foreach ($atoms->chunk(self::BATCH_SIZE) as $batch) {
            foreach ($this->enhanceBatch($batch->values()) as $cardId => $enhancement) {
                $enhancements[$cardId] = $enhancement;
            }
        }

        return $enhancements;
    }

    /**
     * @param  Collection<int, DailyAudioLearningAtom>  $atoms
     * @return array<string, DailyAudioDrillEnhancement>
     */
    private function enhanceBatch(Collection $atoms): array
    {
        if ($atoms->isEmpty()) {
            return [];
        }

        $parsed = $this->providerResponse($atoms);

        if ($parsed === null) {
            return [];
        }

        $expectedCardIds = array_fill_keys(
            $atoms->map(fn (DailyAudioLearningAtom $atom): string => $atom->cardId)->all(),
            true,
        );
        $items = is_array($parsed['items'] ?? null)
            ? array_slice($parsed['items'], 0, self::BATCH_SIZE * 2)
            : [];
        $enhancements = [];

        foreach ($items as $item) {
            $enhancement = $this->enhancement($item, $expectedCardIds);

            if ($enhancement !== null) {
                $enhancements[$enhancement['card_id']] = $enhancement['value'];
            }
        }

        return $enhancements;
    }

    /**
     * @param  Collection<int, DailyAudioLearningAtom>  $atoms
     * @return array<string, mixed>|null
     */
    private function providerResponse(Collection $atoms): ?array
    {
        try {
            $raw = $this->openAi->generateJson(
                systemInstruction: 'Return valid JSON for audio drill examples. English fields must contain English only.',
                prompt: $this->prompt($atoms),
                model: (string) config('services.openai.daily_audio_model'),
                reasoningEffort: (string) config('services.openai.daily_audio_reasoning_effort'),
            );

            return $this->parseJsonObject($raw);
        } catch (JsonException|RuntimeException $exception) {
            Log::warning(
                'Daily Audio drill enhancement batch failed; deterministic fallbacks will be used.',
                ['card_count' => $atoms->count(), 'exception' => $exception::class],
            );

            return null;
        }
    }

    /**
     * @param  array<string, true>  $expectedCardIds
     * @return array{card_id: string, value: DailyAudioDrillEnhancement}|null
     */
    private function enhancement(mixed $item, array $expectedCardIds): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $cardId = $this->stringField($item, 'cardId');

        if ($cardId === null || ! isset($expectedCardIds[$cardId])) {
            return null;
        }

        $englishCue = DailyAudioJapaneseText::safeEnglish($this->stringField($item, 'englishCue'));
        $example = $this->example($item, $englishCue);
        $variations = [
            ...$this->variations($item['grammarSubstitutions'] ?? null, 'grammar_substitution', $englishCue),
            ...$this->variations($item['formTransforms'] ?? null, 'form_transform', $englishCue),
        ];

        return [
            'card_id' => $cardId,
            'value' => new DailyAudioDrillEnhancement(
                englishCue: $englishCue,
                exampleJp: $example['japanese'] ?? null,
                exampleReading: $example['reading'] ?? null,
                exampleEn: $example['english'] ?? null,
                variations: $this->balancedVariations($variations),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{japanese: string, reading: string|null, english: string}|null
     */
    private function example(array $item, ?string $englishCue): ?array
    {
        $anchor = is_array($item['anchor'] ?? null) ? $item['anchor'] : null;
        $japanese = DailyAudioJapaneseText::normalizeDisplay(
            $anchor !== null
                ? $this->stringField($anchor, 'japanese', 'text', 'exampleJp')
                : $this->stringField($item, 'exampleJp'),
            $anchor !== null
                ? $this->stringField($anchor, 'reading', 'exampleReading')
                : $this->stringField($item, 'exampleReading'),
            requireReadingForKanji: true,
        );
        $english = DailyAudioJapaneseText::safeGeneratedTranslation(
            $anchor !== null
                ? $this->stringField($anchor, 'english', 'translation', 'exampleEn')
                : $this->stringField($item, 'exampleEn'),
            $japanese['text'] ?? null,
            $englishCue,
        );

        if ($japanese === null || $english === null) {
            return null;
        }

        return [
            'japanese' => $japanese['text'],
            'reading' => $japanese['reading'] ?? null,
            'english' => $english,
        ];
    }

    /**
     * @return list<DailyAudioDrillVariation>
     */
    private function variations(mixed $values, string $kind, ?string $englishCue): array
    {
        if (! is_array($values) || ! in_array($kind, self::VARIATION_KINDS, true)) {
            return [];
        }

        $variations = [];

        foreach (array_slice($values, 0, self::MAX_PROVIDER_VARIATIONS_PER_KIND) as $value) {
            $variation = $this->parseVariation($value, $kind, $englishCue);

            if ($variation !== null) {
                $variations[] = $variation;
            }
        }

        return $variations;
    }

    private function parseVariation(mixed $value, string $kind, ?string $englishCue): ?DailyAudioDrillVariation
    {
        if (! is_array($value)) {
            return null;
        }

        $japanese = DailyAudioJapaneseText::normalizeDisplay(
            $this->stringField($value, 'japanese', 'text', 'exampleJp'),
            $this->stringField($value, 'reading', 'exampleReading'),
            requireReadingForKanji: true,
        );
        $english = DailyAudioJapaneseText::safeGeneratedTranslation(
            $this->stringField($value, 'english', 'translation', 'exampleEn'),
            $japanese['text'] ?? null,
            $englishCue,
        );

        if ($japanese === null || $english === null) {
            return null;
        }

        return new DailyAudioDrillVariation($kind, $japanese['text'], $japanese['reading'] ?? null, $english);
    }

    /**
     * @param  list<DailyAudioDrillVariation>  $variations
     * @return list<DailyAudioDrillVariation>
     */
    private function balancedVariations(array $variations): array
    {
        $selected = [
            ...$this->variationsOfKind($variations, 'grammar_substitution'),
            ...$this->variationsOfKind($variations, 'form_transform'),
        ];
        $selectedKeys = array_fill_keys(array_map($this->variationKey(...), $selected), true);

        foreach ($variations as $variation) {
            if (count($selected) >= self::MAX_VARIATIONS_PER_ATOM) {
                break;
            }

            $key = $this->variationKey($variation);

            if (isset($selectedKeys[$key])) {
                continue;
            }

            $selected[] = $variation;
            $selectedKeys[$key] = true;
        }

        return array_slice($selected, 0, self::MAX_VARIATIONS_PER_ATOM);
    }

    /**
     * @param  list<DailyAudioDrillVariation>  $variations
     * @return list<DailyAudioDrillVariation>
     */
    private function variationsOfKind(array $variations, string $kind): array
    {
        return array_slice(array_values(array_filter(
            $variations,
            fn (DailyAudioDrillVariation $variation): bool => $variation->kind === $kind,
        )), 0, 2);
    }

    private function variationKey(DailyAudioDrillVariation $variation): string
    {
        return "{$variation->kind}:{$variation->japanese}";
    }

    /** @param Collection<int, DailyAudioLearningAtom> $atoms */
    private function prompt(Collection $atoms): string
    {
        $cards = $atoms->map(fn (DailyAudioLearningAtom $atom): array => [
            'cardId' => mb_substr($atom->cardId, 0, 64, 'UTF-8'),
            'cardType' => mb_substr($atom->cardType, 0, 64, 'UTF-8'),
            'targetText' => $this->boundedPromptText($atom->targetText),
            'reading' => $this->boundedPromptText($atom->reading),
            'english' => $this->boundedPromptText($atom->english),
            'exampleJp' => $this->boundedPromptText($atom->exampleJp),
            'exampleEn' => $this->boundedPromptText($atom->exampleEn),
            'deckName' => $this->boundedPromptText($atom->deckName, 255),
            'noteType' => $this->boundedPromptText($atom->noteType, 255),
        ])->values()->all();
        $cardsJson = json_encode($cards, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<'PROMPT'
Create fresh N5-N4 Japanese drill examples from the learner items in Cards JSON.

Requirements:
- Use each learner item naturally in new Japanese example sentences.
- For every item, create a balanced ladder before moving to the next item:
  1. anchor is a fresh sentence that clearly connects to the flashcard without copying its source example.
  2. grammarSubstitutions contains exactly two items that keep the grammar structure but replace common words or context.
  3. formTransforms contains exactly two items that keep the core expression and change its form when linguistically appropriate.
- For non-inflecting nouns, vary natural phrase or sentence frames instead of inventing conjugations.
- Keep Japanese around JLPT N5-N4 level.
- Japanese fields contain normal Japanese only, without furigana, romaji, or inline readings.
- Put furigana only in reading fields. If Japanese contains kanji, reading is required.
- Use bracket furigana such as 物価[ぶっか], or kana-only reading text. Never use romaji or English in readings.
- English fields must contain English only.
- Translate every full Japanese example into a complete, idiomatic English sentence.
- Translate context-dependent words by their meaning in the generated sentence.
- If a definition is Japanese-only or mixed Japanese and English, translate it into a short natural English cue.
- Prefer new generated sentences over restating the card target by itself.

Return JSON only:
{
  "items": [
    {
      "cardId": "...",
      "englishCue": "short English cue",
      "anchor": {
        "japanese": "new close-anchor Japanese sentence",
        "reading": "required when japanese contains kanji",
        "english": "complete English translation"
      },
      "grammarSubstitutions": [
        {
          "japanese": "variation Japanese sentence",
          "reading": "required when japanese contains kanji",
          "english": "complete English translation"
        }
      ],
      "formTransforms": [
        {
          "japanese": "variation Japanese sentence",
          "reading": "required when japanese contains kanji",
          "english": "complete English translation"
        }
      ]
    }
  ]
}

Cards JSON:
PROMPT."\n".$cardsJson;
    }

    private function boundedPromptText(?string $text, int $limit = 1_000): ?string
    {
        return $text === null ? null : mb_substr(trim($text), 0, $limit, 'UTF-8');
    }

    /** @return array<string, mixed> */
    private function parseJsonObject(string $raw): array
    {
        $text = trim($raw);

        if (preg_match('/```(?:json)?\s*\n?([\s\S]*?)\n?```/i', $text, $matches) === 1) {
            $text = trim($matches[1]);
        }

        $parsed = json_decode($text, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($parsed) || array_is_list($parsed)) {
            throw new JsonException('Daily Audio generator returned invalid JSON.');
        }

        return $parsed;
    }

    /** @param array<string, mixed> $record */
    private function stringField(array $record, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $record[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
