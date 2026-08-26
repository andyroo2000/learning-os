<?php

namespace Tests\Unit\Flashcards;

use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Enums\CardSourceKind;
use PHPUnit\Framework\TestCase;

class CardIntroductionEnumTest extends TestCase
{
    public function test_source_kind_values_are_stable(): void
    {
        $this->assertSame([
            'manual',
            'bulk_import',
            'wanikani',
            'lesson_followup',
        ], array_column(CardSourceKind::cases(), 'value'));
    }

    public function test_selection_policy_values_are_stable(): void
    {
        $this->assertSame([
            'standard',
            'sprinkled',
            'review_soon',
        ], array_column(CardSelectionPolicy::cases(), 'value'));
    }
}
