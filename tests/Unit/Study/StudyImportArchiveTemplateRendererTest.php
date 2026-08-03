<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyImportArchiveTemplateRenderer;
use PHPUnit\Framework\TestCase;

class StudyImportArchiveTemplateRendererTest extends TestCase
{
    public function test_it_renders_template_text_as_plain_card_sides(): void
    {
        $renderer = new StudyImportArchiveTemplateRenderer;

        $rendered = $renderer->render(
            [
                'name' => 'Basic',
                'fields' => ['Front', 'Back', 'Hint'],
                'templates' => [
                    0 => [
                        'name' => 'Card 1',
                        'front' => '{{Front}}{{#Hint}} ({{Hint}}){{/Hint}}{{^Back}} missing{{/Back}}',
                        'back' => '{{FrontSide}}<hr id="answer">{{Back}} [sound:answer.mp3]',
                    ],
                ],
            ],
            0,
            '会社[sound:word.mp3]'."\x1f".'<img src="company.png"> company'."\x1f".'hint',
        );

        $this->assertSame('会社 (hint)', $rendered['front']);
        $this->assertSame('会社 (hint) company', $rendered['back']);
        $this->assertSame(['word.mp3'], $rendered['front_media_references']);

        $renderedWithoutBack = $renderer->render(
            [
                'name' => 'Basic',
                'fields' => ['Front', 'Back', 'Hint'],
                'templates' => [
                    0 => [
                        'name' => 'Card 1',
                        'front' => '{{Front}}{{^Back}} missing{{/Back}}',
                        'back' => '{{FrontSide}}',
                    ],
                ],
            ],
            0,
            '会社'."\x1f"."\x1f".'hint',
        );

        $this->assertSame('会社 missing', $renderedWithoutBack['front']);
        $this->assertSame('会社 missing', $renderedWithoutBack['back']);
        $this->assertSame([], $renderedWithoutBack['front_media_references']);
    }

    public function test_it_renders_cloze_text(): void
    {
        $renderer = new StudyImportArchiveTemplateRenderer;

        $rendered = $renderer->render(
            [
                'name' => 'Cloze',
                'fields' => ['Text'],
                'templates' => [
                    0 => [
                        'name' => 'Cloze',
                        'front' => '{{cloze:Text}}',
                        'back' => '{{cloze:Text}}',
                    ],
                ],
            ],
            0,
            '{{c1::漢字::kanji}}',
        );

        $this->assertSame('漢字', $rendered['front']);
        $this->assertSame('漢字', $rendered['back']);
        $this->assertSame([], $rendered['front_media_references']);
    }

    public function test_it_preserves_front_audio_references_when_plain_text_falls_back_to_another_field(): void
    {
        $renderer = new StudyImportArchiveTemplateRenderer;

        $rendered = $renderer->render(
            [
                'name' => 'Japanese - Listening',
                'fields' => ['Expression', 'AudioWord'],
                'templates' => [
                    0 => [
                        'name' => 'Listening',
                        'front' => '{{AudioWord}}',
                        'back' => '{{Expression}}',
                    ],
                ],
            ],
            0,
            '家内は今出かけています。'."\x1f".'[sound:unit01-example01.mp3]',
        );

        $this->assertSame('家内は今出かけています。', $rendered['front']);
        $this->assertSame('家内は今出かけています。', $rendered['back']);
        $this->assertSame(['unit01-example01.mp3'], $rendered['front_media_references']);
    }
}
