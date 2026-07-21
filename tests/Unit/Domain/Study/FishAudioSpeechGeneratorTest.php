<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Domain\Study\Services\FishAudioSpeechGenerator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('providerFailureProvider')]
    public function test_it_maps_neutral_provider_failures_to_the_study_http_contract(
        int $providerStatus,
        string $providerBody,
        int $expectedHttpStatus,
        string $expectedMessage,
    ): void {
        Http::fake([
            'fish.test/v1/tts' => Http::response($providerBody, $providerStatus),
        ]);

        try {
            app(FishAudioSpeechGenerator::class)->generate('音声', self::VOICE_ID);
            $this->fail('Expected Fish Audio generation to fail.');
        } catch (StudyPreviewMediaGenerationException $exception) {
            $this->assertSame($expectedHttpStatus, $exception->httpStatus());
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }

    /** @return array<string, array{int, string, int, string}> */
    public static function providerFailureProvider(): array
    {
        return [
            'unavailable' => [401, 'unauthorized', 503, 'Fish Audio preview generation is unavailable.'],
            'rate limited' => [429, 'busy', 429, 'Fish Audio is rate limiting preview generation. Please try again shortly.'],
            'provider failed' => [500, 'error', 502, 'Fish Audio failed to generate preview media.'],
            'invalid output' => [200, 'not-an-mp3', 502, 'Fish Audio returned invalid preview media.'],
        ];
    }
}
