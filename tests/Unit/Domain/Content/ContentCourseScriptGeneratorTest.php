<?php

namespace Tests\Unit\Domain\Content;

use App\Domain\Content\Services\ContentCourseScriptGenerator;
use App\Domain\Content\Services\ContentOpenAiClient;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ContentCourseScriptGeneratorTest extends TestCase
{
    public function test_it_requires_narrator_and_speaker_voices_before_calling_the_provider(): void
    {
        $client = Mockery::mock(ContentOpenAiClient::class);
        $client->shouldNotReceive('generateJson');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Course generation requires narrator and speaker voice IDs.');

        (new ContentCourseScriptGenerator($client))->generate([
            'course' => [
                'l1VoiceId' => 'narrator', 'speaker1VoiceId' => null,
                'speaker2VoiceId' => null, 'maxLessonDurationMinutes' => 30,
            ],
            'episode' => ['sourceText' => 'Text', 'sentences' => []],
        ]);
    }

    public function test_it_rejects_an_oversized_source_before_calling_the_provider(): void
    {
        $client = Mockery::mock(ContentOpenAiClient::class);
        $client->shouldNotReceive('generateJson');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Course source is too large for script generation.');

        (new ContentCourseScriptGenerator($client))->generate([
            'course' => [
                'l1VoiceId' => 'narrator',
                'speaker1VoiceId' => 'speaker',
                'speaker2VoiceId' => null,
                'maxLessonDurationMinutes' => 30,
            ],
            'episode' => ['sourceText' => str_repeat('a', 500001), 'sentences' => []],
        ]);
    }

    public function test_it_rejects_a_script_over_the_course_maximum_duration(): void
    {
        $client = Mockery::mock(ContentOpenAiClient::class);
        $client->shouldReceive('generateJson')->once()->andReturn(json_encode([
            'exchanges' => [[
                'speakerName' => 'Aki', 'speakerVoiceId' => 'speaker',
                'textL2' => '猫', 'readingL2' => null, 'translationL1' => 'cat',
                'vocabularyItems' => [],
            ]],
            'scriptUnits' => [
                ['type' => 'pause', 'seconds' => 60],
                ['type' => 'pause', 'seconds' => 60],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Course generator exceeded the maximum lesson duration.');

        (new ContentCourseScriptGenerator($client))->generate([
            'course' => [
                'l1VoiceId' => 'narrator', 'speaker1VoiceId' => 'speaker',
                'speaker2VoiceId' => null, 'maxLessonDurationMinutes' => 1,
            ],
            'episode' => ['sourceText' => 'Text', 'sentences' => []],
        ]);
    }
}
