<?php

namespace Tests\Support\Reviews;

use App\Domain\Vocabulary\Enums\VocabVariantStatus;

final readonly class CardProgressionFixtureStage
{
    public function __construct(
        public int $number,
        public VocabVariantStatus $status,
    ) {}
}
