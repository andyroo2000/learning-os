<?php

namespace App\Domain\Admin\Data;

use InvalidArgumentException;

final readonly class AdminCourseExchangeCollection
{
    private const MAX_EXCHANGES = 100;

    private const MAX_VOCABULARY_PER_EXCHANGE = 20;

    /**
     * @param  list<array<string, mixed>>  $exchanges
     * @param  list<array{textL2: string, readingL2: ?string, translationL1: string, complexityScore: int}>  $coreItems
     */
    private function __construct(
        public array $exchanges,
        public array $coreItems,
    ) {}

    public static function fromPipeline(mixed $scriptJson): self
    {
        if (! is_array($scriptJson)
            || ($scriptJson['_pipelineStage'] ?? null) !== 'exchanges'
            || ! is_array($scriptJson['_exchanges'] ?? null)
            || ! array_is_list($scriptJson['_exchanges'])
            || $scriptJson['_exchanges'] === []
            || count($scriptJson['_exchanges']) > self::MAX_EXCHANGES) {
            throw new InvalidArgumentException('No dialogue exchanges found. Generate dialogue first.');
        }

        $exchanges = [];
        $coreItems = [];
        foreach ($scriptJson['_exchanges'] as $rawExchange) {
            if (! is_array($rawExchange) || array_is_list($rawExchange)) {
                throw new InvalidArgumentException('Saved dialogue exchange is invalid.');
            }

            $vocabulary = self::vocabulary($rawExchange['vocabularyItems'] ?? []);
            $speakerName = self::string($rawExchange['speakerName'] ?? null, 'Saved dialogue speaker', 100);
            $exchange = [
                'order' => self::integer($rawExchange['order'] ?? null, 'Saved dialogue order'),
                'speakerName' => $speakerName,
                'relationshipName' => self::optionalString(
                    $rawExchange['relationshipName'] ?? null,
                    'Saved dialogue relationship',
                    255,
                ) ?? $speakerName,
                'speakerVoiceId' => self::string(
                    $rawExchange['speakerVoiceId'] ?? null,
                    'Saved dialogue voice',
                    255,
                ),
                'textL2' => self::string($rawExchange['textL2'] ?? null, 'Saved dialogue text', 5_000),
                'readingL2' => self::optionalString(
                    $rawExchange['readingL2'] ?? null,
                    'Saved dialogue reading',
                    10_000,
                ),
                'translationL1' => self::string(
                    $rawExchange['translationL1'] ?? null,
                    'Saved dialogue translation',
                    5_000,
                ),
                'vocabularyItems' => $vocabulary,
            ];
            $exchanges[] = $exchange;

            foreach ($vocabulary as $item) {
                $coreItems[] = [
                    'textL2' => $item['textL2'],
                    'readingL2' => $item['readingL2'] ?? null,
                    'translationL1' => $item['translationL1'],
                    'complexityScore' => count($coreItems),
                ];
            }
        }

        return new self($exchanges, $coreItems);
    }

    /** @return list<string> */
    public function speakerVoiceIds(): array
    {
        return array_values(array_unique(array_column($this->exchanges, 'speakerVoiceId')));
    }

    /** @return list<array{textL2: string, readingL2?: string, translationL1: string, jlptLevel?: string}> */
    private static function vocabulary(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)
            || count($value) > self::MAX_VOCABULARY_PER_EXCHANGE) {
            throw new InvalidArgumentException('Saved dialogue vocabulary is invalid.');
        }

        $items = [];
        foreach ($value as $rawItem) {
            if (! is_array($rawItem) || array_is_list($rawItem)) {
                throw new InvalidArgumentException('Saved dialogue vocabulary item is invalid.');
            }
            $item = [
                'textL2' => self::string($rawItem['textL2'] ?? null, 'Saved vocabulary text', 1_000),
                'translationL1' => self::string(
                    $rawItem['translationL1'] ?? null,
                    'Saved vocabulary translation',
                    2_000,
                ),
            ];
            $reading = self::optionalString($rawItem['readingL2'] ?? null, 'Saved vocabulary reading', 2_000);
            $level = self::optionalString($rawItem['jlptLevel'] ?? null, 'Saved vocabulary JLPT level', 8);
            if ($reading !== null) {
                $item['readingL2'] = $reading;
            }
            if ($level !== null) {
                $item['jlptLevel'] = $level;
            }
            $items[] = $item;
        }

        return $items;
    }

    private static function string(mixed $value, string $label, int $max): string
    {
        $value = self::optionalString($value, $label, $max);
        if ($value === null) {
            throw new InvalidArgumentException("{$label} is required.");
        }

        return $value;
    }

    private static function optionalString(mixed $value, string $label, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be a string or null.");
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $value;
    }

    private static function integer(mixed $value, string $label): int
    {
        if (! is_int($value) || $value < 0 || $value > self::MAX_EXCHANGES) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $value;
    }
}
