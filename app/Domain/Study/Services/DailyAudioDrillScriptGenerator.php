<?php

namespace App\Domain\Study\Services;

use App\Domain\Study\Results\DailyAudioDrillEnhancement;
use App\Domain\Study\Results\DailyAudioDrillGenerationResult;
use App\Domain\Study\Results\DailyAudioLearningAtom;
use App\Domain\Study\Results\DailyAudioScriptUnit;
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

        return $this->buildScript(
            $atoms,
            $enhancements,
            $l1VoiceId,
            $l2VoiceId,
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
        string $l1VoiceId,
        string $l2VoiceId,
        int $targetDurationMinutes,
    ): DailyAudioDrillGenerationResult {
        $units = collect([
            DailyAudioScriptUnit::marker('Daily Audio Practice - Drills'),
            DailyAudioScriptUnit::narration(
                "Daily Audio Practice. We'll start with recognition drills, then switch to production drills.",
                $l1VoiceId,
            ),
            DailyAudioScriptUnit::pause(1),
        ]);
        $promptLadders = [];
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
            'targetDurationMinutes' => $targetDurationMinutes,
            'availablePromptCount' => 0,
            'estimatedDurationSeconds' => 0,
            'durationContentExhausted' => false,
        ];

        foreach ($atoms as $atom) {
            $enhancement = $enhancements[$atom->cardId] ?? null;
            $built = $this->prompts($atom, $enhancement);
            if ($built['enhanced']) {
                $metadata['enhancedAtomCount']++;
            }
            $metadata['missingCueCount'] += $built['missingCueCount'];

            $ladder = [];
            foreach ($built['prompts'] as $prompt) {
                $key = preg_replace('/\s+/u', '', $prompt['japanese']);
                $key = is_string($key) ? $key : $prompt['japanese'];
                if (isset($seenJapanese[$key])) {
                    continue;
                }
                $seenJapanese[$key] = true;
                $ladder[] = $prompt;
            }
            if ($ladder !== []) {
                $promptLadders[] = $ladder;
            }
        }

        $availablePrompts = $this->roundRobinPrompts($promptLadders);
        $prompts = $this->promptsForDuration(
            $availablePrompts,
            $targetDurationMinutes,
            $l1VoiceId,
        );
        foreach ($prompts as $prompt) {
            $metadata[$prompt['source'] === 'generated'
                ? 'generatedPromptCount'
                : 'fallbackPromptCount']++;
        }
        $metadata['availablePromptCount'] = count($availablePrompts);
        $metadata['totalPromptCount'] = count($prompts);
        $metadata['durationContentExhausted'] = count($prompts) === count($availablePrompts);

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
        $metadata['estimatedDurationSeconds'] = (int) round(
            $this->estimatedScriptDurationSeconds($units),
        );
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
        string $l1VoiceId,
    ): array {
        $targetSeconds = $targetDurationMinutes * 60;
        $fixedUnits = collect([
            DailyAudioScriptUnit::narration(
                "Daily Audio Practice. We'll start with recognition drills, then switch to production drills.",
                $l1VoiceId,
            ),
            DailyAudioScriptUnit::pause(1),
            DailyAudioScriptUnit::narration(
                'Now the order reverses. Listen to the English prompt, then say the Japanese before the answer.',
                $l1VoiceId,
            ),
            DailyAudioScriptUnit::pause(1),
            DailyAudioScriptUnit::narration('Drill track complete. Nice work.', $l1VoiceId),
        ]);
        $estimatedSeconds = $this->estimatedScriptDurationSeconds($fixedUnits);
        $selected = [];

        foreach ($availablePrompts as $prompt) {
            $promptSeconds = $this->estimatedPromptDurationSeconds($prompt);
            $nextSeconds = $estimatedSeconds + $promptSeconds;
            if ($selected !== []
                && $nextSeconds > $targetSeconds
                && abs($targetSeconds - $estimatedSeconds) <= abs($nextSeconds - $targetSeconds)) {
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
                self::JAPANESE_SPEECH_SPEED,
            ),
            DailyAudioScriptUnit::pause($this->recallPauseSeconds($prompt['english'])),
            DailyAudioScriptUnit::targetLanguage(
                $prompt['japanese'],
                $prompt['reading'],
                $prompt['english'],
                $l2VoiceId,
                self::JAPANESE_SPEECH_SPEED,
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
                self::JAPANESE_SPEECH_SPEED,
            ),
            DailyAudioScriptUnit::pause(1),
            DailyAudioScriptUnit::targetLanguage(
                $prompt['japanese'],
                $prompt['reading'],
                $prompt['english'],
                $l2VoiceId,
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
