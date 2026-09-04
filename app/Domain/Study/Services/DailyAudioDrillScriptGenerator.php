<?php

namespace App\Domain\Study\Services;

use App\Domain\Study\Results\DailyAudioDrillEnhancement;
use App\Domain\Study\Results\DailyAudioDrillGenerationResult;
use App\Domain\Study\Results\DailyAudioLearningAtom;
use App\Domain\Study\Results\DailyAudioScriptUnit;
use App\Domain\Study\Support\DailyAudioDrillVoices;
use App\Domain\Study\Support\DailyAudioJapaneseText;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class DailyAudioDrillScriptGenerator
{
    private const JAPANESE_SPEECH_SPEED = 1.0;

    /**
     * Fish Audio varies by voice and punctuation. These deliberately conservative
     * rates are calibrated against completed Daily Audio tracks, with the final
     * multiplier accounting for synthesis padding and concatenation overhead.
     */
    private const ENGLISH_WORDS_PER_SECOND = 2.4;

    private const JAPANESE_CHARACTERS_PER_SECOND = 5.0;

    private const MINIMUM_SPOKEN_UNIT_SECONDS = 0.8;

    private const SPOKEN_UNIT_PADDING_SECONDS = 0.25;

    private const DURATION_ESTIMATE_MULTIPLIER = 1.08;

    public const MAX_SCRIPT_ATOMS = 50;

    public const ENHANCEMENT_BATCH_SIZE = DailyAudioDrillEnhancer::BATCH_SIZE;

    private const MAX_VARIATIONS_PER_ATOM = DailyAudioDrillEnhancer::MAX_VARIATIONS_PER_ATOM;

    private readonly DailyAudioDrillEnhancer $enhancer;

    public function __construct(
        OpenAiStudyCardGenerator $openAi,
    ) {
        $this->enhancer = new DailyAudioDrillEnhancer($openAi);
    }

    /**
     * @param  iterable<int, DailyAudioLearningAtom>  $atoms
     */
    public function generate(
        iterable $atoms,
        string $l1VoiceId,
        string $l2VoiceId,
        int $targetDurationMinutes = DailyAudioPracticeGeneration::DEFAULT_TARGET_DURATION_MINUTES,
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
        if (
            $targetDurationMinutes < DailyAudioPracticeGeneration::MIN_TARGET_DURATION_MINUTES
            || $targetDurationMinutes > DailyAudioPracticeGeneration::MAX_TARGET_DURATION_MINUTES
        ) {
            throw new InvalidArgumentException(
                'Daily Audio Practice duration is out of range.',
            );
        }
        if ($atoms->contains(
            fn (mixed $atom): bool => ! $atom instanceof DailyAudioLearningAtom,
        )) {
            throw new InvalidArgumentException(
                'Daily Audio Practice script atoms must be learning atoms.',
            );
        }

        $enhancements = $this->enhancer->enhance($atoms);
        $voices = new DailyAudioDrillVoices($l1VoiceId, $l2VoiceId);

        return $this->buildScript(
            $atoms,
            $enhancements,
            $voices,
            $targetDurationMinutes,
        );
    }

    /**
     * @param  Collection<int, DailyAudioLearningAtom>  $atoms
     * @param  array<string, DailyAudioDrillEnhancement>  $enhancements
     */
    private function buildScript(
        Collection $atoms,
        array $enhancements,
        DailyAudioDrillVoices $voices,
        int $targetDurationMinutes,
    ): DailyAudioDrillGenerationResult {
        $units = $this->initialUnits($voices);
        $built = $this->buildPromptLadders($atoms, $enhancements);
        $availablePrompts = $this->roundRobinPrompts($built['ladders']);
        $prompts = $this->promptsForDuration(
            $availablePrompts,
            $targetDurationMinutes,
            $voices,
        );
        $this->appendDrills($units, $prompts, $voices);
        $metadata = $this->scriptMetadata(
            $units,
            [
                'prompts' => $prompts,
                'available_prompts' => $availablePrompts,
                'built' => $built,
            ],
            $targetDurationMinutes,
        );

        return new DailyAudioDrillGenerationResult($units, $metadata);
    }

    /** @return Collection<int, DailyAudioScriptUnit> */
    private function initialUnits(DailyAudioDrillVoices $voices): Collection
    {
        return collect([
            DailyAudioScriptUnit::marker('Daily Audio Practice - Drills'),
            DailyAudioScriptUnit::narration(
                "Daily Audio Practice. We'll start with recognition drills, then switch to production drills.",
                $voices->narrator,
            ),
            DailyAudioScriptUnit::pause(1),
        ]);
    }

    /**
     * @param  Collection<int, DailyAudioLearningAtom>  $atoms
     * @param  array<string, DailyAudioDrillEnhancement>  $enhancements
     * @return array{ladders: list<list<array{label: string, japanese: string, reading: string|null, english: string, source: 'generated'|'fallback'}>>, enhanced_count: int, missing_cue_count: int}
     */
    private function buildPromptLadders(Collection $atoms, array $enhancements): array
    {
        $ladders = [];
        $seenJapanese = [];
        $enhancedCount = 0;
        $missingCueCount = 0;

        foreach ($atoms as $atom) {
            $built = $this->prompts($atom, $enhancements[$atom->cardId] ?? null);
            $enhancedCount += $built['enhanced'] ? 1 : 0;
            $missingCueCount += $built['missingCueCount'];
            $ladder = $this->uniquePromptLadder($built['prompts'], $seenJapanese);
            if ($ladder !== []) {
                $ladders[] = $ladder;
            }
        }

        return [
            'ladders' => $ladders,
            'enhanced_count' => $enhancedCount,
            'missing_cue_count' => $missingCueCount,
        ];
    }

    /**
     * @param  list<array{label: string, japanese: string, reading: string|null, english: string, source: 'generated'|'fallback'}>  $prompts
     * @param  array<string, true>  $seenJapanese
     * @return list<array{label: string, japanese: string, reading: string|null, english: string, source: 'generated'|'fallback'}>
     */
    private function uniquePromptLadder(array $prompts, array &$seenJapanese): array
    {
        $ladder = [];
        foreach ($prompts as $prompt) {
            $key = preg_replace('/\s+/u', '', $prompt['japanese']);
            $key = is_string($key) ? $key : $prompt['japanese'];
            if (isset($seenJapanese[$key])) {
                continue;
            }
            $seenJapanese[$key] = true;
            $ladder[] = $prompt;
        }

        return $ladder;
    }

    /**
     * @param  Collection<int, DailyAudioScriptUnit>  $units
     * @param  list<array{label: string, japanese: string, reading: string|null, english: string, source: 'generated'|'fallback'}>  $prompts
     */
    private function appendDrills(Collection $units, array $prompts, DailyAudioDrillVoices $voices): void
    {
        $units->push(DailyAudioScriptUnit::marker('Recognition drills'));
        foreach ($prompts as $prompt) {
            $this->pushRecognitionPrompt($units, $prompt, $voices);
        }

        $units->push(
            DailyAudioScriptUnit::marker('Production drills'),
            DailyAudioScriptUnit::narration(
                'Now the order reverses. Listen to the English prompt, then say the Japanese before the answer.',
                $voices->narrator,
            ),
            DailyAudioScriptUnit::pause(1),
        );
        foreach ($prompts as $prompt) {
            $this->pushProductionPrompt($units, $prompt, $voices);
        }

        $units->push(DailyAudioScriptUnit::narration(
            'Drill track complete. Nice work.',
            $voices->narrator,
        ));
    }

    /**
     * @param  Collection<int, DailyAudioScriptUnit>  $units
     * @param  array{prompts: list<array{label: string, japanese: string, reading: string|null, english: string, source: 'generated'|'fallback'}>, available_prompts: list<array{label: string, japanese: string, reading: string|null, english: string, source: 'generated'|'fallback'}>, built: array{ladders: list<list<array{label: string, japanese: string, reading: string|null, english: string, source: 'generated'|'fallback'}>>, enhanced_count: int, missing_cue_count: int}}  $script
     * @return array<string, int|bool>
     */
    private function scriptMetadata(
        Collection $units,
        array $script,
        int $targetDurationMinutes,
    ): array {
        $prompts = $script['prompts'];
        $availablePrompts = $script['available_prompts'];
        $built = $script['built'];
        $l2Units = $units->filter(
            static fn (DailyAudioScriptUnit $unit): bool => $unit->type === 'L2',
        );
        $l2UnitsMissingReading = $l2Units->filter(
            static fn (DailyAudioScriptUnit $unit): bool => $unit->reading === null,
        )->count();

        return [
            'enhancedAtomCount' => $built['enhanced_count'],
            'generatedPromptCount' => collect($prompts)
                ->filter(static fn (array $prompt): bool => $prompt['source'] === 'generated')
                ->count(),
            'fallbackPromptCount' => collect($prompts)
                ->filter(static fn (array $prompt): bool => $prompt['source'] === 'fallback')
                ->count(),
            'missingCueCount' => $built['missing_cue_count'],
            'totalPromptCount' => count($prompts),
            'unitCount' => $units->count(),
            'l2UnitCount' => $l2Units->count(),
            'l2UnitsWithReadingCount' => $l2Units->count() - $l2UnitsMissingReading,
            'l2UnitsMissingReadingCount' => $l2UnitsMissingReading,
            'targetDurationMinutes' => $targetDurationMinutes,
            'availablePromptCount' => count($availablePrompts),
            'estimatedDurationSeconds' => (int) round($this->estimatedScriptDurationSeconds($units)),
            'durationContentExhausted' => count($prompts) === count($availablePrompts),
        ];
    }

    /**
     * Take the anchor/fallback prompt from every card before taking second and
     * later variations. Short editions therefore retain breadth instead of
     * exhausting one card's entire ladder first.
     *
     * @param  list<list<array{label: string, japanese: string, reading: string|null, english: string, source: 'generated'|'fallback'}>>  $ladders
     * @return list<array{label: string, japanese: string, reading: string|null, english: string, source: 'generated'|'fallback'}>
     */
    private function roundRobinPrompts(array $ladders): array
    {
        $prompts = [];

        for ($depth = 0; $depth <= self::MAX_VARIATIONS_PER_ATOM; $depth++) {
            foreach ($ladders as $ladder) {
                if (isset($ladder[$depth])) {
                    $prompts[] = $ladder[$depth];
                }
            }
        }

        return $prompts;
    }

    /**
     * @param  list<array{label: string, japanese: string, reading: string|null, english: string, source: 'generated'|'fallback'}>  $availablePrompts
     * @return list<array{label: string, japanese: string, reading: string|null, english: string, source: 'generated'|'fallback'}>
     */
    private function promptsForDuration(
        array $availablePrompts,
        int $targetDurationMinutes,
        DailyAudioDrillVoices $voices,
    ): array {
        $targetSeconds = $targetDurationMinutes * 60;
        $fixedUnits = collect([
            DailyAudioScriptUnit::narration(
                "Daily Audio Practice. We'll start with recognition drills, then switch to production drills.",
                $voices->narrator,
            ),
            DailyAudioScriptUnit::pause(1),
            DailyAudioScriptUnit::narration(
                'Now the order reverses. Listen to the English prompt, then say the Japanese before the answer.',
                $voices->narrator,
            ),
            DailyAudioScriptUnit::pause(1),
            DailyAudioScriptUnit::narration('Drill track complete. Nice work.', $voices->narrator),
        ]);
        $estimatedSeconds = $this->estimatedScriptDurationSeconds($fixedUnits);
        $selected = [];

        foreach ($availablePrompts as $prompt) {
            $promptSeconds = $this->estimatedPromptDurationSeconds($prompt);
            $nextSeconds = $estimatedSeconds + $promptSeconds;
            if ($this->shouldStopBeforePrompt($selected, [
                'target' => $targetSeconds,
                'current' => $estimatedSeconds,
                'next' => $nextSeconds,
            ])) {
                break;
            }

            $selected[] = $prompt;
            $estimatedSeconds = $nextSeconds;
            if ($estimatedSeconds >= $targetSeconds) {
                break;
            }
        }

        return $selected;
    }

    /**
     * @param  list<array<string, mixed>>  $selected
     * @param  array{target: float|int, current: float|int, next: float|int}  $duration
     */
    private function shouldStopBeforePrompt(array $selected, array $duration): bool
    {
        if ($selected === []) {
            return false;
        }
        if ($duration['next'] <= $duration['target']) {
            return false;
        }

        return abs($duration['target'] - $duration['current'])
            <= abs($duration['next'] - $duration['target']);
    }

    /**
     * Recognition and production each speak the Japanese twice, speak one
     * English line, and include two recall pauses plus their fixed pauses.
     *
     * @param  array{label: string, japanese: string, reading: string|null, english: string, source: string}  $prompt
     */
    private function estimatedPromptDurationSeconds(array $prompt): float
    {
        $japaneseSeconds = $this->estimatedJapaneseSpeechSeconds($prompt['japanese']);
        $englishSeconds = $this->estimatedEnglishSpeechSeconds($prompt['english']);
        $productionPromptSeconds = $this->estimatedEnglishSpeechSeconds(
            "How do you say \"{$prompt['english']}\"?",
        );

        return self::DURATION_ESTIMATE_MULTIPLIER * (
            ($japaneseSeconds * 4)
            + $englishSeconds
            + $productionPromptSeconds
        ) + ($this->recallPauseSeconds($prompt['english']) * 2) + 6.75;
    }

    /** @param Collection<int, DailyAudioScriptUnit> $units */
    private function estimatedScriptDurationSeconds(Collection $units): float
    {
        return $units->sum(function (DailyAudioScriptUnit $unit): float {
            return match ($unit->type) {
                'pause' => $unit->seconds ?? 0,
                'L2' => self::DURATION_ESTIMATE_MULTIPLIER
                    * $this->estimatedJapaneseSpeechSeconds($unit->text ?? ''),
                'narration_L1' => self::DURATION_ESTIMATE_MULTIPLIER
                    * $this->estimatedEnglishSpeechSeconds($unit->text ?? ''),
                default => 0,
            };
        });
    }

    private function estimatedEnglishSpeechSeconds(string $text): float
    {
        preg_match_all("/[A-Za-z0-9]+(?:'[A-Za-z0-9]+)?/u", $text, $matches);
        $wordCount = count($matches[0] ?? []);

        return max(
            self::MINIMUM_SPOKEN_UNIT_SECONDS,
            $wordCount / self::ENGLISH_WORDS_PER_SECOND,
        ) + self::SPOKEN_UNIT_PADDING_SECONDS;
    }

    private function estimatedJapaneseSpeechSeconds(string $text): float
    {
        $spoken = preg_replace('/[\s、。！？!?.,・「」『』（）()[\]]+/u', '', $text);
        $characterCount = mb_strlen(is_string($spoken) ? $spoken : $text, 'UTF-8');

        return max(
            self::MINIMUM_SPOKEN_UNIT_SECONDS,
            $characterCount / self::JAPANESE_CHARACTERS_PER_SECOND,
        ) + self::SPOKEN_UNIT_PADDING_SECONDS;
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
        $hasGeneratedContent = $enhancement?->hasGeneratedContent() ?? false;
        $hasGeneratedAnchor = $this->hasGeneratedAnchor($enhancement);
        $prompts = $this->anchorPrompt($atom, $enhancement, $hasGeneratedAnchor);
        array_push($prompts, ...$this->variationPrompts($atom, $enhancement));
        $fallback = $this->fallbackPrompt($atom, $cueText, $target, $hasGeneratedAnchor);
        if ($fallback !== null) {
            array_unshift($prompts, $fallback);
        }

        return [
            'prompts' => $prompts,
            'enhanced' => $hasGeneratedContent,
            'missingCueCount' => $this->missingCueCount($cueText, $hasGeneratedContent),
        ];
    }

    private function hasGeneratedAnchor(?DailyAudioDrillEnhancement $enhancement): bool
    {
        if ($enhancement?->exampleJp === null) {
            return false;
        }

        return $enhancement->exampleEn !== null;
    }

    /**
     * @return list<array{label: string, japanese: string, reading: string|null, english: string, source: 'generated'|'fallback'}>
     */
    private function anchorPrompt(
        DailyAudioLearningAtom $atom,
        ?DailyAudioDrillEnhancement $enhancement,
        bool $hasGeneratedAnchor,
    ): array {
        $exampleJp = $enhancement?->exampleJp ?? $atom->exampleJp;
        if ($exampleJp === null) {
            return [];
        }
        $exampleEn = $enhancement?->exampleEn ?? DailyAudioJapaneseText::safeEnglish($atom->exampleEn);
        if ($exampleEn === null) {
            return [];
        }
        $reading = $enhancement?->exampleReading
            ?? ($exampleJp === $atom->targetText ? $atom->reading : null);
        $example = DailyAudioJapaneseText::normalizeDisplay($exampleJp, $reading);
        if ($example === null) {
            return [];
        }

        return [[
            'label' => 'Anchor: '.$this->labelTarget($atom),
            'japanese' => $example['text'],
            'reading' => $example['reading'] ?? null,
            'english' => $exampleEn,
            'source' => $hasGeneratedAnchor ? 'generated' : 'fallback',
        ]];
    }

    /**
     * @return list<array{label: string, japanese: string, reading: string|null, english: string, source: 'generated'}>
     */
    private function variationPrompts(
        DailyAudioLearningAtom $atom,
        ?DailyAudioDrillEnhancement $enhancement,
    ): array {
        $prompts = [];
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

        return $prompts;
    }

    /**
     * @param  array{text: string, reading?: string|null}|null  $target
     * @return array{label: string, japanese: string, reading: string|null, english: string, source: 'fallback'}|null
     */
    private function fallbackPrompt(
        DailyAudioLearningAtom $atom,
        ?string $cueText,
        ?array $target,
        bool $hasGeneratedAnchor,
    ): ?array {
        if ($hasGeneratedAnchor) {
            return null;
        }
        if ($cueText === null) {
            return null;
        }
        if ($target === null) {
            return null;
        }

        return [
            'label' => 'Drill: '.$this->labelTarget($atom),
            'japanese' => $target['text'],
            'reading' => $target['reading'] ?? null,
            'english' => $cueText,
            'source' => 'fallback',
        ];
    }

    private function missingCueCount(?string $cueText, bool $hasGeneratedContent): int
    {
        if ($cueText !== null) {
            return 0;
        }

        return $hasGeneratedContent ? 0 : 1;
    }

    /**
     * @param  Collection<int, DailyAudioScriptUnit>  $units
     * @param  array{label: string, japanese: string, reading: string|null, english: string, source: string}  $prompt
     */
    private function pushRecognitionPrompt(
        Collection $units,
        array $prompt,
        DailyAudioDrillVoices $voices,
    ): void {
        $units->push(
            DailyAudioScriptUnit::marker("Recognition: {$prompt['label']}"),
            DailyAudioScriptUnit::targetLanguage(
                $prompt['japanese'],
                $prompt['reading'],
                $prompt['english'],
                $voices->speaker,
                self::JAPANESE_SPEECH_SPEED,
            ),
            DailyAudioScriptUnit::pause($this->recallPauseSeconds($prompt['english'])),
            DailyAudioScriptUnit::targetLanguage(
                $prompt['japanese'],
                $prompt['reading'],
                $prompt['english'],
                $voices->speaker,
                self::JAPANESE_SPEECH_SPEED,
            ),
            DailyAudioScriptUnit::pause(1.25),
            DailyAudioScriptUnit::narration($prompt['english'], $voices->narrator),
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
        DailyAudioDrillVoices $voices,
    ): void {
        $units->push(
            DailyAudioScriptUnit::marker($prompt['label']),
            DailyAudioScriptUnit::narration(
                "How do you say \"{$prompt['english']}\"?",
                $voices->narrator,
            ),
            DailyAudioScriptUnit::pause($this->recallPauseSeconds($prompt['english'])),
            DailyAudioScriptUnit::targetLanguage(
                $prompt['japanese'],
                $prompt['reading'],
                $prompt['english'],
                $voices->speaker,
                self::JAPANESE_SPEECH_SPEED,
            ),
            DailyAudioScriptUnit::pause(1),
            DailyAudioScriptUnit::targetLanguage(
                $prompt['japanese'],
                $prompt['reading'],
                $prompt['english'],
                $voices->speaker,
                self::JAPANESE_SPEECH_SPEED,
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
}
