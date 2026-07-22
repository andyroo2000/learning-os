<?php

namespace App\Domain\Admin\Data;

use InvalidArgumentException;
use JsonException;

final readonly class UpdateAdminCoursePipelineData
{
    private const MAX_EXCHANGES = 100;

    private const MAX_SCRIPT_UNITS = 1000;

    private const MAX_JSON_BYTES = 500000;

    private const MAX_JSON_DEPTH = 12;

    private const MAX_JSON_NODES = 10000;

    /** @param list<mixed> $data */
    private function __construct(
        public string $stage,
        public array $data,
    ) {}

    public static function fromInput(mixed $stage, mixed $data): self
    {
        $stage = is_string($stage) ? trim($stage) : $stage;
        if (! is_string($stage) || ! in_array($stage, ['exchanges', 'script'], true)) {
            throw new InvalidArgumentException('Invalid stage. Must be "exchanges" or "script"');
        }
        if (! is_array($data) || ! array_is_list($data)) {
            throw new InvalidArgumentException('Pipeline data must be a list.');
        }

        $max = $stage === 'exchanges' ? self::MAX_EXCHANGES : self::MAX_SCRIPT_UNITS;
        if (count($data) > $max) {
            throw new InvalidArgumentException('Pipeline data contains too many items.');
        }

        $nodes = 0;
        self::assertBoundedJson($data, 0, $nodes);
        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Pipeline data could not be encoded.', 0, $exception);
        }
        if (strlen($encoded) > self::MAX_JSON_BYTES) {
            throw new InvalidArgumentException('Pipeline data is too large.');
        }

        return new self($stage, $data);
    }

    private static function assertBoundedJson(mixed $value, int $depth, int &$nodes): void
    {
        $nodes++;
        if ($depth > self::MAX_JSON_DEPTH || $nodes > self::MAX_JSON_NODES) {
            throw new InvalidArgumentException('Pipeline data is too complex.');
        }
        if (is_string($value) && mb_strlen($value) > 10000) {
            throw new InvalidArgumentException('Pipeline data text is too long.');
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Pipeline data contains an invalid number.');
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key) && mb_strlen($key) > 255) {
                    throw new InvalidArgumentException('Pipeline data contains an invalid key.');
                }
                self::assertBoundedJson($child, $depth + 1, $nodes);
            }

            return;
        }
        if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
            throw new InvalidArgumentException('Pipeline data contains an invalid value.');
        }
    }
}
