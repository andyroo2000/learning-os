<?php

namespace App\Domain\Content\Services;

use App\Domain\Content\Results\ContentCourseScriptGenerationResult;
use JsonException;
use RuntimeException;

class ContentCourseScriptGenerator
{
    public function __construct(
        private readonly ContentOpenAiClient $client,
    ) {}

    /** @param array<string, mixed> $snapshot */
    public function generate(array $snapshot): ContentCourseScriptGenerationResult
    {
        $narratorVoiceId = $snapshot['course']['l1VoiceId'] ?? null;
        $maxDurationMinutes = $snapshot['course']['maxLessonDurationMinutes'] ?? null;
        $speakerVoiceIds = $this->speakerVoiceIds($snapshot);

        $this->assertVoiceConfiguration($narratorVoiceId, $speakerVoiceIds);
        $this->assertMaximumDuration($maxDurationMinutes);
        $source = $this->encodeSource($snapshot);

        $result = ContentCourseScriptGenerationResult::fromProviderJson($this->client->generateJson(
            'You generate bounded, production-ready conversational language lesson scripts. Follow the JSON contract exactly.',
            $this->prompt($source),
        ));

        $this->assertResultDuration($result, $maxDurationMinutes);
        $this->assertExchangeVoices($result, $speakerVoiceIds);
        $this->assertUnitVoices($result, $narratorVoiceId, $speakerVoiceIds);

        return $result;
    }

    /** @param list<string> $speakerVoiceIds */
    private function assertVoiceConfiguration(mixed $narratorVoiceId, array $speakerVoiceIds): void
    {
        if (! is_string($narratorVoiceId)) {
            throw new RuntimeException('Course generation requires narrator and speaker voice IDs.');
        }

        if (trim($narratorVoiceId) === '') {
            throw new RuntimeException('Course generation requires narrator and speaker voice IDs.');
        }

        if ($speakerVoiceIds === []) {
            throw new RuntimeException('Course generation requires narrator and speaker voice IDs.');
        }
    }

    private function assertMaximumDuration(mixed $maxDurationMinutes): void
    {
        if (! is_int($maxDurationMinutes)) {
            throw new RuntimeException('Course generation requires a valid maximum duration.');
        }

        if ($maxDurationMinutes < 1) {
            throw new RuntimeException('Course generation requires a valid maximum duration.');
        }

        if ($maxDurationMinutes > 120) {
            throw new RuntimeException('Course generation requires a valid maximum duration.');
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function encodeSource(array $snapshot): string
    {
        try {
            $source = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Course source could not be encoded for generation.', 0, $exception);
        }
        if (strlen($source) > 500000) {
            throw new RuntimeException('Course source is too large for script generation.');
        }

        return $source;
    }

    private function prompt(string $source): string
    {
        return <<<PROMPT
Create a Pimsleur-style English-to-Japanese audio lesson from this Course and its first Episode:
{$source}

Return one JSON object with exactly these top-level keys:
- "exchanges": 1-100 ordered objects with speakerName, speakerVoiceId, textL2, readingL2 (string or null), translationL1, and vocabularyItems.
- "scriptUnits": 1-1000 ordered objects. Allowed exact types are marker, narration_L1, pause, and L2.

Each vocabularyItems value is an array of at most 10 objects with textL2, readingL2 (string or null), translationL1, a non-negative complexityScore no greater than 100000, and components (array or null).
Marker units require label. narration_L1 units require English text and the Course l1VoiceId as voiceId. Pause units require seconds from 0.1 to 60. L2 units require Japanese text, English translation, voiceId from a source speaker or Course speaker voice, and speed from 0.5 to 2.0. Use bracket reading notation such as 北海道[ほっかいどう] where useful.

Teach the dialogue progressively: scenario, listening, translation, vocabulary, prompted recall, response, and review. Stay within maxLessonDurationMinutes. Do not include markdown or keys outside the requested shapes.
PROMPT;
    }

    private function assertResultDuration(
        ContentCourseScriptGenerationResult $result,
        int $maxDurationMinutes,
    ): void {
        if ($result->estimatedDurationSeconds > $maxDurationMinutes * 60) {
            throw new RuntimeException('Course generator exceeded the maximum lesson duration.');
        }
    }

    /** @param list<string> $speakerVoiceIds */
    private function assertExchangeVoices(
        ContentCourseScriptGenerationResult $result,
        array $speakerVoiceIds,
    ): void {
        foreach ($result->exchanges as $exchange) {
            if (! in_array($exchange['speakerVoiceId'], $speakerVoiceIds, true)) {
                throw new RuntimeException('Course generator returned an unknown speaker voice ID.');
            }
        }
    }

    /** @param list<string> $speakerVoiceIds */
    private function assertUnitVoices(
        ContentCourseScriptGenerationResult $result,
        string $narratorVoiceId,
        array $speakerVoiceIds,
    ): void {
        foreach ($result->units as $unit) {
            $this->assertUnitNarratorVoice($unit->type, $unit->voiceId, $narratorVoiceId);
            $this->assertUnitSpeakerVoice($unit->type, $unit->voiceId, $speakerVoiceIds);
        }
    }

    private function assertUnitNarratorVoice(string $type, ?string $voiceId, string $narratorVoiceId): void
    {
        if ($type === 'narration_L1' && $voiceId !== $narratorVoiceId) {
            throw new RuntimeException('Course generator returned an unknown narrator voice ID.');
        }
    }

    /** @param list<string> $speakerVoiceIds */
    private function assertUnitSpeakerVoice(string $type, ?string $voiceId, array $speakerVoiceIds): void
    {
        if ($type === 'L2' && ! in_array($voiceId, $speakerVoiceIds, true)) {
            throw new RuntimeException('Course generator returned an unknown speaker voice ID.');
        }
    }

    /** @param array<string, mixed> $snapshot
     * @return list<string>
     */
    private function speakerVoiceIds(array $snapshot): array
    {
        $voiceIds = [
            $snapshot['course']['speaker1VoiceId'] ?? null,
            $snapshot['course']['speaker2VoiceId'] ?? null,
            ...$this->sentenceVoiceIds($snapshot),
        ];

        return array_values(array_unique(array_filter(
            $voiceIds,
            static fn (mixed $voiceId): bool => is_string($voiceId) && trim($voiceId) !== '',
        )));
    }

    /** @param array<string, mixed> $snapshot
     * @return list<mixed>
     */
    private function sentenceVoiceIds(array $snapshot): array
    {
        $sentences = $snapshot['episode']['sentences'] ?? [];
        if (! is_array($sentences)) {
            return [];
        }

        $voiceIds = [];
        foreach ($sentences as $sentence) {
            if (is_array($sentence)) {
                $voiceIds[] = $sentence['speakerVoiceId'] ?? null;
            }
        }

        return $voiceIds;
    }
}
