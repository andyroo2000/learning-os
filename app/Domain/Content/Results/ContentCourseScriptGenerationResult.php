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
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Course generator returned invalid JSON.', 0, $exception);
        }
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Course generator response must be an object.');
        }
        self::assertExactKeys($decoded, ['exchanges', 'scriptUnits'], 'response');

        $rawExchanges = self::boundedList($decoded, 'exchanges', 1, self::MAX_EXCHANGES);
        $rawUnits = self::boundedList($decoded, 'scriptUnits', 1, ContentCourseScriptUnits::MAX_UNITS);

        $exchanges = [];
        $coreItems = [];
        foreach ($rawExchanges as $exchangeIndex => $rawExchange) {
            if (! is_array($rawExchange)) {
                throw new InvalidArgumentException('Course exchange must be an object.');
            }
            $exchange = self::exchange($rawExchange);
            $exchanges[] = $exchange;

            foreach ($exchange['vocabularyItems'] as $item) {
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

        $units = array_map(static function (mixed $unit): ContentCourseScriptUnit {
            if (! is_array($unit)) {
                throw new InvalidArgumentException('Course script unit must be an object.');
            }

            return ContentCourseScriptUnit::fromProvider($unit);
        }, $rawUnits);

        $duration = (int) round(array_sum(array_map(
            static fn (ContentCourseScriptUnit $unit): float => $unit->estimatedDurationSeconds(),
            $units,
        )));

        return new self($exchanges, $units, $coreItems, max(1, $duration));
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
        if (! is_array($value) || ! array_is_list($value) || count($value) < $min || count($value) > $max) {
            throw new InvalidArgumentException("Course generator {$key} count is invalid.");
        }

        return $value;
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
        if (! is_array($rawVocabulary) || ! array_is_list($rawVocabulary) || count($rawVocabulary) > 10) {
            throw new InvalidArgumentException('Course exchange vocabulary must be a bounded list.');
        }

        return [
            'speakerName' => self::string($input, 'speakerName', 255),
            'speakerVoiceId' => self::string($input, 'speakerVoiceId', 255),
            'textL2' => self::string($input, 'textL2', 4000),
            'readingL2' => self::nullableString($input, 'readingL2', 4000),
            'translationL1' => self::string($input, 'translationL1', 4000),
            'vocabularyItems' => array_map(static function (mixed $item): array {
                if (! is_array($item)) {
                    throw new InvalidArgumentException('Course vocabulary item must be an object.');
                }
                self::assertExactKeys($item, [
                    'textL2', 'readingL2', 'translationL1', 'complexityScore', 'components',
                ], 'vocabulary item');
                $score = $item['complexityScore'] ?? null;
                if ((! is_int($score) && ! is_float($score)) || ! is_finite((float) $score)
                    || $score < 0 || $score > 100000) {
                    throw new InvalidArgumentException('Course vocabulary complexity score is invalid.');
                }
                $components = $item['components'] ?? null;
                if ($components !== null && (! is_array($components) || count($components) > 50)) {
                    throw new InvalidArgumentException('Course vocabulary components are invalid.');
                }
                $componentNodes = 0;
                self::assertBoundedJson($components, 0, $componentNodes);

                return [
                    'textL2' => self::string($item, 'textL2', 1000),
                    'readingL2' => self::nullableString($item, 'readingL2', 1000),
                    'translationL1' => self::string($item, 'translationL1', 1000),
                    'complexityScore' => (float) $score,
                    'components' => $components,
                ];
            }, $rawVocabulary),
        ];
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
        if ($depth > 5 || $nodes > 200) {
            throw new InvalidArgumentException('Course vocabulary components are too complex.');
        }
        if (is_string($value) && mb_strlen($value) > 4000) {
            throw new InvalidArgumentException('Course vocabulary component text is too long.');
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Course vocabulary components contain an invalid number.');
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key) && mb_strlen($key) > 255) {
                    throw new InvalidArgumentException('Course vocabulary component key is too long.');
                }
                self::assertBoundedJson($child, $depth + 1, $nodes);
            }

            return;
        }
        if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
            throw new InvalidArgumentException('Course vocabulary components contain an invalid value.');
        }
    }
}
