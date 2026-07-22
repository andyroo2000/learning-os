<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Models\AdminPronunciationDictionary;
use App\Domain\Admin\Services\AdminJapanesePronunciationOverrides;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminJapanesePronunciationOverridesTest extends TestCase
{
    use RefreshDatabase;

    public function test_plain_readings_and_exact_force_mappings_follow_dictionary_precedence(): void
    {
        $service = app(AdminJapanesePronunciationOverrides::class);

        $this->assertSame('ほっかいどう', $service->apply('北海道', 'invalid reading'));
        $this->assertSame('べんきょう', $service->apply('勉強', 'べんきょう'));
        $this->assertSame('ほっかいどうと日本', $service->apply('北海道と日本'));
    }

    public function test_bracket_readings_collapse_valid_overlap_without_collapsing_a_particle(): void
    {
        $service = app(AdminJapanesePronunciationOverrides::class);

        $this->assertSame(
            'かいもの',
            $service->apply('買い物', '買[か]い物[かいもの]', '買[か]い物[かいもの]'),
        );
        $this->assertSame(
            'かか',
            $service->apply('か買', 'か買[か]', 'か買[か]'),
        );
    }

    public function test_derived_godan_forms_use_the_verb_dictionary_without_overriding_explicit_entries(): void
    {
        $dictionary = AdminPronunciationDictionary::query()->findOrFail('ja');
        $dictionary->keep_kanji = [];
        $dictionary->force_kana = ['話し' => 'explicit'];
        $dictionary->verb_kana = ['話す' => 'はなす'];
        $dictionary->save();

        $service = app(AdminJapanesePronunciationOverrides::class);

        $this->assertSame('explicit', $service->apply('話し'));
        $this->assertSame('はなせ', $service->apply('話せ'));
    }

    public function test_oversized_text_bypasses_dictionary_loading_and_returns_the_original_text(): void
    {
        AdminPronunciationDictionary::query()->delete();
        $text = str_repeat('日', 10_001);

        $this->assertSame($text, app(AdminJapanesePronunciationOverrides::class)->apply($text, 'reading'));
    }
}
