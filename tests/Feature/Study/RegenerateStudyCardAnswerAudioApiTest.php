<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Domain\Study\Actions\RegenerateStudyCardAnswerAudioAction;
use App\Domain\Study\Data\RegenerateStudyCardAnswerAudioData;
use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use App\Domain\Study\Support\StudyCardGenerationDefaults;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class RegenerateStudyCardAnswerAudioApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    private const VOICE_ID = 'fishaudio:abb4362e736f40b7b5716f4fafcafa9f';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config([
            'services.fish_audio.api_key' => 'fish-test-key',
            'services.fish_audio.base_url' => 'https://fish.test',
            'services.fish_audio.backend' => 's1',
        ]);
    }

    public function test_it_regenerates_answer_audio_and_returns_the_compatibility_card_shape(): void
    {
        Http::fake([
            'fish.test/v1/tts' => Http::response('ID3fresh-audio'),
        ]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => [
                'expression' => '会社',
                'meaning' => 'company',
                'answerAudioVoiceId' => self::VOICE_ID,
            ],
        ]);

        $response = $this->postJson("/api/study/cards/{$card->id}/regenerate-answer-audio", [
            'answerAudioTextOverride' => 'かいしゃ',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('id', $card->id)
            ->assertJsonPath('answer.answerAudioTextOverride', 'かいしゃ')
            ->assertJsonPath('answer.answerAudio.mediaKind', 'audio')
            ->assertJsonPath('answer.answerAudio.source', 'generated')
            ->assertJsonPath('answerAudioSource', 'generated');
        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $media = MediaAsset::query()->sole();
        $this->assertSame($media->id, $card->refresh()->answer_json['answerAudio']['id']);
        $this->assertSame([$media->id], $card->mediaAssets()->pluck('media_assets.id')->all());
        Storage::disk('media')->assertExists($media->path);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://fish.test/v1/tts'
                && $request->data()['text'] === 'かいしゃ'
                && $request->data()['reference_id'] === 'abb4362e736f40b7b5716f4fafcafa9f';
        });

        $this->assertDatabaseCount('sync_feed_entries', 3);
        $this->assertSyncEntry(
            $user->id,
            MediaAssetSyncPayload::RESOURCE_TYPE,
            $media->id,
            SyncFeedOperation::Create,
        );
        $this->assertSyncEntry(
            $user->id,
            CardSyncPayload::RESOURCE_TYPE,
            $card->id,
            SyncFeedOperation::Update,
        );
        $this->assertSyncEntry(
            $user->id,
            CardMediaSyncPayload::RESOURCE_TYPE,
            CardMediaSyncPayload::resourceId($card->id, $media->id),
            SyncFeedOperation::Create,
        );
    }

    public function test_it_accepts_an_uppercase_copied_card_uuid_and_returns_the_canonical_client_id(): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3copied-card')]);
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->make([
            'front_text' => '会社',
            'back_text' => 'company',
            'answer_json' => ['expression' => '会社', 'answerAudioVoiceId' => self::VOICE_ID],
        ]);
        $card->convolab_id = 'c358732a-2cd0-4b18-9cce-c474297863f9';
        $card->save();

        $this->postJson('/api/study/cards/C358732A-2CD0-4B18-9CCE-C474297863F9/regenerate-answer-audio')
            ->assertOk()
            ->assertJsonPath('id', 'c358732a-2cd0-4b18-9cce-c474297863f9');
    }

    public function test_it_replaces_and_deletes_unreferenced_generated_audio(): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3replacement')]);
        $user = $this->signIn();
        $oldMedia = MediaAsset::factory()->for($user)->create([
            'disk' => 'media',
            'path' => 'study/generated/old.mp3',
            'mime_type' => 'audio/mpeg',
            'original_filename' => 'old.mp3',
        ]);
        Storage::disk('media')->put($oldMedia->path, 'old');
        $card = $this->studyCardFor($user, [
            'answer_json' => [
                'expression' => '会社',
                'answerAudioVoiceId' => self::VOICE_ID,
                'answerAudio' => [
                    'id' => $oldMedia->id,
                    'filename' => 'old.mp3',
                    'url' => "/api/study/media/{$oldMedia->id}",
                    'mediaKind' => 'audio',
                    'source' => 'generated',
                ],
            ],
            'answer_audio_source' => 'generated',
        ]);
        $card->mediaAssets()->attach($oldMedia);

        $this->postJson("/api/study/cards/{$card->id}/regenerate-answer-audio")
            ->assertOk();

        $this->assertDatabaseMissing('media_assets', ['id' => $oldMedia->id]);
        Storage::disk('media')->assertMissing($oldMedia->path);
        $this->assertNotSame($oldMedia->id, $card->refresh()->answer_json['answerAudio']['id']);
    }

    public function test_it_keeps_old_generated_audio_when_another_card_still_references_it(): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3replacement')]);
        $user = $this->signIn();
        $oldMedia = MediaAsset::factory()->for($user)->create(['mime_type' => 'audio/mpeg']);
        $reference = [
            'id' => $oldMedia->id,
            'filename' => $oldMedia->original_filename,
            'url' => "/api/study/media/{$oldMedia->id}",
            'mediaKind' => 'audio',
            'source' => 'generated',
        ];
        $card = $this->studyCardFor($user, [
            'answer_json' => ['expression' => '会社', 'answerAudioVoiceId' => self::VOICE_ID, 'answerAudio' => $reference],
        ]);
        $otherCard = $this->studyCardFor($user, ['answer_json' => ['expression' => '別', 'answerAudio' => $reference]]);
        $card->mediaAssets()->attach($oldMedia);
        $otherCard->mediaAssets()->attach($oldMedia);

        $this->postJson("/api/study/cards/{$card->id}/regenerate-answer-audio")
            ->assertOk();

        $this->assertDatabaseHas('media_assets', ['id' => $oldMedia->id]);
        $this->assertTrue($otherCard->mediaAssets()->whereKey($oldMedia->id)->exists());
        $this->assertFalse($card->mediaAssets()->whereKey($oldMedia->id)->exists());
    }

    public function test_both_routes_hide_cross_user_cards_and_do_not_call_the_provider(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $card = $this->studyCardFor($owner, [
            'answer_json' => ['expression' => '会社', 'answerAudioVoiceId' => self::VOICE_ID],
        ]);
        $this->signIn();

        $this->postJson("/api/study/cards/{$card->id}/regenerate-answer-audio")
            ->assertNotFound();
        $this->postJson("/api/study/cards/{$card->id}/prepare-answer-audio")
            ->assertNotFound();

        Http::assertNothingSent();
    }

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

    public function test_prepare_migrates_a_persisted_legacy_google_voice_to_the_fish_default(): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3migrated')]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => [
                'expression' => '会社',
                'answerAudioVoiceId' => 'ja-JP-Wavenet-D',
            ],
        ]);

        $this->postJson("/api/study/cards/{$card->id}/prepare-answer-audio")
            ->assertOk()
            ->assertJsonPath('answer.answerAudioVoiceId', StudyCardGenerationDefaults::VOICE_ID)
            ->assertJsonPath('answer.answerAudio.source', 'generated');

        $this->assertSame(
            StudyCardGenerationDefaults::VOICE_ID,
            $card->refresh()->answer_json['answerAudioVoiceId'],
        );
        $cardEntry = SyncFeedEntry::query()
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->sole();
        $this->assertSame(
            StudyCardGenerationDefaults::VOICE_ID,
            $cardEntry->payload['answer_json']['answerAudioVoiceId'],
        );
        Http::assertSent(fn (Request $request): bool => $request->data()['reference_id'] === 'abb4362e736f40b7b5716f4fafcafa9f');
    }

    public function test_regenerate_accepts_a_legacy_google_voice_override_and_persists_the_fish_default(): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3migrated')]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => ['expression' => '会社'],
        ]);

        $this->postJson("/api/study/cards/{$card->id}/regenerate-answer-audio", [
            'answerAudioVoiceId' => 'ja-JP-Neural2-C',
        ])
            ->assertOk()
            ->assertJsonPath('answer.answerAudioVoiceId', StudyCardGenerationDefaults::VOICE_ID);

        $this->assertSame(
            StudyCardGenerationDefaults::VOICE_ID,
            $card->refresh()->answer_json['answerAudioVoiceId'],
        );
    }

    public function test_the_action_migrates_a_legacy_google_voice_override_for_direct_callers(): void
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
                voiceId: 'ja-JP-Wavenet-A',
                hasTextOverride: false,
                textOverride: null,
            ),
        );

        $this->assertSame(
            StudyCardGenerationDefaults::VOICE_ID,
            $updated->answer_json['answerAudioVoiceId'],
        );
        Http::assertSent(fn (Request $request): bool => $request->data()['reference_id'] === 'abb4362e736f40b7b5716f4fafcafa9f');
    }

    public function test_it_validates_payload_text_voice_and_unknown_fields_before_generation(): void
    {
        Http::fake();
        $user = $this->signIn();
        $card = $this->studyCardFor($user, ['answer_json' => ['meaning' => 'company']]);

        $this->postJson("/api/study/cards/{$card->id}/regenerate-answer-audio", [
            'answerAudioVoiceId' => 'not-a-voice',
            'unexpected' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answerAudioVoiceId', 'unexpected']);

        Http::assertNothingSent();
    }

    public function test_the_action_rejects_invalid_direct_caller_inputs_before_provider_or_storage_work(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $card = $this->studyCardFor($user, [
            'answer_json' => [
                'expression' => '会社',
                'answerAudioVoiceId' => 'not-a-voice',
            ],
        ]);

        try {
            app(RegenerateStudyCardAnswerAudioAction::class)->handle(
                $card,
                RegenerateStudyCardAnswerAudioData::fromInput(
                    hasVoiceId: false,
                    voiceId: null,
                    hasTextOverride: false,
                    textOverride: null,
                ),
            );
            $this->fail('Expected invalid direct-caller voice data to be rejected.');
        } catch (StudyCardAudioValidationException $exception) {
            $this->assertSame('answer.answerAudioVoiceId', $exception->field());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_it_cleans_up_generated_media_when_the_card_changes_during_generation(): void
    {
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => ['expression' => '会社', 'answerAudioVoiceId' => self::VOICE_ID],
        ]);
        Http::fake(function () use ($card) {
            $card->forceFill(['back_text' => 'changed concurrently'])->save();

            return Http::response('ID3stale-audio');
        });

        $this->postJson("/api/study/cards/{$card->id}/regenerate-answer-audio")
            ->assertConflict()
            ->assertExactJson([
                'message' => 'The study card changed while answer audio was being generated. Please retry.',
            ]);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function studyCardFor(User $user, array $attributes): Card
    {
        return Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '会社',
            'back_text' => 'company',
            ...$attributes,
        ]);
    }

    private function assertSyncEntry(
        int $userId,
        string $resourceType,
        string $resourceId,
        SyncFeedOperation $operation,
    ): void {
        $entry = SyncFeedEntry::query()
            ->where('user_id', $userId)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->sole();

        $this->assertSame($operation, $entry->operation);
    }
}
