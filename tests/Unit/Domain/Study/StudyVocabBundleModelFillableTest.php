<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Domain\Study\Models\StudyVocabVariantSentence;
use PHPUnit\Framework\TestCase;

class StudyVocabBundleModelFillableTest extends TestCase
{
    public function test_provider_generated_group_fields_are_not_mass_assignable(): void
    {
        $group = new StudyVocabVariantGroup;

        $this->assertContains('target_word', $group->getFillable());
        $this->assertNotContains('target_reading', $group->getFillable());
        $this->assertNotContains('target_meaning', $group->getFillable());
    }

    public function test_provider_generated_sentence_fields_are_not_mass_assignable(): void
    {
        $sentence = new StudyVocabVariantSentence;

        $this->assertSame([], $sentence->getFillable());
    }
}
