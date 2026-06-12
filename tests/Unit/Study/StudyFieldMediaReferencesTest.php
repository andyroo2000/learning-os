<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyFieldMediaReferences;
use Tests\TestCase;

class StudyFieldMediaReferencesTest extends TestCase
{
    public function test_it_extracts_anki_sound_and_image_references_from_text(): void
    {
        $this->assertSame(
            [
                [
                    'filename' => 'word & tone.mp3',
                    'mediaKind' => 'audio',
                    'source' => 'imported',
                ],
                [
                    'filename' => 'company & office.png',
                    'mediaKind' => 'image',
                    'source' => 'imported_image',
                ],
            ],
            StudyFieldMediaReferences::fromText(
                '会社 [sound: word &amp; tone.mp3 ] <img alt="Company" src="company &amp; office.png">',
            ),
        );
    }

    public function test_it_extracts_unquoted_image_sources_and_ignores_empty_references(): void
    {
        $this->assertSame(
            [
                [
                    'filename' => 'company.png',
                    'mediaKind' => 'image',
                    'source' => 'imported_image',
                ],
            ],
            StudyFieldMediaReferences::fromText('[sound:   ] <img src=company.png>'),
        );
    }

    public function test_it_returns_first_matching_media_reference_from_values(): void
    {
        $audio = [
            'id' => 'audio-1',
            'filename' => 'word.mp3',
            'url' => '/api/study/media/audio-1',
            'mediaKind' => 'audio',
            'source' => 'generated',
            'extra' => 'ignored',
        ];
        $image = [
            'id' => 'image-1',
            'filename' => 'company.png',
            'url' => '/api/study/media/image-1',
            'mediaKind' => 'image',
            'source' => 'imported_image',
        ];

        $this->assertSame(
            [
                'id' => 'audio-1',
                'filename' => 'word.mp3',
                'url' => '/api/study/media/audio-1',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            StudyFieldMediaReferences::audioFromValue($audio),
        );
        $this->assertNull(StudyFieldMediaReferences::imageFromValue($audio));
        $this->assertSame($image, StudyFieldMediaReferences::imageFromValue($image));
        $this->assertSame(
            [
                'filename' => 'legacy.mp3',
                'mediaKind' => 'audio',
                'source' => 'imported',
            ],
            StudyFieldMediaReferences::audioFromValue('[sound:legacy.mp3]'),
        );
        $this->assertSame(
            [
                'filename' => 'legacy.png',
                'mediaKind' => 'image',
                'source' => 'imported_image',
            ],
            StudyFieldMediaReferences::imageFromValue('<img src="legacy.png">'),
        );
    }

    public function test_it_ignores_malformed_typed_media_references(): void
    {
        $this->assertNull(StudyFieldMediaReferences::audioFromValue([
            'filename' => 'word.mp3',
            'mediaKind' => 'audio',
            'source' => 'unknown',
        ]));
        $this->assertNull(StudyFieldMediaReferences::audioFromValue([
            'filename' => 'word.mp3',
            'mediaKind' => 'audio',
            'source' => 'generated',
            'url' => ['not-a-string'],
        ]));
        $this->assertNull(StudyFieldMediaReferences::imageFromValue([
            'filename' => 'company.png',
            'mediaKind' => 'audio',
            'source' => 'imported',
        ]));
    }
}
