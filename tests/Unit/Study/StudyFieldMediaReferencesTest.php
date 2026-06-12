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
                    'id' => null,
                    'filename' => 'word & tone.mp3',
                    'url' => null,
                    'mediaKind' => 'audio',
                    'source' => 'imported',
                ],
                [
                    'id' => null,
                    'filename' => 'company & office.png',
                    'url' => null,
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
                    'id' => null,
                    'filename' => 'company.png',
                    'url' => null,
                    'mediaKind' => 'image',
                    'source' => 'imported_image',
                ],
            ],
            StudyFieldMediaReferences::fromText('[sound:   ] <img src=company.png>'),
        );
    }

    public function test_it_ignores_image_sources_with_mismatched_quotes(): void
    {
        $this->assertSame([], StudyFieldMediaReferences::fromText('<img src="company.png\'>'));
        $this->assertSame([], StudyFieldMediaReferences::fromText('<img src=\'company.png">'));
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
                'id' => null,
                'filename' => 'legacy.mp3',
                'url' => null,
                'mediaKind' => 'audio',
                'source' => 'imported',
            ],
            StudyFieldMediaReferences::audioFromValue('[sound:legacy.mp3]'),
        );
        $this->assertSame(
            [
                'id' => null,
                'filename' => 'legacy.png',
                'url' => null,
                'mediaKind' => 'image',
                'source' => 'imported_image',
            ],
            StudyFieldMediaReferences::imageFromValue('<img src="legacy.png">'),
        );
    }

    public function test_value_media_helpers_keep_the_first_legacy_reference(): void
    {
        $this->assertSame(
            [
                'id' => null,
                'filename' => 'first.mp3',
                'url' => null,
                'mediaKind' => 'audio',
                'source' => 'imported',
            ],
            StudyFieldMediaReferences::audioFromValue('[sound:first.mp3] [sound:second.mp3]'),
        );
        $this->assertSame(
            [
                'id' => null,
                'filename' => 'first.png',
                'url' => null,
                'mediaKind' => 'image',
                'source' => 'imported_image',
            ],
            StudyFieldMediaReferences::imageFromValue('<img src="first.png"> <img src="second.png">'),
        );
        $this->assertSame(
            ['first.mp3', 'second.mp3'],
            StudyFieldMediaReferences::filenamesFromText('[sound:first.mp3] [sound:second.mp3] [sound:first.mp3]'),
        );
    }

    public function test_it_extracts_audio_and_image_from_a_value_once_for_browser_fields(): void
    {
        $this->assertSame(
            [
                'audio' => [
                    'id' => null,
                    'filename' => 'word.mp3',
                    'url' => null,
                    'mediaKind' => 'audio',
                    'source' => 'imported',
                ],
                'image' => [
                    'id' => null,
                    'filename' => 'company.png',
                    'url' => null,
                    'mediaKind' => 'image',
                    'source' => 'imported_image',
                ],
            ],
            StudyFieldMediaReferences::fromValue('[sound:word.mp3] <img src="company.png">'),
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
