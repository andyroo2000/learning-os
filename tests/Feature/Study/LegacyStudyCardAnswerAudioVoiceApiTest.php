<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Study\Actions\RegenerateStudyCardAnswerAudioAction;
use App\Domain\Study\Data\RegenerateStudyCardAnswerAudioData;
use App\Domain\Study\Support\StudyCardGenerationDefaults;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Study\RegenerateStudyCardAnswerAudioTestCase;

class LegacyStudyCardAnswerAudioVoiceApiTest extends RegenerateStudyCardAnswerAudioTestCase
{
    public function test_prepare_migrates_a_persisted_legacy_google_voice_to_the_fish_default(): void
    {
        $this->assertPrepareMigratesVoice(
            'ja-JP-Wavenet-D',
            StudyCardGenerationDefaults::VOICE_ID,
            'abb4362e736f40b7b5716f4fafcafa9f',
        );
    }

    public function test_prepare_preserves_gender_when_migrating_a_persisted_legacy_polly_voice(): void
    {
        $this->assertPrepareMigratesVoice(
            'Kazuha',
            StudyCardGenerationDefaults::FEMALE_VOICE_ID,
            '9639f090aa6346329d7d3aca7e6b7226',
        );
    }

    public function test_regenerate_accepts_a_legacy_polly_voice_override_and_persists_the_fish_default(): void
    {
        $this->assertRegenerateMigratesVoice('Tomoko');
    }

    public function test_the_action_migrates_a_legacy_polly_voice_override_for_direct_callers(): void
    {
        $this->assertDirectActionMigratesVoice('Kazuha');
    }

    public function test_regenerate_preserves_gender_for_a_legacy_google_voice_override(): void
    {
        $this->assertRegenerateMigratesVoice('ja-JP-Neural2-B');
    }

    public function test_the_action_migrates_a_legacy_google_voice_override_for_direct_callers(): void
    {
        $this->assertDirectActionMigratesVoice('ja-JP-Wavenet-A');
    }

    private function assertPrepareMigratesVoice(
        string $legacyVoiceId,
        string $expectedVoiceId,
        string $expectedReferenceId,
    ): void {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3migrated')]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => [
                'expression' => '会社',
                'answerAudioVoiceId' => $legacyVoiceId,
            ],
        ]);

        $this->postJson("/api/study/cards/{$card->id}/prepare-answer-audio")
            ->assertOk()
            ->assertJsonPath('answer.answerAudioVoiceId', $expectedVoiceId)
            ->assertJsonPath('answer.answerAudio.source', 'generated');

        $this->assertSame($expectedVoiceId, $card->refresh()->answer_json['answerAudioVoiceId']);
        $cardEntry = SyncFeedEntry::query()
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->sole();
        $this->assertSame($expectedVoiceId, $cardEntry->payload['answer_json']['answerAudioVoiceId']);
        Http::assertSent(fn (Request $request): bool => $request->data()['reference_id'] === $expectedReferenceId);
    }

    private function assertRegenerateMigratesVoice(string $legacyVoiceId): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3migrated')]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => ['expression' => '会社'],
        ]);

        $this->postJson("/api/study/cards/{$card->id}/regenerate-answer-audio", [
            'answerAudioVoiceId' => $legacyVoiceId,
        ])
            ->assertOk()
            ->assertJsonPath('answer.answerAudioVoiceId', StudyCardGenerationDefaults::FEMALE_VOICE_ID);

        $this->assertSame(
            StudyCardGenerationDefaults::FEMALE_VOICE_ID,
            $card->refresh()->answer_json['answerAudioVoiceId'],
        );
        Http::assertSent(fn (Request $request): bool => $request->data()['reference_id'] === '9639f090aa6346329d7d3aca7e6b7226');
    }

    private function assertDirectActionMigratesVoice(string $legacyVoiceId): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3direct-migration')]);
        $user = User::factory()->create();
        $card = $this->studyCardFor($user, [
            'answer_json' => ['expression' => '会社'],
        ]);

        $updated = app(RegenerateStudyCardAnswerAudioAction::class)->handle(
            $card,
            RegenerateStudyCardAnswerAudioData::fromInput(
                hasVoiceId: true,
                voiceId: $legacyVoiceId,
                hasTextOverride: false,
                textOverride: null,
            ),
        );

        $this->assertSame(
            StudyCardGenerationDefaults::FEMALE_VOICE_ID,
            $updated->answer_json['answerAudioVoiceId'],
        );
        Http::assertSent(fn (Request $request): bool => $request->data()['reference_id'] === '9639f090aa6346329d7d3aca7e6b7226');
    }
}
