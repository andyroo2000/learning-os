<?php

namespace App\Domain\Study\Services;

use App\Domain\Study\Results\DailyAudioDrillEnhancement;
use App\Domain\Study\Results\DailyAudioDrillGenerationResult;
use App\Domain\Study\Results\DailyAudioDrillVariation;
use App\Domain\Study\Results\DailyAudioLearningAtom;
use App\Domain\Study\Results\DailyAudioScriptUnit;
use App\Domain\Study\Support\DailyAudioJapaneseText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class DailyAudioDrillScriptGenerator
{
    public const MAX_SCRIPT_ATOMS = 50;

    public const ENHANCEMENT_BATCH_SIZE = 5;

    private const MAX_VARIATIONS_PER_ATOM = 4;

    private const MAX_PROVIDER_VARIATIONS_PER_KIND = 12;

    private const VARIATION_KINDS = [
        'grammar_substitution',
        'form_transform',
    ];

    public function __construct(
        private readonly OpenAiStudyCardGenerator $openAi,
    ) {}

    /**
     * @param  iterable<int, DailyAudioLearningAtom>  $atoms
     */
    public function generate(
        iterable $atoms,
        string $l1VoiceId,
        string $l2VoiceId,
    ): DailyAudioDrillGenerationResult {
        $atoms = collect($atoms)
            ->take(self::MAX_SCRIPT_ATOMS)
            ->values();
        $l1VoiceId = trim($l1VoiceId);
        $l2VoiceId = trim($l2VoiceId);

        if ($atoms->isEmpty()) {
            throw new InvalidArgumentException(
                'Daily Audio Practice needs at least one eligible study card.',
            );
        }
        if ($l1VoiceId === '' || $l2VoiceId === '') {
            throw new InvalidArgumentException(
                'Daily Audio Practice requires narrator and speaker voice IDs.',
            );
        }
        if ($atoms->contains(
            fn (mixed $atom): bool => ! $atom instanceof DailyAudioLearningAtom,
        )) {
            throw new InvalidArgumentException(
                'Daily Audio Practice script atoms must be learning atoms.',
            );
        }

        $enhancements = $this->enhancements($atoms);

        return $this->buildScript($atoms, $enhancements, $l1VoiceId, $l2VoiceId);
    }

    /**
     * @param  Collection<int, DailyAudioLearningAtom>  $atoms
     * @return array<string, DailyAudioDrillEnhancement>
     */
    private function enhancements(Collection $atoms): array
    {
        $enhancements = [];

        foreach ($atoms->chunk(self::ENHANCEMENT_BATCH_SIZE) as $batch) {
            foreach ($this->enhancementBatch($batch->values()) as $cardId => $enhancement) {
                $enhancements[$cardId] = $enhancement;
            }
        }

        return $enhancements;
    }

    /**
     * @param  Collection<int, DailyAudioLearningAtom>  $atoms
     * @return array<string, DailyAudioDrillEnhancement>
     */
    private function enhancementBatch(Collection $atoms): array
    {
        if ($atoms->isEmpty()) {
            return [];
        }

        try {
            $raw = $this->openAi->generateJson(
                systemInstruction: 'Return valid JSON for audio drill examples. English fields must contain English only.',
                prompt: $this->enhancementPrompt($atoms),
                model: (string) config('services.openai.daily_audio_model'),
                reasoningEffort: (string) config('services.openai.daily_audio_reasoning_effort'),
            );
            $parsed = $this->parseJsonObject($raw);
        } catch (JsonException|RuntimeException $exception) {
            Log::warning(
                'Daily Audio drill enhancement batch failed; deterministic fallbacks will be used.',
                [
                    'card_count' => $atoms->count(),
                    'exception' => $exception::class,
                ],
            );

            return [];
        }

        $expectedCardIds = array_fill_keys(
            $atoms->map(fn (DailyAudioLearningAtom $atom): string => $atom->cardId)->all(),
            true,
        );
        $items = is_array($parsed['items'] ?? null)
            ? array_slice($parsed['items'], 0, self::ENHANCEMENT_BATCH_SIZE * 2)
            : [];
        $enhancements = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $cardId = $this->stringField($item, 'cardId');
            if ($cardId === null || ! isset($expectedCardIds[$cardId])) {
                continue;
            }

            $englishCue = DailyAudioJapaneseText::safeEnglish(
                $this->stringField($item, 'englishCue'),
            );
            $anchor = is_array($item['anchor'] ?? null) ? $item['anchor'] : null;
            $example = $anchor !== null
                ? DailyAudioJapaneseText::normalizeDisplay(
                    $this->stringField($anchor, 'japanese', 'text', 'exampleJp'),
                    $this->stringField($anchor, 'reading', 'exampleReading'),
                    requireReadingForKanji: true,
                )
                : DailyAudioJapaneseText::normalizeDisplay(
                    $this->stringField($item, 'exampleJp'),
                    $this->stringField($item, 'exampleReading'),
                    requireReadingForKanji: true,
                );
            $exampleEn = DailyAudioJapaneseText::safeGeneratedTranslation(
                $anchor !== null
                    ? $this->stringField($anchor, 'english', 'translation', 'exampleEn')
                    : $this->stringField($item, 'exampleEn'),
                $example['text'] ?? null,
                $englishCue,
            );
            $variations = [
                ...$this->variations(
                    $item['grammarSubstitutions'] ?? null,
                    'grammar_substitution',
                    $englishCue,
                ),
                ...$this->variations(
                    $item['formTransforms'] ?? null,
                    'form_transform',
                    $englishCue,
                ),
            ];

            $enhancements[$cardId] = new DailyAudioDrillEnhancement(
                englishCue: $englishCue,
                exampleJp: $example !== null && $exampleEn !== null
                    ? $example['text']
                    : null,
                exampleReading: $example !== null && $exampleEn !== null
                    ? ($example['reading'] ?? null)
                    : null,
                exampleEn: $example !== null ? $exampleEn : null,
                variations: $this->balancedVariations($variations),
            );
        }

        return $enhancements;
    }

    /**
     * @param  Collection<int, DailyAudioLearningAtom>  $atoms
     */
    private function enhancementPrompt(Collection $atoms): string
    {
        $cards = $atoms
            ->map(fn (DailyAudioLearningAtom $atom): array => [
                'cardId' => mb_substr($atom->cardId, 0, 64, 'UTF-8'),
                'cardType' => mb_substr($atom->cardType, 0, 64, 'UTF-8'),
                'targetText' => $this->boundedPromptText($atom->targetText),
                'reading' => $this->boundedPromptText($atom->reading),
                'english' => $this->boundedPromptText($atom->english),
                'exampleJp' => $this->boundedPromptText($atom->exampleJp),
                'exampleEn' => $this->boundedPromptText($atom->exampleEn),
                'deckName' => $this->boundedPromptText($atom->deckName, 255),
                'noteType' => $this->boundedPromptText($atom->noteType, 255),
            ])
            ->values()
            ->all();
        $cardsJson = json_encode(
            $cards,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );

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

    /**
     * @return list<DailyAudioDrillVariation>
     */
    private function variations(
        mixed $values,
        string $kind,
        ?string $englishCue,
    ): array {
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

    private function parseVariation(
        mixed $value,
        string $kind,
        ?string $englishCue,
    ): ?DailyAudioDrillVariation {
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

        return new DailyAudioDrillVariation(
            kind: $kind,
            japanese: $japanese['text'],
            reading: $japanese['reading'] ?? null,
            english: $english,
        );
    }

    /**
     * @param  list<DailyAudioDrillVariation>  $variations
     * @return list<DailyAudioDrillVariation>
     */
    private function balancedVariations(array $variations): array
    {
        $selected = [
            ...array_slice(array_values(array_filter(
                $variations,
                fn (DailyAudioDrillVariation $variation): bool => $variation->kind === 'grammar_substitution',
            )), 0, 2),
            ...array_slice(array_values(array_filter(
                $variations,
                fn (DailyAudioDrillVariation $variation): bool => $variation->kind === 'form_transform',
            )), 0, 2),
        ];
        $selectedKeys = array_fill_keys(array_map(
            fn (DailyAudioDrillVariation $variation): string => "{$variation->kind}:{$variation->japanese}",
            $selected,
        ), true);

        foreach ($variations as $variation) {
            if (count($selected) >= self::MAX_VARIATIONS_PER_ATOM) {
                break;
            }

            $key = "{$variation->kind}:{$variation->japanese}";
            if (isset($selectedKeys[$key])) {
                continue;
            }
            $selected[] = $variation;
            $selectedKeys[$key] = true;
        }

        return array_slice($selected, 0, self::MAX_VARIATIONS_PER_ATOM);
    }

    /**
     * @param  Collection<int, DailyAudioLearningAtom>  $atoms
     * @param  array<string, DailyAudioDrillEnhancement>  $enhancements
     */
    private function buildScript(
        Collection $atoms,
        array $enhancements,
        string $l1VoiceId,
        string $l2VoiceId,
    ): DailyAudioDrillGenerationResult {
        $units = collect([
            DailyAudioScriptUnit::marker('Daily Audio Practice - Drills'),
            DailyAudioScriptUnit::narration(
                "Daily Audio Practice. We'll start with recognition drills, then switch to production drills.",
                $l1VoiceId,
            ),
            DailyAudioScriptUnit::pause(1),
        ]);
        $prompts = [];
        $seenJapanese = [];
        $metadata = [
            'enhancedAtomCount' => 0,
            'generatedPromptCount' => 0,
            'fallbackPromptCount' => 0,
            'missingCueCount' => 0,
            'totalPromptCount' => 0,
            'unitCount' => 0,
            'l2UnitCount' => 0,
            'l2UnitsWithReadingCount' => 0,
            'l2UnitsMissingReadingCount' => 0,
        ];

        foreach ($atoms as $atom) {
            $enhancement = $enhancements[$atom->cardId] ?? null;
            $built = $this->prompts($atom, $enhancement);
            if ($built['enhanced']) {
                $metadata['enhancedAtomCount']++;
            }
            $metadata['missingCueCount'] += $built['missingCueCount'];

            foreach ($built['prompts'] as $prompt) {
                $key = preg_replace('/\s+/u', '', $prompt['japanese']);
                $key = is_string($key) ? $key : $prompt['japanese'];
                if (isset($seenJapanese[$key])) {
                    continue;
                }
                $seenJapanese[$key] = true;
                $metadata[$prompt['source'] === 'generated'
                    ? 'generatedPromptCount'
                    : 'fallbackPromptCount']++;
                $prompts[] = $prompt;
            }
        }
        $metadata['totalPromptCount'] = count($prompts);

        $units->push(DailyAudioScriptUnit::marker('Recognition drills'));
        foreach ($prompts as $prompt) {
            $this->pushRecognitionPrompt($units, $prompt, $l1VoiceId, $l2VoiceId);
        }

        $units->push(
            DailyAudioScriptUnit::marker('Production drills'),
            DailyAudioScriptUnit::narration(
                'Now the order reverses. Listen to the English prompt, then say the Japanese before the answer.',
                $l1VoiceId,
            ),
            DailyAudioScriptUnit::pause(1),
        );
        foreach ($prompts as $prompt) {
            $this->pushProductionPrompt($units, $prompt, $l1VoiceId, $l2VoiceId);
        }

        $units->push(DailyAudioScriptUnit::narration(
            'Drill track complete. Nice work.',
            $l1VoiceId,
        ));

        $metadata['unitCount'] = $units->count();
        foreach ($units as $unit) {
            if ($unit->type !== 'L2') {
                continue;
            }
            $metadata['l2UnitCount']++;
            $metadata[$unit->reading === null
                ? 'l2UnitsMissingReadingCount'
                : 'l2UnitsWithReadingCount']++;
        }

        return new DailyAudioDrillGenerationResult($units, $metadata);
    }

    /**
     * @return array{
     *     prompts: list<array{
     *         label: string,
     *         japanese: string,
     *         reading: string|null,
     *         english: string,
     *         source: 'generated'|'fallback'
     *     }>,
     *     enhanced: bool,
     *     missingCueCount: int
     * }
     */
    private function prompts(
        DailyAudioLearningAtom $atom,
        ?DailyAudioDrillEnhancement $enhancement,
    ): array {
        $cueText = $enhancement?->englishCue
            ?? DailyAudioJapaneseText::safeEnglish($atom->english)
            ?? DailyAudioJapaneseText::safeEnglish($atom->exampleEn);
        $target = DailyAudioJapaneseText::normalizeDisplay($atom->targetText, $atom->reading);
        $prompts = [];
        $hasGeneratedContent = $enhancement?->hasGeneratedContent() ?? false;
        $hasGeneratedAnchor = $enhancement?->exampleJp !== null
            && $enhancement->exampleEn !== null;
        $exampleJp = $enhancement?->exampleJp ?? $atom->exampleJp;
        $exampleEn = $enhancement?->exampleEn
            ?? DailyAudioJapaneseText::safeEnglish($atom->exampleEn);

        if ($exampleJp !== null && $exampleEn !== null) {
            $example = DailyAudioJapaneseText::normalizeDisplay(
                $exampleJp,
                $enhancement?->exampleReading
                    ?? ($exampleJp === $atom->targetText ? $atom->reading : null),
            );
            if ($example !== null) {
                $prompts[] = [
                    'label' => 'Anchor: '.$this->labelTarget($atom),
                    'japanese' => $example['text'],
                    'reading' => $example['reading'] ?? null,
                    'english' => $exampleEn,
                    'source' => $hasGeneratedAnchor ? 'generated' : 'fallback',
                ];
            }
        }

        foreach (array_slice($enhancement?->variations ?? [], 0, self::MAX_VARIATIONS_PER_ATOM) as $index => $variation) {
            $japanese = DailyAudioJapaneseText::normalizeDisplay(
                $variation->japanese,
                $variation->reading,
            );
            if ($japanese === null) {
                continue;
            }
            $prompts[] = [
                'label' => 'Variation '.($index + 1).': '.$this->labelTarget($atom),
                'japanese' => $japanese['text'],
                'reading' => $japanese['reading'] ?? null,
                'english' => $variation->english,
                'source' => 'generated',
            ];
        }

        if (! $hasGeneratedAnchor && $cueText !== null && $target !== null) {
            array_unshift($prompts, [
                'label' => 'Drill: '.$this->labelTarget($atom),
                'japanese' => $target['text'],
                'reading' => $target['reading'] ?? null,
                'english' => $cueText,
                'source' => 'fallback',
            ]);
        }

        return [
            'prompts' => $prompts,
            'enhanced' => $hasGeneratedContent,
            'missingCueCount' => $cueText === null && ! $hasGeneratedContent ? 1 : 0,
        ];
    }

    /**
     * @param  Collection<int, DailyAudioScriptUnit>  $units
     * @param  array{label: string, japanese: string, reading: string|null, english: string, source: string}  $prompt
     */
    private function pushRecognitionPrompt(
        Collection $units,
        array $prompt,
        string $l1VoiceId,
        string $l2VoiceId,
    ): void {
        $units->push(
            DailyAudioScriptUnit::marker("Recognition: {$prompt['label']}"),
            DailyAudioScriptUnit::targetLanguage(
                $prompt['japanese'],
                $prompt['reading'],
                $prompt['english'],
                $l2VoiceId,
                0.75,
            ),
            DailyAudioScriptUnit::pause($this->recallPauseSeconds($prompt['english'])),
            DailyAudioScriptUnit::targetLanguage(
                $prompt['japanese'],
                $prompt['reading'],
                $prompt['english'],
                $l2VoiceId,
                1,
            ),
            DailyAudioScriptUnit::pause(1.25),
            DailyAudioScriptUnit::narration($prompt['english'], $l1VoiceId),
            DailyAudioScriptUnit::pause(2),
        );
    }

    /**
     * @param  Collection<int, DailyAudioScriptUnit>  $units
     * @param  array{label: string, japanese: string, reading: string|null, english: string, source: string}  $prompt
     */
    private function pushProductionPrompt(
        Collection $units,
        array $prompt,
        string $l1VoiceId,
        string $l2VoiceId,
    ): void {
        $units->push(
            DailyAudioScriptUnit::marker($prompt['label']),
            DailyAudioScriptUnit::narration(
                "How do you say \"{$prompt['english']}\"?",
                $l1VoiceId,
            ),
            DailyAudioScriptUnit::pause($this->recallPauseSeconds($prompt['english'])),
            DailyAudioScriptUnit::targetLanguage(
                $prompt['japanese'],
                $prompt['reading'],
                $prompt['english'],
                $l2VoiceId,
                0.75,
            ),
            DailyAudioScriptUnit::pause(1),
            DailyAudioScriptUnit::targetLanguage(
                $prompt['japanese'],
                $prompt['reading'],
                $prompt['english'],
                $l2VoiceId,
                1,
            ),
            DailyAudioScriptUnit::pause(2.5),
        );
    }

    private function recallPauseSeconds(string $text): float
    {
        $length = mb_strlen(trim($text), 'UTF-8');

        return match (true) {
            $length > 80 => 9,
            $length > 48 => 7,
            $length > 28 => 5.5,
            default => 4,
        };
    }

    private function labelTarget(DailyAudioLearningAtom $atom): string
    {
        return mb_substr(trim($atom->targetText), 0, 200, 'UTF-8');
    }

    private function boundedPromptText(?string $text, int $limit = 1_000): ?string
    {
        if ($text === null) {
            return null;
        }

        return mb_substr(trim($text), 0, $limit, 'UTF-8');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
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

    /**
     * @param  array<string, mixed>  $record
     */
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
