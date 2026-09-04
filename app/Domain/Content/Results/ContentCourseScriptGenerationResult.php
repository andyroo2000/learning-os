<?php

namespace App\Domain\Content\Results;

use App\Domain\Content\Data\ContentCourseScriptUnits;
use InvalidArgumentException;
use JsonException;

final readonly class ContentCourseScriptGenerationResult
{
    private const MAX_EXCHANGES = 100;

    private const MAX_VOCABULARY_ITEMS = 500;

    /**
     * @param  list<array<string, mixed>>  $exchanges
     * @param  list<ContentCourseScriptUnit>  $units
     * @param  list<array{textL2: string, readingL2: ?string, translationL1: string, complexityScore: float, sourceUnitIndex: int, components: ?array<mixed>}>  $coreItems
     */
    private function __construct(
        public array $exchanges,
        public array $units,
        public array $coreItems,
        public int $estimatedDurationSeconds,
    ) {}

    public static function fromProviderJson(string $json): self
    {
        $decoded = self::decodedResponse($json);
        $rawExchanges = self::boundedList($decoded, 'exchanges', 1, self::MAX_EXCHANGES);
        $rawUnits = self::boundedList($decoded, 'scriptUnits', 1, ContentCourseScriptUnits::MAX_UNITS);
        [$exchanges, $coreItems] = self::exchanges($rawExchanges);
        $units = self::scriptUnits($rawUnits);
        $duration = (int) round(array_sum(array_map(
            static fn (ContentCourseScriptUnit $unit): float => $unit->estimatedDurationSeconds(),
            $units,
        )));

        return new self($exchanges, $units, $coreItems, max(1, $duration));
    }

    /** @return array<string, mixed> */
    private static function decodedResponse(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Course generator returned invalid JSON.', 0, $exception);
        }
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Course generator response must be an object.');
        }
        self::assertExactKeys($decoded, ['exchanges', 'scriptUnits'], 'response');

        return $decoded;
    }

    /**
     * @param  list<mixed>  $rawExchanges
     * @return array{list<array<string, mixed>>, list<array{textL2: string, readingL2: ?string, translationL1: string, complexityScore: float, sourceUnitIndex: int, components: ?array<mixed>}>}
     */
    private static function exchanges(array $rawExchanges): array
    {
        $exchanges = [];
        $coreItems = [];

        foreach ($rawExchanges as $exchangeIndex => $rawExchange) {
            if (! is_array($rawExchange)) {
                throw new InvalidArgumentException('Course exchange must be an object.');
            }
            $exchange = self::exchange($rawExchange);
            $exchanges[] = $exchange;
            self::appendCoreItems($coreItems, $exchange['vocabularyItems'], $exchangeIndex);
        }

        return [$exchanges, $coreItems];
    }

    /**
     * @param  list<array{textL2: string, readingL2: ?string, translationL1: string, complexityScore: float, sourceUnitIndex: int, components: ?array<mixed>}>  $coreItems
     * @param  list<array{textL2: string, readingL2: ?string, translationL1: string, complexityScore: float, components: ?array<mixed>}>  $items
     */
    private static function appendCoreItems(array &$coreItems, array $items, int $exchangeIndex): void
    {
        foreach ($items as $item) {
            if (count($coreItems) >= self::MAX_VOCABULARY_ITEMS) {
                throw new InvalidArgumentException('Course generator returned too many vocabulary items.');
            }
            $coreItems[] = [
                'textL2' => $item['textL2'],
                'readingL2' => $item['readingL2'],
                'translationL1' => $item['translationL1'],
                'complexityScore' => $item['complexityScore'],
                'sourceUnitIndex' => $exchangeIndex,
                'components' => $item['components'],
            ];
        }
    }

    /**
     * @param  list<mixed>  $rawUnits
     * @return list<ContentCourseScriptUnit>
     */
    private static function scriptUnits(array $rawUnits): array
    {
        return array_map(static function (mixed $unit): ContentCourseScriptUnit {
            if (! is_array($unit)) {
                throw new InvalidArgumentException('Course script unit must be an object.');
            }

            return ContentCourseScriptUnit::fromProvider($unit);
        }, $rawUnits);
    }

    /** @return array{_pipelineStage: string, _exchanges: list<array<string, mixed>>, _scriptUnits: list<array<string, float|string>>} */
    public function pipelinePayload(): array
    {
        return [
            '_pipelineStage' => 'script',
            '_exchanges' => $this->exchanges,
            '_scriptUnits' => $this->scriptUnitsPayload(),
        ];
    }

    /** @return list<array<string, float|string>> */
    public function scriptUnitsPayload(): array
    {
        return array_map(
            static fn (ContentCourseScriptUnit $unit): array => $unit->toArray(),
            $this->units,
        );
    }

    /** @param array<string, mixed> $input
     * @return list<mixed>
     */
    private static function boundedList(array $input, string $key, int $min, int $max): array
    {
        $value = $input[$key] ?? null;
        if (! self::isBoundedList($value, $min, $max)) {
            throw new InvalidArgumentException("Course generator {$key} count is invalid.");
        }

        return $value;
    }

    private static function isBoundedList(mixed $value, int $min, int $max): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        return count($value) >= $min && count($value) <= $max;
    }

    /** @param array<string, mixed> $input
     *  @return array{speakerName: string, speakerVoiceId: string, textL2: string, readingL2: ?string, translationL1: string, vocabularyItems: list<array{textL2: string, readingL2: ?string, translationL1: string, complexityScore: float, components: ?array<mixed>}>>}
     */
    private static function exchange(array $input): array
    {
        self::assertExactKeys($input, [
            'speakerName', 'speakerVoiceId', 'textL2', 'readingL2',
            'translationL1', 'vocabularyItems',
        ], 'exchange');
        $rawVocabulary = $input['vocabularyItems'] ?? null;
        if (! self::isBoundedList($rawVocabulary, 0, 10)) {
            throw new InvalidArgumentException('Course exchange vocabulary must be a bounded list.');
        }

        return [
            'speakerName' => self::string($input, 'speakerName', 255),
            'speakerVoiceId' => self::string($input, 'speakerVoiceId', 255),
            'textL2' => self::string($input, 'textL2', 4000),
            'readingL2' => self::nullableString($input, 'readingL2', 4000),
            'translationL1' => self::string($input, 'translationL1', 4000),
            'vocabularyItems' => array_map(self::vocabularyItem(...), $rawVocabulary),
        ];
    }

    /** @return array{textL2: string, readingL2: ?string, translationL1: string, complexityScore: float, components: ?array<mixed>} */
    private static function vocabularyItem(mixed $item): array
    {
        if (! is_array($item)) {
            throw new InvalidArgumentException('Course vocabulary item must be an object.');
        }
        self::assertExactKeys($item, [
            'textL2', 'readingL2', 'translationL1', 'complexityScore', 'components',
        ], 'vocabulary item');
        $score = self::complexityScore($item['complexityScore'] ?? null);
        $components = self::components($item['components'] ?? null);

        return [
            'textL2' => self::string($item, 'textL2', 1000),
            'readingL2' => self::nullableString($item, 'readingL2', 1000),
            'translationL1' => self::string($item, 'translationL1', 1000),
            'complexityScore' => $score,
            'components' => $components,
        ];
    }

    private static function complexityScore(mixed $score): float
    {
        self::assertComplexityScoreType($score);
        $score = (float) $score;
        if (! is_finite($score)) {
            throw new InvalidArgumentException('Course vocabulary complexity score is invalid.');
        }
        if ($score < 0) {
            throw new InvalidArgumentException('Course vocabulary complexity score is invalid.');
        }
        if ($score > 100000) {
            throw new InvalidArgumentException('Course vocabulary complexity score is invalid.');
        }

        return $score;
    }

    private static function assertComplexityScoreType(mixed $score): void
    {
        if (! in_array(gettype($score), ['integer', 'double'], true)) {
            throw new InvalidArgumentException('Course vocabulary complexity score is invalid.');
        }
    }

    /** @return ?array<mixed> */
    private static function components(mixed $components): ?array
    {
        if ($components !== null && ! is_array($components)) {
            throw new InvalidArgumentException('Course vocabulary components are invalid.');
        }
        if (is_array($components) && count($components) > 50) {
            throw new InvalidArgumentException('Course vocabulary components are invalid.');
        }
        $componentNodes = 0;
        self::assertBoundedJson($components, 0, $componentNodes);

        return $components;
    }

    /** @param array<string, mixed> $input */
    private static function string(array $input, string $key, int $max): string
    {
        $value = self::nullableString($input, $key, $max);
        if ($value === null) {
            throw new InvalidArgumentException("Course generator {$key} is required.");
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function nullableString(array $input, string $key, int $max): ?string
    {
        if (! array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }
        if (! is_string($input[$key])) {
            throw new InvalidArgumentException("Course generator {$key} must be a string.");
        }
        $value = trim($input[$key]);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException("Course generator {$key} is invalid.");
        }

        return $value;
    }

    /** @param array<string, mixed> $input
     * @param  list<string>  $allowed
     */
    private static function assertExactKeys(array $input, array $allowed, string $label): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new InvalidArgumentException("Course generator {$label} contains unsupported fields.");
        }
    }

    private static function assertBoundedJson(mixed $value, int $depth, int &$nodes): void
    {
        $nodes++;
        if ($depth > 5) {
            throw new InvalidArgumentException('Course vocabulary components are too complex.');
        }
        if ($nodes > 200) {
            throw new InvalidArgumentException('Course vocabulary components are too complex.');
        }
        self::assertComponentValue($value);

        if (is_array($value)) {
            self::assertBoundedChildren($value, $depth, $nodes);
        }
    }

    private static function assertComponentValue(mixed $value): void
    {
        if (is_string($value)) {
            self::assertComponentText($value);

            return;
        }
        if (is_float($value)) {
            self::assertComponentNumber($value);

            return;
        }
        if (is_array($value)) {
            return;
        }
        if (! in_array(gettype($value), ['NULL', 'string', 'integer', 'double', 'boolean'], true)) {
            throw new InvalidArgumentException('Course vocabulary components contain an invalid value.');
        }
    }

    private static function assertComponentText(string $value): void
    {
        if (mb_strlen($value) > 4000) {
            throw new InvalidArgumentException('Course vocabulary component text is too long.');
        }
    }

    private static function assertComponentNumber(float $value): void
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('Course vocabulary components contain an invalid number.');
        }
    }

    /** @param array<mixed> $value */
    private static function assertBoundedChildren(array $value, int $depth, int &$nodes): void
    {
        foreach ($value as $key => $child) {
            if (is_string($key) && mb_strlen($key) > 255) {
                throw new InvalidArgumentException('Course vocabulary component key is too long.');
            }
            self::assertBoundedJson($child, $depth + 1, $nodes);
        }
    }
}
