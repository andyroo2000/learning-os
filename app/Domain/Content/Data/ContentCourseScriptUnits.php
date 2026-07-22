<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Results\ContentCourseScriptUnit;
use InvalidArgumentException;

final readonly class ContentCourseScriptUnits
{
    public const MAX_UNITS = 1000;

    /** @param non-empty-list<ContentCourseScriptUnit> $units */
    private function __construct(public array $units) {}

    public static function fromPayload(mixed $payload): self
    {
        if (! is_array($payload) || ! array_is_list($payload) || $payload === []) {
            throw new InvalidArgumentException('Course audio requires a generated script.');
        }
        if (count($payload) > self::MAX_UNITS) {
            throw new InvalidArgumentException('Course script contains too many units.');
        }

        $units = array_map(static function (mixed $unit): ContentCourseScriptUnit {
            if (! is_array($unit)) {
                throw new InvalidArgumentException('Course script unit must be an object.');
            }

            return ContentCourseScriptUnit::fromProvider($unit);
        }, $payload);

        return new self($units);
    }

    /** @return non-empty-list<array<string, float|string>> */
    public function payload(): array
    {
        return array_map(
            static fn (ContentCourseScriptUnit $unit): array => $unit->toArray(),
            $this->units,
        );
    }
}
