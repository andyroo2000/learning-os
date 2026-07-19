<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Services\FishAudioSpeechGenerator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class FishAudioSpeechGeneratorTest extends TestCase
{
    private const VOICE_ID = 'fishaudio:abb4362e736f40b7b5716f4fafcafa9f';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.fish_audio.api_key' => 'fish-test-key',
            'services.fish_audio.base_url' => 'https://fish.test',
            'services.fish_audio.backend' => 's1',
        ]);
    }

    public function test_it_sends_the_requested_speech_speed(): void
    {
        Http::fake([
            'fish.test/v1/tts' => Http::response('ID3daily-audio'),
        ]);

        $bytes = app(FishAudioSpeechGenerator::class)->generate(
            'ゆっくり話してください。',
            self::VOICE_ID,
            0.85,
        );

        $this->assertSame('ID3daily-audio', $bytes);
        Http::assertSent(fn (Request $request): bool => $request->data()['prosody'] === [
            'speed' => 0.85,
            'volume' => 0,
        ]);
    }

    public function test_it_rejects_invalid_speed_before_calling_the_provider(): void
    {
        Http::fake();

        try {
            app(FishAudioSpeechGenerator::class)->generate(
                '速すぎます。',
                self::VOICE_ID,
                2.01,
            );
            $this->fail('Expected an invalid Fish Audio speed to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Fish Audio speech speed is invalid.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
    }
}
