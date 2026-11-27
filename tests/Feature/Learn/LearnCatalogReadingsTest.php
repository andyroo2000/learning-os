<?php

namespace Tests\Feature\Learn;

use App\Domain\Learn\Support\LearnCatalog;
use App\Support\Japanese\BracketFurigana;
use Tests\TestCase;

class LearnCatalogReadingsTest extends TestCase
{
    public function test_all_catalog_text_has_complete_readings_including_explanations_and_questions(): void
    {
        $catalog = app(LearnCatalog::class)->all();
        $checked = 0;
        array_walk_recursive($catalog, function ($text) use (&$checked): void {
            if (! is_string($text)) {
                return;
            }
            $plain = preg_replace('/([\p{sc=Han}々〆]+)\[([ぁ-ゖァ-ヺー]+)\]/u', '$1', $text);
            $this->assertSame($text, BracketFurigana::validate($plain, $text));
            $checked++;
        });
        $this->assertGreaterThan(80, $checked);
    }
}
