<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardGenerationDefaults;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Study\RegenerateStudyCardAnswerAudioTestCase;

class RandomStudyCardAudioVoiceTest extends RegenerateStudyCardAnswerAudioTestCase
{
    public function test_missing_card_audio_chooses_and_persists_a_pool_voice_then_reuses_it(): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3audio')]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => ['expression' => '猫です。'],
            'answer_audio_source' => 'missing',
        ]);
        $response = $this->postJson("/api/study/cards/{$card->id}/prepare-answer-audio")->assertOk();
        $voice = $response->json('answer.answerAudioVoiceId');
        $this->assertContains($voice, StudyCardGenerationDefaults::VOICE_IDS);
        $this->assertSame($voice, $card->refresh()->answer_json['answerAudioVoiceId']);
        Http::assertSent(fn (Request $request): bool => $request['reference_id'] === substr($voice, 10));

        $this->postJson("/api/study/cards/{$card->id}/regenerate-answer-audio")
            ->assertOk()->assertJsonPath('answer.answerAudioVoiceId', $voice);
        Http::assertSentCount(2);
    }

    public function test_preview_saves_its_random_voice_and_preserves_it_on_regeneration(): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3audio')]);
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->for($user)->create([
            'status' => StudyManualCardDraftStatus::Ready,
            'answer_json' => ['expression' => '猫です。'],
        ]);
        $this->postJson("/api/study/card-drafts/{$draft->id}/preview-audio")->assertOk();
        $voice = $draft->refresh()->answer_json['answerAudioVoiceId'];
        $this->assertContains($voice, StudyCardGenerationDefaults::VOICE_IDS);
        Http::assertSent(fn (Request $request): bool => $request['reference_id'] === substr($voice, 10));

        $this->postJson("/api/study/card-drafts/{$draft->id}/preview-audio")->assertOk();
        $this->assertSame($voice, $draft->refresh()->answer_json['answerAudioVoiceId']);
        Http::assertSentCount(2);
    }
}
