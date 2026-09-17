<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Exceptions\StudyCaptureReadingException;
use App\Domain\Study\Services\OpenAiStudyCardGenerator;
use App\Domain\Study\Services\StudyCaptureReadingGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StudyCaptureReadingGeneratorTest extends TestCase
{
    public function test_kana_only_subtitles_do_not_call_the_provider(): void
    {
        $provider = $this->createMock(OpenAiStudyCardGenerator::class);
        $provider->expects($this->never())->method('generateJson');
        $this->assertSame('ありがとう。', (new StudyCaptureReadingGenerator($provider))->generate('ありがとう。'));
    }

    public function test_it_generates_only_a_reading_for_the_exact_source_sentence(): void
    {
        $provider = $this->createMock(OpenAiStudyCardGenerator::class);
        $provider->expects($this->once())->method('generateJson')
            ->with($this->stringContains('Preserve every original character'), '{"expression":"今日。"}')
            ->willReturn('{"reading":"今日[きょう]。"}');
        $this->assertSame('今日[きょう]。', (new StudyCaptureReadingGenerator($provider))->generate('今日。'));
    }

    public function test_provider_errors_are_wrapped_for_retryable_api_handling(): void
    {
        $provider = $this->createMock(OpenAiStudyCardGenerator::class);
        $provider->expects($this->once())->method('generateJson')->willThrowException(new RuntimeException('Unavailable'));
        $this->expectException(StudyCaptureReadingException::class);
        (new StudyCaptureReadingGenerator($provider))->generate('今日。');
    }
}
