<?php

namespace Tests\Unit\Support;

use App\Support\Audio\AudioSpeechGenerationException;
use App\Support\Audio\AudioSpeechGenerator;
use App\Support\Audio\FishAudioSpeechGenerator;
use Illuminate\Support\Facades\Http;
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

    public function test_the_shared_binding_uses_a_domain_neutral_provider_and_exception(): void
    {
        $this->assertInstanceOf(FishAudioSpeechGenerator::class, app(AudioSpeechGenerator::class));
        Http::fake(['fish.test/v1/tts' => Http::response(['error' => 'busy'], 429)]);

        try {
            app(AudioSpeechGenerator::class)->generate('音声', self::VOICE_ID);
            $this->fail('Expected the shared provider rate limit to be reported.');
        } catch (AudioSpeechGenerationException $exception) {
            $this->assertSame(AudioSpeechGenerationException::RATE_LIMITED, $exception->reason);
            $this->assertSame('Fish Audio is rate limiting speech generation.', $exception->getMessage());
        }
    }
}
