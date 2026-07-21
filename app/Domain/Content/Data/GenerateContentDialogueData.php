<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentEpisodeId;
use InvalidArgumentException;

final readonly class GenerateContentDialogueData
{
    private const PROFICIENCIES = [
        'beginner', 'intermediate', 'advanced', 'native', 'N5', 'N4', 'N3', 'N2', 'N1',
    ];

    private const TONES = ['casual', 'polite', 'formal', 'neutral'];

    /** @param list<array{name: string, voiceId: string, proficiency: string, tone: string, color: string|null}> $speakers */
    private function __construct(
        public string $episodeId,
        public array $speakers,
        public int $variationCount,
        public int $dialogueLength,
        public ?string $jlptLevel,
        public ?string $vocabSeedOverride,
        public ?string $grammarSeedOverride,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $episodeId = ContentEpisodeId::normalize(self::requiredString($input, 'episodeId', 64));
        $speakers = self::speakers($input['speakers'] ?? null);
        $variationCount = self::boundedInteger($input['variationCount'] ?? 3, 'Dialogue variation count', 1, 5);
        $dialogueLength = self::boundedInteger($input['dialogueLength'] ?? 6, 'Dialogue length', 2, 20);
        $jlptLevel = self::nullableString($input['jlptLevel'] ?? null, 'Dialogue JLPT level', 8);
        if ($jlptLevel !== null && ! in_array($jlptLevel, ['N5', 'N4', 'N3', 'N2', 'N1'], true)) {
            throw new InvalidArgumentException('Dialogue JLPT level is invalid.');
        }

        return new self(
            episodeId: $episodeId,
            speakers: $speakers,
            variationCount: $variationCount,
            dialogueLength: $dialogueLength,
            jlptLevel: $jlptLevel,
            vocabSeedOverride: self::nullableString(
                $input['vocabSeedOverride'] ?? null,
                'Dialogue vocabulary seed override',
                10_000,
            ),
            grammarSeedOverride: self::nullableString(
                $input['grammarSeedOverride'] ?? null,
                'Dialogue grammar seed override',
                10_000,
            ),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'episodeId' => $this->episodeId,
            'speakers' => $this->speakers,
            'variationCount' => $this->variationCount,
            'dialogueLength' => $this->dialogueLength,
            'jlptLevel' => $this->jlptLevel,
            'vocabSeedOverride' => $this->vocabSeedOverride,
            'grammarSeedOverride' => $this->grammarSeedOverride,
        ];
    }

    /** @return list<array{name: string, voiceId: string, proficiency: string, tone: string, color: string|null}> */
    private static function speakers(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 2) {
            throw new InvalidArgumentException('Dialogue generation requires exactly two speakers.');
        }

        $speakers = [];
        foreach ($value as $speaker) {
            if (! is_array($speaker) || array_diff(array_keys($speaker), ['name', 'voiceId', 'proficiency', 'tone', 'color']) !== []) {
                throw new InvalidArgumentException('Dialogue speaker is invalid.');
            }
            $proficiency = self::requiredString($speaker, 'proficiency', 32);
            $tone = self::requiredString($speaker, 'tone', 32);
            if (! in_array($proficiency, self::PROFICIENCIES, true)) {
                throw new InvalidArgumentException('Dialogue speaker proficiency is invalid.');
            }
            if (! in_array($tone, self::TONES, true)) {
                throw new InvalidArgumentException('Dialogue speaker tone is invalid.');
            }
            $color = self::nullableString($speaker['color'] ?? null, 'Dialogue speaker color', 32);
            if ($color !== null && preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
                throw new InvalidArgumentException('Dialogue speaker color is invalid.');
            }

            $speakers[] = [
                'name' => self::requiredString($speaker, 'name', 100),
                'voiceId' => self::requiredString($speaker, 'voiceId', 255),
                'proficiency' => $proficiency,
                'tone' => $tone,
                'color' => $color,
            ];
        }

        $names = array_map(
            static fn (array $speaker): string => mb_strtolower(self::promptName($speaker['name'])),
            $speakers,
        );
        if ($names[0] === '' || $names[1] === '' || $names[0] === $names[1]) {
            throw new InvalidArgumentException('Dialogue speaker names must be distinct.');
        }

        return $speakers;
    }

    /** @param array<string, mixed> $input */
    private static function requiredString(array $input, string $key, int $max): string
    {
        $value = $input[$key] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$key} must be a string.");
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$key} is invalid.");
        }

        return $value;
    }

    private static function nullableString(mixed $value, string $label, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be a string or null.");
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$label} is too long.");
        }

        return $value === '' ? null : $value;
    }

    private static function boundedInteger(mixed $value, string $label, int $min, int $max): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $value;
    }

    public static function promptName(string $name): string
    {
        return trim((string) preg_replace('/\[[^\]]+\]/u', '', $name));
    }
}
