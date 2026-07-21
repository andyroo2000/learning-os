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
            'course' => ['l1VoiceId' => 'narrator', 'speaker1VoiceId' => null, 'speaker2VoiceId' => null],
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
            ],
            'episode' => ['sourceText' => str_repeat('a', 500001), 'sentences' => []],
        ]);
    }
}
