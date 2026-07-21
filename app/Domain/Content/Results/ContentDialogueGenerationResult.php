<?php

namespace App\Domain\Content\Results;

use App\Domain\Content\Data\GenerateContentDialogueData;
use InvalidArgumentException;
use JsonException;

final readonly class ContentDialogueGenerationResult
{
    /** @param list<array{speaker: string, text: string, reading: string|null, translation: string, variations: list<string>}> $sentences */
    private function __construct(
        public string $title,
        public array $sentences,
    ) {}

    public static function fromJson(
        string $json,
        GenerateContentDialogueData $input,
        string $targetLanguage,
    ): self {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Dialogue provider response must be valid JSON.', 0, $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('Dialogue provider response shape is invalid.');
        }
        $topLevelKeys = array_keys($decoded);
        sort($topLevelKeys);
        if ($topLevelKeys !== ['sentences', 'title']) {
            throw new InvalidArgumentException('Dialogue provider response shape is invalid.');
        }

        $title = self::string($decoded['title'], 'Dialogue title', 255);
        $rawSentences = $decoded['sentences'];
        if (! is_array($rawSentences) || ! array_is_list($rawSentences) || count($rawSentences) !== $input->dialogueLength) {
            throw new InvalidArgumentException('Dialogue provider returned an invalid sentence count.');
        }

        $speakerNames = array_map(
            static fn (array $speaker): string => GenerateContentDialogueData::promptName($speaker['name']),
            $input->speakers,
        );
        $sentences = [];
        foreach ($rawSentences as $index => $rawSentence) {
            if (! is_array($rawSentence) || array_is_list($rawSentence)) {
                throw new InvalidArgumentException('Dialogue provider sentence must be an object.');
            }
            $keys = array_keys($rawSentence);
            sort($keys);
            if (! in_array($keys, [
                ['reading', 'speaker', 'text', 'translation', 'variations'],
                ['speaker', 'text', 'translation', 'variations'],
            ], true)) {
                throw new InvalidArgumentException('Dialogue provider sentence shape is invalid.');
            }

            $speaker = GenerateContentDialogueData::promptName(
                self::string($rawSentence['speaker'], 'Dialogue sentence speaker', 100),
            );
            if ($speaker !== $speakerNames[$index % count($speakerNames)]) {
                throw new InvalidArgumentException('Dialogue provider speakers must strictly alternate.');
            }
            $variations = $rawSentence['variations'];
            if (! is_array($variations) || ! array_is_list($variations) || count($variations) !== $input->variationCount) {
                throw new InvalidArgumentException('Dialogue provider sentence variations are invalid.');
            }

            $normalizedVariations = [];
            foreach ($variations as $variation) {
                $normalizedVariations[] = self::string($variation, 'Dialogue sentence variation', 5_000);
            }
            $text = self::string($rawSentence['text'], 'Dialogue sentence text', 5_000);
            $reading = array_key_exists('reading', $rawSentence)
                ? self::nullableString($rawSentence['reading'], 'Dialogue sentence reading', 10_000)
                : null;

            $sentences[] = [
                'speaker' => $speaker,
                'text' => $text,
                'reading' => $targetLanguage === 'ja' ? ($reading ?? $text) : $reading,
                'translation' => self::string($rawSentence['translation'], 'Dialogue sentence translation', 5_000),
                'variations' => $normalizedVariations,
            ];
        }

        return new self($title, $sentences);
    }

    private static function string(mixed $value, string $label, int $max): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be a string.");
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $value;
    }

    private static function nullableString(mixed $value, string $label, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = self::string($value, $label, $max);

        return $value === '' ? null : $value;
    }
}
