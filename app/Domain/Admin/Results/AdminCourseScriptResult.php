<?php

namespace App\Domain\Admin\Results;

use App\Domain\Content\Results\ContentCourseScriptUnit;

final readonly class AdminCourseScriptResult
{
    /** @param list<ContentCourseScriptUnit> $units */
    private function __construct(
        public array $units,
        public int $estimatedDurationSeconds,
    ) {}

    /** @param list<string> $speakerVoiceIds */
    public static function fromJson(
        string $json,
        string $narratorVoiceId,
        array $speakerVoiceIds,
        string $targetLanguage,
        int $maximumDurationSeconds,
    ): self {
        $parsed = (new AdminCourseScriptResultParser(
            $json,
            new AdminCourseScriptConstraints(
                $narratorVoiceId,
                $speakerVoiceIds,
                $targetLanguage,
                $maximumDurationSeconds,
            ),
        ))->parse();

        return new self($parsed->units, $parsed->estimatedDurationSeconds);
    }

    /** @return list<array<string, float|string>> */
    public function payload(): array
    {
        return array_map(
            static fn (ContentCourseScriptUnit $unit): array => $unit->toArray(),
            $this->units,
        );
    }
}
