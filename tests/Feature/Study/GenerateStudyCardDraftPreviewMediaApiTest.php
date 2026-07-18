<?php

namespace Tests\Feature\Study;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Services\FishAudioSpeechGenerator;
use App\Domain\Study\Services\OpenAiStudyImageGenerator;
use App\Domain\Study\Sync\StudyCardDraftSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateStudyCardDraftPreviewMediaApiTest extends TestCase
{
    use RefreshDatabase;

    private const FISH_VOICE_ID = 'fishaudio:abb4362e736f40b7b5716f4fafcafa9f';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config([
            'services.fish_audio.api_key' => 'fish-test-key',
            'services.fish_audio.base_url' => 'https://fish.test',
            'services.fish_audio.backend' => 's1',
            'services.openai.api_key' => 'openai-test-key',
            'services.openai.base_url' => 'https://openai.test/v1',
            'services.openai.study_image_model' => 'gpt-image-1',
        ]);
    }

    public function test_it_generates_and_persists_answer_preview_audio_for_an_owned_draft(): void
    {
        Http::fake([
            'fish.test/v1/tts' => Http::response('ID3mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        $user = $this->signIn();
        $draft = $this->readyDraft($user, [
            'creation_kind' => StudyCardCreationKind::TextRecognition,
            'answer_json' => [
                'expression' => '会社',
                'answerAudioTextOverride' => 'かいしゃ',
                'answerAudioVoiceId' => self::FISH_VOICE_ID,
            ],
        ]);

        $response = $this->postJson("/api/study/card-drafts/{$draft->id}/preview-audio");

        $response
            ->assertOk()
            ->assertExactJson([
                'previewAudio' => [
                    'id' => $response->json('previewAudio.id'),
                    'filename' => $response->json('previewAudio.filename'),
                    'url' => '/api/study/media/'.$response->json('previewAudio.id'),
                    'mediaKind' => 'audio',
                    'source' => 'generated',
                ],
                'previewAudioRole' => 'answer',
            ]);

        $mediaAsset = MediaAsset::query()->sole();
        $this->assertSame($user->id, $mediaAsset->user_id);
        $this->assertSame('audio/mpeg', $mediaAsset->mime_type);
        $this->assertSame(strlen('ID3mp3-bytes'), $mediaAsset->size_bytes);
        $this->assertSame(hash('sha256', 'ID3mp3-bytes'), $mediaAsset->checksum_sha256);
        Storage::disk('media')->assertExists($mediaAsset->path);
        $this->assertSame('ID3mp3-bytes', Storage::disk('media')->get($mediaAsset->path));

        $draft->refresh();
        $this->assertSame($mediaAsset->id, $draft->preview_audio_json['id']);
        $this->assertSame(StudyCardAudioRole::Answer, $draft->preview_audio_role);

        $syncEntries = SyncFeedEntry::query()->orderBy('checkpoint')->get();
        $this->assertCount(2, $syncEntries);
        $this->assertSame([$user->id, $user->id], $syncEntries->pluck('user_id')->all());
        $this->assertSame(
            [MediaAssetSyncPayload::RESOURCE_TYPE, StudyCardDraftSyncPayload::RESOURCE_TYPE],
            $syncEntries->pluck('resource_type')->all(),
        );
        $this->assertSame(
            [SyncFeedOperation::Create, SyncFeedOperation::Update],
            $syncEntries->pluck('operation')->all(),
        );

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://fish.test/v1/tts'
                && $request->hasHeader('Authorization', 'Bearer fish-test-key')
                && $request->hasHeader('model', 's1')
                && $data['text'] === 'かいしゃ'
                && $data['reference_id'] === 'abb4362e736f40b7b5716f4fafcafa9f'
                && $data['format'] === 'mp3'
                && $data['prosody'] === ['speed' => 1, 'volume' => 0];
        });
    }

    public function test_it_generates_prompt_audio_for_audio_recognition_using_its_fallback_order(): void
    {
        Http::fake([
            'fish.test/v1/tts' => Http::response('ID3audio-recognition-bytes'),
        ]);
        $user = $this->signIn();
        $draft = $this->readyDraft($user, [
            'creation_kind' => StudyCardCreationKind::AudioRecognition,
            'answer_json' => [
                'expression' => '株式会社',
                'expressionReading' => 'かぶしきがいしゃ',
                'meaning' => 'corporation',
                'answerAudioVoiceId' => self::FISH_VOICE_ID,
            ],
        ]);

        $this->postJson("/api/study/card-drafts/{$draft->id}/preview-audio")
            ->assertOk()
            ->assertJsonPath('previewAudioRole', 'prompt');

        $this->assertSame(StudyCardAudioRole::Prompt, $draft->refresh()->preview_audio_role);
        Http::assertSent(fn (Request $request): bool => $request->data()['text'] === '株式会社');
    }

    public function test_it_generates_and_persists_a_guarded_webp_preview_image(): void
    {
        $webpBytes = $this->webpBytes();
        Http::fake([
            'openai.test/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode($webpBytes)]],
                'output_format' => 'webp',
            ]),
        ]);
        $user = $this->signIn();
        $draft = $this->readyDraft($user, [
            'image_placement' => StudyCardImagePlacement::Prompt,
            'image_prompt' => 'A commuter entering a Tokyo office.',
        ]);

        $response = $this->postJson("/api/study/card-drafts/{$draft->id}/preview-image");

        $response
            ->assertOk()
            ->assertJsonPath('imagePrompt', 'A commuter entering a Tokyo office.')
            ->assertJsonPath('imagePlacement', 'prompt')
            ->assertJsonPath('previewImage.mediaKind', 'image')
            ->assertJsonPath('previewImage.source', 'generated');

        $mediaAsset = MediaAsset::query()->sole();
        $this->assertSame('image/webp', $mediaAsset->mime_type);
        $this->assertStringEndsWith('.webp', $mediaAsset->original_filename);
        $this->assertSame($webpBytes, Storage::disk('media')->get($mediaAsset->path));
        $this->assertSame($mediaAsset->id, $draft->refresh()->preview_image_json['id']);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://openai.test/v1/images/generations'
                && $request->hasHeader('Authorization', 'Bearer openai-test-key')
                && $data['model'] === 'gpt-image-1'
                && $data['output_format'] === 'webp'
                && str_starts_with($data['prompt'], 'A commuter entering a Tokyo office.')
                && str_contains($data['prompt'], OpenAiStudyImageGenerator::PROMPT_GUARDRAIL);
        });
    }

    public function test_it_rejects_missing_audio_text_without_calling_a_provider_or_writing_media(): void
    {
        Http::fake();
        $user = $this->signIn();
        $draft = $this->readyDraft($user, [
            'answer_json' => [
                'answerAudioVoiceId' => self::FISH_VOICE_ID,
            ],
        ]);

        $this->postJson("/api/study/card-drafts/{$draft->id}/preview-audio")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answer']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_it_rejects_an_invalid_audio_voice_before_calling_the_provider(): void
    {
        Http::fake();
        $user = $this->signIn();
        $draft = $this->readyDraft($user, [
            'answer_json' => [
                'expression' => '会社',
                'answerAudioVoiceId' => 'ja-JP-Wavenet-D',
            ],
        ]);

        $this->postJson("/api/study/card-drafts/{$draft->id}/preview-audio")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answer.answerAudioVoiceId']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_enforces_the_provider_audio_text_limit_before_side_effects(): void
    {
        Http::fake();
        $user = $this->signIn();
        $draft = $this->readyDraft($user, [
            'answer_json' => [
                'expression' => str_repeat('a', FishAudioSpeechGenerator::MAX_TEXT_LENGTH + 1),
                'answerAudioVoiceId' => self::FISH_VOICE_ID,
            ],
        ]);

        $this->postJson("/api/study/card-drafts/{$draft->id}/preview-audio")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answer']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_image_generation_without_a_prompt_or_active_placement(): void
    {
        Http::fake();
        $user = $this->signIn();
        $withoutPlacement = $this->readyDraft($user, [
            'image_placement' => StudyCardImagePlacement::None,
            'image_prompt' => 'A scene.',
        ]);
        $withoutPrompt = $this->readyDraft($user, [
            'image_placement' => StudyCardImagePlacement::Answer,
            'image_prompt' => null,
        ]);

        $this->postJson("/api/study/card-drafts/{$withoutPlacement->id}/preview-image")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['imagePlacement']);
        $this->postJson("/api/study/card-drafts/{$withoutPrompt->id}/preview-image")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['imagePrompt']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_hides_cross_user_and_malformed_draft_ids_before_provider_calls(): void
    {
        Http::fake();
        $this->signIn();
        $otherDraft = $this->readyDraft(User::factory()->create());

        $this->postJson("/api/study/card-drafts/{$otherDraft->id}/preview-audio")
            ->assertNotFound();
        $this->postJson('/api/study/card-drafts/not-a-ulid/preview-image')
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_it_requires_authentication_and_rejects_unknown_body_fields(): void
    {
        Http::fake();
        $draft = $this->readyDraft(User::factory()->create());

        $this->postJson("/api/study/card-drafts/{$draft->id}/preview-audio")
            ->assertUnauthorized();

        $this->signIn($draft->user);
        $this->postJson("/api/study/card-drafts/{$draft->id}/preview-audio", ['voiceId' => 'override'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['voiceId']);

        Http::assertNothingSent();
    }

    public function test_it_maps_provider_credentials_rate_limits_and_invalid_output_without_leaking_details(): void
    {
        $user = $this->signIn();
        $audioDraft = $this->readyDraft($user);
        $imageDraft = $this->readyDraft($user, [
            'image_placement' => StudyCardImagePlacement::Both,
            'image_prompt' => 'A quiet station platform.',
        ]);

        Http::fake([
            'fish.test/v1/tts' => Http::response(['message' => 'secret credential detail'], 401),
            'openai.test/v1/images/generations' => Http::response([
                'error' => ['message' => 'quota detail'],
            ], 429),
        ]);

        $this->postJson("/api/study/card-drafts/{$audioDraft->id}/preview-audio")
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Fish Audio preview generation is unavailable.']);
        $this->postJson("/api/study/card-drafts/{$imageDraft->id}/preview-image")
            ->assertTooManyRequests()
            ->assertExactJson(['message' => 'OpenAI is rate limiting preview generation. Please try again shortly.']);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_it_rejects_invalid_provider_output_without_persisting_it(): void
    {
        $user = $this->signIn();
        $audioDraft = $this->readyDraft($user);
        $imageDraft = $this->readyDraft($user, [
            'image_placement' => StudyCardImagePlacement::Both,
            'image_prompt' => 'A quiet station platform.',
        ]);
        Http::fake([
            'fish.test/v1/tts' => Http::response('{"message":"not audio"}'),
            'openai.test/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode('not-webp')]],
            ]),
        ]);

        $this->postJson("/api/study/card-drafts/{$audioDraft->id}/preview-audio")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Fish Audio returned invalid preview media.']);
        $this->postJson("/api/study/card-drafts/{$imageDraft->id}/preview-image")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'OpenAI returned invalid preview media.']);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_it_discards_generated_media_when_the_draft_becomes_uneditable_during_generation(): void
    {
        $user = $this->signIn();
        $draft = $this->readyDraft($user);
        Http::fake(function () use ($draft) {
            StudyCardDraft::query()
                ->whereKey($draft->id)
                ->update(['status' => StudyManualCardDraftStatus::Generating->value]);

            return Http::response('ID3raced-audio-bytes');
        });

        $this->postJson("/api/study/card-drafts/{$draft->id}/preview-audio")
            ->assertConflict()
            ->assertExactJson(['message' => 'Generating drafts cannot be edited yet.']);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
        $this->assertSame(StudyManualCardDraftStatus::Generating, $draft->refresh()->status);

        $syncEntries = SyncFeedEntry::query()
            ->where('resource_type', MediaAssetSyncPayload::RESOURCE_TYPE)
            ->orderBy('checkpoint')
            ->get();
        $this->assertCount(2, $syncEntries);
        $this->assertSame([$user->id, $user->id], $syncEntries->pluck('user_id')->all());
        $this->assertSame(
            [SyncFeedOperation::Create, SyncFeedOperation::Delete],
            $syncEntries->pluck('operation')->all(),
        );
        $this->assertSame($syncEntries[0]->resource_id, $syncEntries[1]->resource_id);
    }

    public function test_audio_and_image_generation_share_one_user_scoped_rate_limit(): void
    {
        Http::fake([
            'fish.test/v1/tts' => Http::response('ID3first-audio'),
            'openai.test/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode($this->webpBytes())]],
            ]),
        ]);
        $user = $this->signIn();
        $audioDraft = $this->readyDraft($user);
        $imageDraft = $this->readyDraft($user, [
            'image_placement' => StudyCardImagePlacement::Prompt,
            'image_prompt' => 'A scene.',
        ]);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson("/api/study/card-drafts/{$audioDraft->id}/preview-audio")
                ->assertOk();
        }
        $this->postJson("/api/study/card-drafts/{$imageDraft->id}/preview-image")
            ->assertTooManyRequests();

        Http::assertSentCount(10);
    }

    private function readyDraft(User $user, array $attributes = []): StudyCardDraft
    {
        return StudyCardDraft::factory()
            ->ready()
            ->for($user)
            ->create([
                'answer_json' => [
                    'expression' => '会社',
                    'answerAudioVoiceId' => self::FISH_VOICE_ID,
                ],
                'preview_audio_json' => null,
                'preview_audio_role' => null,
                'preview_image_json' => null,
                ...$attributes,
            ]);
    }

    private function webpBytes(): string
    {
        return 'RIFF'."\x04\x00\x00\x00".'WEBP';
    }
}
