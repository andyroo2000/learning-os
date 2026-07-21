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
        $speakerVoiceIds = $this->speakerVoiceIds($snapshot);
        if (! is_string($narratorVoiceId) || trim($narratorVoiceId) === '' || $speakerVoiceIds === []) {
            throw new RuntimeException('Course generation requires narrator and speaker voice IDs.');
        }

        try {
            $source = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Course source could not be encoded for generation.', 0, $exception);
        }
        if (strlen($source) > 500000) {
            throw new RuntimeException('Course source is too large for script generation.');
        }

        $prompt = <<<PROMPT
Create a Pimsleur-style English-to-Japanese audio lesson from this Course and its first Episode:
{$source}

Return one JSON object with exactly these top-level keys:
- "exchanges": 1-100 ordered objects with speakerName, speakerVoiceId, textL2, readingL2 (string or null), translationL1, and vocabularyItems.
- "scriptUnits": 1-1000 ordered objects. Allowed exact types are marker, narration_L1, pause, and L2.

Each vocabularyItems value is an array of at most 10 objects with textL2, readingL2 (string or null), translationL1, a non-negative complexityScore no greater than 100000, and components (array or null).
Marker units require label. narration_L1 units require English text and the Course l1VoiceId as voiceId. Pause units require seconds from 0.1 to 60. L2 units require Japanese text, English translation, voiceId from a source speaker or Course speaker voice, and speed from 0.5 to 2.0. Use bracket reading notation such as 北海道[ほっかいどう] where useful.

Teach the dialogue progressively: scenario, listening, translation, vocabulary, prompted recall, response, and review. Stay within maxLessonDurationMinutes. Do not include markdown or keys outside the requested shapes.
PROMPT;

        $result = ContentCourseScriptGenerationResult::fromProviderJson($this->client->generateJson(
            'You generate bounded, production-ready conversational language lesson scripts. Follow the JSON contract exactly.',
            $prompt,
        ));

        foreach ($result->exchanges as $exchange) {
            if (! in_array($exchange['speakerVoiceId'], $speakerVoiceIds, true)) {
                throw new RuntimeException('Course generator returned an unknown speaker voice ID.');
            }
        }
        foreach ($result->units as $unit) {
            if ($unit->type === 'narration_L1' && $unit->voiceId !== $narratorVoiceId) {
                throw new RuntimeException('Course generator returned an unknown narrator voice ID.');
            }
            if ($unit->type === 'L2' && ! in_array($unit->voiceId, $speakerVoiceIds, true)) {
                throw new RuntimeException('Course generator returned an unknown speaker voice ID.');
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $snapshot
     * @return list<string>
     */
    private function speakerVoiceIds(array $snapshot): array
    {
        $voiceIds = [
            $snapshot['course']['speaker1VoiceId'] ?? null,
            $snapshot['course']['speaker2VoiceId'] ?? null,
        ];
        $sentences = $snapshot['episode']['sentences'] ?? [];
        if (is_array($sentences)) {
            foreach ($sentences as $sentence) {
                if (is_array($sentence)) {
                    $voiceIds[] = $sentence['speakerVoiceId'] ?? null;
                }
            }
        }

        return array_values(array_unique(array_filter(
            $voiceIds,
            static fn (mixed $voiceId): bool => is_string($voiceId) && trim($voiceId) !== '',
        )));
    }
}
