<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Domain\Study\Actions\RegenerateStudyCardAnswerAudioAction;
use App\Domain\Study\Data\RegenerateStudyCardAnswerAudioData;
use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Study\RegenerateStudyCardAnswerAudioTestCase;

class RegenerateStudyCardAnswerAudioApiTest extends RegenerateStudyCardAnswerAudioTestCase
{
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
            ->assertJsonPath('answerAudioSource', 'generated')
            ->assertJsonPath('revision', 1);
        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $media = MediaAsset::query()->sole();
        $this->assertSame($media->id, $card->refresh()->answer_json['answerAudio']['id']);
        $this->assertSame(1, $card->content_revision);
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

    public function test_it_regenerates_audio_for_a_structured_card_with_blank_legacy_text(): void
    {
        Http::fake([
            'fish.test/v1/tts' => Http::response('ID3structured-audio'),
        ]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'front_text' => '',
            'back_text' => '',
            'prompt_json' => ['cueText' => '学校で偉人について勉強しました。'],
            'answer_json' => [
                'expression' => '学校で偉人について勉強しました。',
                'answerAudioVoiceId' => self::VOICE_ID,
            ],
        ]);

        $this->postJson("/api/study/cards/{$card->id}/regenerate-answer-audio")
            ->assertOk()
            ->assertJsonPath('answer.answerAudio.source', 'generated');

        $card->refresh();
        $this->assertSame('', $card->front_text);
        $this->assertSame('', $card->back_text);
        $this->assertSame('generated', $card->answer_audio_source);
        $this->assertIsArray($card->answer_json['answerAudio']);
        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_keeps_audio_recognition_prompt_and_answer_audio_in_sync(): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3replacement')]);
        $user = $this->signIn();
        $oldPromptMedia = $this->generatedAudioFor($user, 'study/generated/old-prompt.mp3');
        $oldAnswerMedia = $this->generatedAudioFor($user, 'study/generated/old-answer.mp3');
        $card = $this->studyCardFor($user, [
            'card_type' => CardType::Recognition,
            'prompt_json' => ['cueAudio' => $this->audioReference($oldPromptMedia)],
            'answer_json' => [
                'expression' => '学校で偉人について勉強しました。',
                'answerAudioVoiceId' => self::VOICE_ID,
                'answerAudio' => $this->audioReference($oldAnswerMedia),
            ],
            'answer_audio_source' => 'generated',
        ]);
        $card->mediaAssets()->attach([$oldPromptMedia->id, $oldAnswerMedia->id]);
        $this->assertSame(CardType::Recognition, $card->card_type);
        $this->assertSame($oldPromptMedia->id, $card->prompt_json['cueAudio']['id']);

        $response = $this->postJson("/api/study/cards/{$card->id}/regenerate-answer-audio")
            ->assertOk();

        $newMediaId = $response->json('answer.answerAudio.id');
        $this->assertIsString($newMediaId);
        $response->assertJsonPath('prompt.cueAudio.id', $newMediaId);
        $card->refresh();
        $this->assertSame($newMediaId, $card->prompt_json['cueAudio']['id']);
        $this->assertSame($newMediaId, $card->answer_json['answerAudio']['id']);
        $this->assertSame([$newMediaId], $card->mediaAssets()->pluck('media_assets.id')->all());
        $this->assertDatabaseMissing('media_assets', ['id' => $oldPromptMedia->id]);
        $this->assertDatabaseMissing('media_assets', ['id' => $oldAnswerMedia->id]);
        Storage::disk('media')->assertMissing($oldPromptMedia->path);
        Storage::disk('media')->assertMissing($oldAnswerMedia->path);

        $cardEntry = SyncFeedEntry::query()
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->sole();
        $this->assertSame($newMediaId, $cardEntry->payload['prompt_json']['cueAudio']['id']);
        $this->assertSame($newMediaId, $cardEntry->payload['answer_json']['answerAudio']['id']);
        foreach ([$oldPromptMedia, $oldAnswerMedia] as $oldMedia) {
            $this->assertSyncEntry(
                $user->id,
                CardMediaSyncPayload::RESOURCE_TYPE,
                CardMediaSyncPayload::resourceId($card->id, $oldMedia->id),
                SyncFeedOperation::Delete,
            );
            $this->assertSyncEntry(
                $user->id,
                MediaAssetSyncPayload::RESOURCE_TYPE,
                $oldMedia->id,
                SyncFeedOperation::Delete,
            );
        }
    }

    public function test_it_preserves_prompt_audio_and_attachment_for_text_led_recognition_cards(): void
    {
        Http::fake(['fish.test/v1/tts' => Http::response('ID3replacement')]);
        $user = $this->signIn();
        $oldMedia = $this->generatedAudioFor($user, 'study/generated/shared-prompt-answer.mp3');
        $oldReference = $this->audioReference($oldMedia);
        $card = $this->studyCardFor($user, [
            'card_type' => CardType::Recognition,
            'prompt_json' => [
                'cueText' => '会社',
                'cueAudio' => $oldReference,
            ],
            'answer_json' => [
                'expression' => '会社',
                'answerAudioVoiceId' => self::VOICE_ID,
                'answerAudio' => $oldReference,
            ],
            'answer_audio_source' => 'generated',
        ]);
        $card->mediaAssets()->attach($oldMedia);
        $this->assertSame(CardType::Recognition, $card->card_type);
        $this->assertSame('会社', $card->prompt_json['cueText']);

        $response = $this->postJson("/api/study/cards/{$card->id}/regenerate-answer-audio")
            ->assertOk();

        $response->assertJsonPath('prompt.cueAudio.id', $oldMedia->id);
        $this->assertNotSame($oldMedia->id, $response->json('answer.answerAudio.id'));
        $this->assertDatabaseHas('media_assets', ['id' => $oldMedia->id]);
        Storage::disk('media')->assertExists($oldMedia->path);
        $this->assertTrue($card->mediaAssets()->whereKey($oldMedia->id)->exists());
        $this->assertDatabaseMissing('sync_feed_entries', [
            'resource_type' => CardMediaSyncPayload::RESOURCE_TYPE,
            'resource_id' => CardMediaSyncPayload::resourceId($card->id, $oldMedia->id),
            'operation' => SyncFeedOperation::Delete->value,
        ]);
        $this->assertDatabaseMissing('sync_feed_entries', [
            'resource_type' => MediaAssetSyncPayload::RESOURCE_TYPE,
            'resource_id' => $oldMedia->id,
            'operation' => SyncFeedOperation::Delete->value,
        ]);
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
}
