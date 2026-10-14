<?php

namespace Tests\Unit\Support;

use App\Support\Audio\AudioSpeechGenerationException;
use App\Support\Audio\AudioSpeechGenerator;
use App\Support\Audio\FishAudioSpeechGenerator;
use Illuminate\Http\Client\Request;
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
            'services.fish_audio.backend' => 'drama-3',
        ]);
    }

    public function test_it_sends_drama_3_with_the_selected_voice_and_delivery_direction(): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3preview')]);
        $text = '[warm, conversational] 今日はいい天気ですね。';
        $voiceId = '297a6fd278df47c3b9da9bfdf55ac89a';

        $bytes = app(AudioSpeechGenerator::class)->generate($text, 'fishaudio:'.$voiceId);

        $this->assertSame('ID3preview', $bytes);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('model', 'drama-3')
            && $request['reference_id'] === $voiceId
            && $request['text'] === $text
            && $request['format'] === 'mp3');
    }

    public function test_it_preserves_an_explicit_s1_backend_override(): void
    {
        config()->set('services.fish_audio.backend', 's1');
        Http::fake(['fish.test/v1/tts' => Http::response('ID3preview')]);

        app(AudioSpeechGenerator::class)->generate('こんにちは。', self::VOICE_ID);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('model', 's1'));
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
