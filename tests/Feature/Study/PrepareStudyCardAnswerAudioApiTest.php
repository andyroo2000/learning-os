<?php

namespace Tests\Feature\Study;

use Illuminate\Support\Facades\Http;
use Tests\Support\Study\RegenerateStudyCardAnswerAudioTestCase;

class PrepareStudyCardAnswerAudioApiTest extends RegenerateStudyCardAnswerAudioTestCase
{
    public function test_prepare_returns_playable_imported_audio_without_provider_spend(): void
    {
        Http::fake();
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => [
                'expression' => '会社',
                'answerAudio' => [
                    'id' => null,
                    'filename' => 'imported.mp3',
                    'url' => '/api/study/media/imported',
                    'mediaKind' => 'audio',
                    'source' => 'imported',
                ],
            ],
            'answer_audio_source' => 'imported',
        ]);

        $this->postJson("/api/study/cards/{$card->id}/prepare-answer-audio")
            ->assertOk()
            ->assertJsonPath('answer.answerAudio.filename', 'imported.mp3')
            ->assertJsonPath('answerAudioSource', 'imported');

        Http::assertNothingSent();
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_prepare_reuses_prompt_only_audio_without_provider_spend(): void
    {
        Http::fake();
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'prompt_json' => [
                'cueAudio' => [
                    'id' => null,
                    'filename' => 'listening-example.mp3',
                    'url' => '/api/study/media/listening-example',
                    'mediaKind' => 'audio',
                    'source' => 'imported',
                ],
            ],
            'answer_json' => ['expression' => '会社'],
            'answer_audio_source' => 'imported',
        ]);

        $this->postJson("/api/study/cards/{$card->id}/prepare-answer-audio")
            ->assertOk()
            ->assertJsonPath('prompt.cueAudio.filename', 'listening-example.mp3')
            ->assertJsonMissingPath('answer.answerAudio')
            ->assertJsonPath('answerAudioSource', 'imported');

        Http::assertNothingSent();
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_prepare_repairs_a_missing_generated_media_asset(): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3repaired')]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => [
                'expression' => '会社',
                'answerAudioVoiceId' => self::VOICE_ID,
                'answerAudio' => [
                    'id' => '01J00000000000000000000000',
                    'filename' => 'missing.mp3',
                    'url' => '/api/study/media/01J00000000000000000000000',
                    'mediaKind' => 'audio',
                    'source' => 'generated',
                ],
            ],
            'answer_audio_source' => 'generated',
        ]);

        $response = $this->postJson("/api/study/cards/{$card->id}/prepare-answer-audio");
        $response
            ->assertOk()
            ->assertJsonPath('answer.answerAudio.source', 'generated');
        $this->assertNotSame('01J00000000000000000000000', $response->json('answer.answerAudio.id'));

        Http::assertSentCount(1);
        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_prepare_fallback_consumes_the_shared_generation_rate_limit_budget(): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3audio')]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => ['expression' => '会社', 'answerAudioVoiceId' => self::VOICE_ID],
        ]);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson("/api/study/cards/{$card->id}/regenerate-answer-audio")->assertOk();
        }

        $card->forceFill([
            'answer_json' => [
                'expression' => '会社',
                'answerAudioVoiceId' => self::VOICE_ID,
            ],
            'answer_audio_source' => 'missing',
        ])->save();

        $this->postJson("/api/study/cards/{$card->id}/prepare-answer-audio")
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset')
            ->assertExactJson([
                'message' => 'Study media generation rate limit exceeded. Please try again shortly.',
            ]);
        Http::assertSentCount(10);
    }
}
