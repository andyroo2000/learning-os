<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Services\OpenAiStudyImageGenerator;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class RegenerateStudyCardImageApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config([
            'services.openai.api_key' => 'openai-test-key',
            'services.openai.base_url' => 'https://openai.test/v1',
            'services.openai.study_image_model' => 'gpt-image-1',
        ]);
    }

    public function test_it_regenerates_a_card_image_and_returns_the_compatibility_shape(): void
    {
        Http::fake([
            'openai.test/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode($this->webpBytes())]],
            ]),
        ]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user);

        $response = $this->postJson("/api/study/cards/{$card->id}/regenerate-image", [
            'imagePrompt' => 'A commuter entering a Tokyo office.',
            'imageRole' => 'both',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('id', $card->id)
            ->assertJsonPath('prompt.cueImage.mediaKind', 'image')
            ->assertJsonPath('prompt.cueImage.source', 'generated')
            ->assertJsonPath('answer.answerImage.mediaKind', 'image')
            ->assertJsonPath('answer.answerImage.source', 'generated');
        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $media = MediaAsset::query()->sole();
        $card->refresh();
        $this->assertSame($media->id, $card->prompt_json['cueImage']['id']);
        $this->assertSame($media->id, $card->answer_json['answerImage']['id']);
        $this->assertSame([$media->id], $card->mediaAssets()->pluck('media_assets.id')->all());
        $this->assertSame('image/webp', $media->mime_type);
        $this->assertSame($this->webpBytes(), Storage::disk('media')->get($media->path));

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://openai.test/v1/images/generations'
                && $request->hasHeader('Authorization', 'Bearer openai-test-key')
                && $data['model'] === 'gpt-image-1'
                && $data['output_format'] === 'webp'
                && str_starts_with($data['prompt'], 'A commuter entering a Tokyo office.')
                && str_contains($data['prompt'], OpenAiStudyImageGenerator::PROMPT_GUARDRAIL);
        });

        $this->assertSyncEntry(
            $user->id,
            MediaAssetSyncPayload::DOMAIN,
            MediaAssetSyncPayload::RESOURCE_TYPE,
            $media->id,
            SyncFeedOperation::Create,
        );
        $this->assertSyncEntry(
            $user->id,
            CardSyncPayload::DOMAIN,
            CardSyncPayload::RESOURCE_TYPE,
            $card->id,
            SyncFeedOperation::Update,
        );
        $this->assertSyncEntry(
            $user->id,
            CardMediaSyncPayload::DOMAIN,
            CardMediaSyncPayload::RESOURCE_TYPE,
            CardMediaSyncPayload::resourceId($card->id, $media->id),
            SyncFeedOperation::Create,
        );
    }

    public function test_it_places_the_image_on_only_the_requested_side(): void
    {
        Http::fake([
            'openai.test/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode($this->webpBytes())]],
            ]),
        ]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'prompt_json' => [
                'type' => 'text',
                'text' => '会社',
                'cueImage' => ['id' => 'imported-prompt'],
            ],
            'answer_json' => [
                'type' => 'text',
                'text' => 'company',
                'answerImage' => ['id' => 'imported-answer'],
            ],
        ]);

        $response = $this->postJson("/api/study/cards/{$card->id}/regenerate-image", [
            'imagePrompt' => 'An office exterior.',
            'imageRole' => 'answer',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('prompt.cueImage', null)
            ->assertJsonFragment(['cueImage' => null])
            ->assertJsonPath('answer.answerImage.source', 'generated');
        $this->assertNull($card->refresh()->prompt_json['cueImage']);
        $this->assertSame('generated', $card->answer_json['answerImage']['source']);
    }

    public function test_it_replaces_and_deletes_unreferenced_generated_images(): void
    {
        Http::fake([
            'openai.test/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode($this->webpBytes())]],
            ]),
        ]);
        $user = $this->signIn();
        $oldMedia = $this->generatedImageFor($user, 'study/generated/old.webp');
        $oldReference = $this->mediaReference($oldMedia, 'generated');
        $card = $this->studyCardFor($user, [
            'prompt_json' => ['type' => 'text', 'text' => '会社', 'cueImage' => $oldReference],
            'answer_json' => ['type' => 'text', 'text' => 'company', 'answerImage' => $oldReference],
        ]);
        $card->mediaAssets()->attach($oldMedia);

        $response = $this->postJson("/api/study/cards/{$card->id}/regenerate-image", [
            'imagePrompt' => 'A new office scene.',
            'imageRole' => 'prompt',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('prompt.cueImage.source', 'generated')
            ->assertJsonPath('answer.answerImage', null);
        $newMediaId = $response->json('prompt.cueImage.id');
        $this->assertNotSame($oldMedia->id, $newMediaId);
        $this->assertDatabaseMissing('media_assets', ['id' => $oldMedia->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $newMediaId]);
        Storage::disk('media')->assertMissing($oldMedia->path);
        $this->assertSame([$newMediaId], $card->mediaAssets()->pluck('media_assets.id')->all());

        $this->assertSyncEntry(
            $user->id,
            MediaAssetSyncPayload::DOMAIN,
            MediaAssetSyncPayload::RESOURCE_TYPE,
            $oldMedia->id,
            SyncFeedOperation::Delete,
        );
        $this->assertSyncEntry(
            $user->id,
            CardMediaSyncPayload::DOMAIN,
            CardMediaSyncPayload::RESOURCE_TYPE,
            CardMediaSyncPayload::resourceId($card->id, $oldMedia->id),
            SyncFeedOperation::Delete,
        );
    }

    public function test_it_keeps_replaced_generated_media_that_another_card_still_references(): void
    {
        Http::fake([
            'openai.test/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode($this->webpBytes())]],
            ]),
        ]);
        $user = $this->signIn();
        $oldMedia = $this->generatedImageFor($user, 'study/generated/shared.webp');
        $oldReference = $this->mediaReference($oldMedia, 'generated');
        $card = $this->studyCardFor($user, [
            'prompt_json' => ['type' => 'text', 'text' => '会社', 'cueImage' => $oldReference],
        ]);
        $otherCard = $this->studyCardFor($user, [
            'prompt_json' => ['type' => 'text', 'text' => '企業', 'cueImage' => $oldReference],
        ]);
        $card->mediaAssets()->attach($oldMedia);
        $otherCard->mediaAssets()->attach($oldMedia);

        $this->postJson("/api/study/cards/{$card->id}/regenerate-image", [
            'imagePrompt' => 'A new office.',
            'imageRole' => 'prompt',
        ])->assertOk();

        $this->assertDatabaseHas('media_assets', ['id' => $oldMedia->id]);
        Storage::disk('media')->assertExists($oldMedia->path);
        $this->assertFalse($card->mediaAssets()->whereKey($oldMedia->id)->exists());
        $this->assertTrue($otherCard->mediaAssets()->whereKey($oldMedia->id)->exists());
        $this->assertSame(
            0,
            SyncFeedEntry::query()
                ->where('domain', MediaAssetSyncPayload::DOMAIN)
                ->where('resource_type', MediaAssetSyncPayload::RESOURCE_TYPE)
                ->where('resource_id', $oldMedia->id)
                ->where('operation', SyncFeedOperation::Delete->value)
                ->count(),
        );
    }

    public function test_it_preserves_imported_media_attachments_when_replacing_payload_images(): void
    {
        Http::fake([
            'openai.test/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode($this->webpBytes())]],
            ]),
        ]);
        $user = $this->signIn();
        $importedMedia = MediaAsset::factory()->for($user)->create([
            'mime_type' => 'image/png',
            'path' => 'study/imported/company.png',
            'original_filename' => 'company.png',
        ]);
        Storage::disk('media')->put($importedMedia->path, 'imported-image');
        $card = $this->studyCardFor($user, [
            'prompt_json' => [
                'type' => 'text',
                'text' => '会社',
                'cueImage' => $this->mediaReference($importedMedia, 'imported'),
            ],
        ]);
        $card->mediaAssets()->attach($importedMedia);

        $response = $this->postJson("/api/study/cards/{$card->id}/regenerate-image", [
            'imagePrompt' => 'A new company building.',
            'imageRole' => 'answer',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('prompt.cueImage', null)
            ->assertJsonFragment(['cueImage' => null]);
        $this->assertDatabaseHas('media_assets', ['id' => $importedMedia->id]);
        Storage::disk('media')->assertExists($importedMedia->path);
        $this->assertTrue(
            $card->mediaAssets()->whereKey($importedMedia->id)->exists(),
            'Imported media may still support raw imported fields and must stay attached.',
        );
    }

    public function test_it_normalizes_legacy_null_payloads_before_adding_an_image(): void
    {
        Http::fake([
            'openai.test/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode($this->webpBytes())]],
            ]),
        ]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'prompt_json' => null,
            'answer_json' => null,
        ]);

        $this->postJson("/api/study/cards/{$card->id}/regenerate-image", [
            'imagePrompt' => 'A simple office.',
            'imageRole' => 'prompt',
        ])
            ->assertOk()
            ->assertJsonPath('prompt.text', '会社')
            ->assertJsonPath('prompt.cueImage.source', 'generated')
            ->assertJsonPath('answer.text', 'company')
            ->assertJsonPath('answer.answerImage', null);
    }

    public function test_it_accepts_an_uppercase_copied_card_uuid(): void
    {
        Http::fake([
            'openai.test/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode($this->webpBytes())]],
            ]),
        ]);
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->make([
            'front_text' => '会社',
            'back_text' => 'company',
            'prompt_json' => ['type' => 'text', 'text' => '会社'],
            'answer_json' => ['type' => 'text', 'text' => 'company'],
        ]);
        $card->convolab_id = 'c358732a-2cd0-4b18-9cce-c474297863f9';
        $card->save();

        $this->postJson('/api/study/cards/C358732A-2CD0-4B18-9CCE-C474297863F9/regenerate-image', [
            'imagePrompt' => 'An office.',
            'imageRole' => 'both',
        ])
            ->assertOk()
            ->assertJsonPath('id', 'c358732a-2cd0-4b18-9cce-c474297863f9');
    }

    public function test_it_hides_missing_cross_user_deleted_and_malformed_cards(): void
    {
        Http::fake();
        $user = $this->signIn();
        $otherCard = $this->studyCardFor(User::factory()->create());
        $deletedCard = $this->studyCardFor($user);
        $deletedCard->delete();
        $deletedDeckCard = $this->studyCardFor($user);
        $deletedDeckCard->deck()->delete();

        $payload = ['imagePrompt' => 'An office.', 'imageRole' => 'prompt'];

        $this->postJson("/api/study/cards/{$otherCard->id}/regenerate-image", $payload)
            ->assertNotFound();
        $this->postJson("/api/study/cards/{$deletedCard->id}/regenerate-image", $payload)
            ->assertNotFound();
        $this->postJson("/api/study/cards/{$deletedDeckCard->id}/regenerate-image", $payload)
            ->assertNotFound();
        $this->postJson('/api/study/cards/not-an-id/regenerate-image', $payload)
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_requires_authentication_and_strictly_validates_the_payload(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $card = $this->studyCardFor($user);

        $this->postJson("/api/study/cards/{$card->id}/regenerate-image", [
            'imagePrompt' => 'An office.',
            'imageRole' => 'prompt',
        ])->assertUnauthorized();

        $this->signIn($user);
        $this->postJson("/api/study/cards/{$card->id}/regenerate-image", [
            'imagePrompt' => ' ',
            'imageRole' => 'none',
            'unexpected' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['imagePrompt', 'imageRole', 'unexpected']);

        $this->postJson("/api/study/cards/{$card->id}/regenerate-image", [
            'imagePrompt' => str_repeat('a', StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH + 1),
            'imageRole' => 'prompt',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['imagePrompt']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_maps_provider_failures_without_leaking_details(): void
    {
        $user = $this->signIn();
        $card = $this->studyCardFor($user);
        Http::fake([
            'openai.test/v1/images/generations' => Http::response([
                'error' => ['message' => 'secret quota detail'],
            ], 429),
        ]);

        $this->postJson("/api/study/cards/{$card->id}/regenerate-image", [
            'imagePrompt' => 'An office.',
            'imageRole' => 'prompt',
        ])
            ->assertTooManyRequests()
            ->assertExactJson([
                'message' => 'OpenAI is rate limiting preview generation. Please try again shortly.',
            ]);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_it_cleans_up_generated_media_when_the_card_changes_during_generation(): void
    {
        $user = $this->signIn();
        $card = $this->studyCardFor($user);
        Http::fake(function () use ($card) {
            $card->forceFill(['back_text' => 'changed concurrently'])->save();

            return Http::response([
                'data' => [['b64_json' => base64_encode($this->webpBytes())]],
            ]);
        });

        $this->postJson("/api/study/cards/{$card->id}/regenerate-image", [
            'imagePrompt' => 'An office.',
            'imageRole' => 'prompt',
        ])
            ->assertConflict()
            ->assertExactJson([
                'message' => 'The study card changed while its image was being generated. Please retry.',
            ]);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_image_regeneration_consumes_the_shared_generation_budget(): void
    {
        Http::fake([
            'openai.test/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode($this->webpBytes())]],
            ]),
        ]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user);
        $payload = ['imagePrompt' => 'An office.', 'imageRole' => 'prompt'];

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson("/api/study/cards/{$card->id}/regenerate-image", $payload)
                ->assertOk();
        }

        $this->postJson("/api/study/cards/{$card->id}/regenerate-image", $payload)
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
    private function studyCardFor(User $user, array $attributes = []): Card
    {
        return Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '会社',
            'back_text' => 'company',
            'prompt_json' => ['type' => 'text', 'text' => '会社', 'cueImage' => null],
            'answer_json' => ['type' => 'text', 'text' => 'company', 'answerImage' => null],
            ...$attributes,
        ]);
    }

    private function generatedImageFor(User $user, string $path): MediaAsset
    {
        $media = MediaAsset::factory()->for($user)->create([
            'disk' => 'media',
            'path' => $path,
            'mime_type' => 'image/webp',
            'original_filename' => basename($path),
        ]);
        Storage::disk('media')->put($path, 'old-webp');

        return $media;
    }

    /**
     * @return array{id: string, filename: string, url: string, mediaKind: 'image', source: string}
     */
    private function mediaReference(MediaAsset $media, string $source): array
    {
        return [
            'id' => $media->id,
            'filename' => $media->original_filename,
            'url' => "/api/study/media/{$media->id}",
            'mediaKind' => 'image',
            'source' => $source,
        ];
    }

    private function webpBytes(): string
    {
        return 'RIFF'."\x04\x00\x00\x00".'WEBP';
    }

    private function assertSyncEntry(
        int $userId,
        string $domain,
        string $resourceType,
        string $resourceId,
        SyncFeedOperation $operation,
    ): void {
        $entry = SyncFeedEntry::query()
            ->where('user_id', $userId)
            ->where('domain', $domain)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->where('operation', $operation->value)
            ->sole();

        $this->assertSame($userId, $entry->user_id);
        $this->assertSame($domain, $entry->domain);
        $this->assertSame($resourceType, $entry->resource_type);
        $this->assertSame($resourceId, $entry->resource_id);
        $this->assertSame($operation, $entry->operation);
    }
}
