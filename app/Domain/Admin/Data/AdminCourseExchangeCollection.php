<?php

namespace App\Domain\Admin\Data;

use App\Domain\Admin\Support\AdminCoursePipelineMessage;
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
        $rawExchanges = self::pipelineExchanges($scriptJson);

        $exchanges = [];
        $coreItems = [];
        foreach ($rawExchanges as $rawExchange) {
            $rawExchange = self::exchangeObject($rawExchange);
            $vocabulary = self::vocabulary($rawExchange['vocabularyItems'] ?? []);
            $exchanges[] = self::exchange($rawExchange, $vocabulary);
            self::appendCoreItems($coreItems, $vocabulary);
        }

        return new self($exchanges, $coreItems);
    }

    /** @return list<mixed> */
    private static function pipelineExchanges(mixed $scriptJson): array
    {
        if (! is_array($scriptJson)) {
            self::invalidPipeline();
        }
        if (($scriptJson['_pipelineStage'] ?? null) !== 'exchanges') {
            self::invalidPipeline();
        }

        $exchanges = $scriptJson['_exchanges'] ?? null;
        if (! is_array($exchanges)) {
            self::invalidPipeline();
        }
        if (! array_is_list($exchanges)) {
            self::invalidPipeline();
        }
        if ($exchanges === []) {
            self::invalidPipeline();
        }
        if (count($exchanges) > self::MAX_EXCHANGES) {
            self::invalidPipeline();
        }

        return $exchanges;
    }

    private static function invalidPipeline(): never
    {
        throw new InvalidArgumentException(AdminCoursePipelineMessage::DIALOGUE_EXCHANGES_REQUIRED);
    }

    /** @return array<string, mixed> */
    private static function exchangeObject(mixed $rawExchange): array
    {
        if (! is_array($rawExchange) || array_is_list($rawExchange)) {
            throw new InvalidArgumentException('Saved dialogue exchange is invalid.');
        }

        return $rawExchange;
    }

    /**
     * @param  array<string, mixed>  $rawExchange
     * @param  list<array{textL2: string, readingL2?: string, translationL1: string, jlptLevel?: string}>  $vocabulary
     * @return array<string, mixed>
     */
    private static function exchange(array $rawExchange, array $vocabulary): array
    {
        $speakerName = self::string($rawExchange['speakerName'] ?? null, 'Saved dialogue speaker', 100);

        return [
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
    }

    /**
     * @param  list<array{textL2: string, readingL2: ?string, translationL1: string, complexityScore: int}>  $coreItems
     * @param  list<array{textL2: string, readingL2?: string, translationL1: string, jlptLevel?: string}>  $vocabulary
     */
    private static function appendCoreItems(array &$coreItems, array $vocabulary): void
    {
        foreach ($vocabulary as $item) {
            // Legacy stores insertion order here as a placeholder, not a difficulty estimate.
            $coreItems[] = [
                'textL2' => $item['textL2'],
                'readingL2' => $item['readingL2'] ?? null,
                'translationL1' => $item['translationL1'],
                'complexityScore' => count($coreItems),
            ];
        }
    }

    /** @return list<string> */
    public function speakerVoiceIds(): array
    {
        return array_values(array_unique(array_column($this->exchanges, 'speakerVoiceId')));
    }

    /** @return list<array{textL2: string, readingL2?: string, translationL1: string, jlptLevel?: string}> */
    private static function vocabulary(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Saved dialogue vocabulary is invalid.');
        }
        if (! array_is_list($value)) {
            throw new InvalidArgumentException('Saved dialogue vocabulary is invalid.');
        }
        if (count($value) > self::MAX_VOCABULARY_PER_EXCHANGE) {
            throw new InvalidArgumentException('Saved dialogue vocabulary is invalid.');
        }

        $items = [];
        foreach ($value as $rawItem) {
            $items[] = self::vocabularyItem($rawItem);
        }

        return $items;
    }

    /** @return array{textL2: string, readingL2?: string, translationL1: string, jlptLevel?: string} */
    private static function vocabularyItem(mixed $rawItem): array
    {
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

        return $item;
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
        if (! is_int($value)) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }
        if ($value < 0) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }
        if ($value > self::MAX_EXCHANGES) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $value;
    }
}
