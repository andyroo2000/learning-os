<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Actions\PersistUploadedStudyImageAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class UploadStudyCardImageApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
    }

    public function test_it_uploads_and_places_a_user_image_on_the_card(): void
    {
        $user = $this->signIn();
        $card = $this->studyCardFor($user);

        $response = $this->post("/api/study/cards/{$card->id}/image", [
            'imageRole' => 'both',
            'image' => $this->jpegUpload(),
        ], ['Accept' => 'application/json']);

        $response
            ->assertOk()
            ->assertJsonPath('id', $card->id)
            ->assertJsonPath('prompt.cueImage.source', 'imported_image')
            ->assertJsonPath('answer.answerImage.source', 'imported_image')
            ->assertJsonPath('revision', 1);
        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $media = MediaAsset::query()->sole();
        $card->refresh();
        $this->assertSame(1, $card->content_revision);
        $this->assertSame($media->id, $card->prompt_json['cueImage']['id']);
        $this->assertSame($media->id, $card->answer_json['answerImage']['id']);
        $this->assertSame('image/jpeg', $media->mime_type);
        $this->assertSame('camera.jpg', $media->original_filename);
        $this->assertSame([$media->id], $card->mediaAssets()->pluck('media_assets.id')->all());
        Storage::disk('media')->assertExists($media->path);
    }

    public function test_it_uploads_an_image_for_a_structured_card_with_blank_legacy_text(): void
    {
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'front_text' => '',
            'back_text' => '',
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['expression' => '会社', 'meaning' => 'company'],
        ]);

        $this->post("/api/study/cards/{$card->id}/image", [
            'imageRole' => 'both',
            'image' => $this->jpegUpload(),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('prompt.cueImage.source', 'imported_image')
            ->assertJsonPath('answer.answerImage.source', 'imported_image');

        $card->refresh();
        $this->assertSame('', $card->front_text);
        $this->assertSame('', $card->back_text);
        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_replaces_and_deletes_an_unreferenced_managed_image(): void
    {
        $user = $this->signIn();
        $oldMedia = MediaAsset::factory()->for($user)->create([
            'mime_type' => 'image/png',
            'path' => 'study/uploads/'.$user->id.'/old.png',
            'original_filename' => 'old.png',
        ]);
        Storage::disk('media')->put($oldMedia->path, 'old-image');
        $oldReference = [
            'id' => $oldMedia->id,
            'filename' => 'old.png',
            'url' => "/api/study/media/{$oldMedia->id}",
            'mediaKind' => 'image',
            'source' => 'imported_image',
        ];
        $card = $this->studyCardFor($user, [
            'prompt_json' => ['type' => 'text', 'text' => '会社', 'cueImage' => $oldReference],
        ]);
        $card->mediaAssets()->attach($oldMedia);

        $response = $this->post("/api/study/cards/{$card->id}/image", [
            'imageRole' => 'answer',
            'image' => $this->jpegUpload(),
        ], ['Accept' => 'application/json']);

        $response
            ->assertOk()
            ->assertJsonPath('prompt.cueImage', null)
            ->assertJsonPath('answer.answerImage.source', 'imported_image');
        $this->assertDatabaseMissing('media_assets', ['id' => $oldMedia->id]);
        Storage::disk('media')->assertMissing($oldMedia->path);
        $this->assertFalse($card->mediaAssets()->whereKey($oldMedia->id)->exists());
    }

    public function test_it_rejects_invalid_files_and_none_placement(): void
    {
        $user = $this->signIn();
        $card = $this->studyCardFor($user);

        $this->post("/api/study/cards/{$card->id}/image", [
            'imageRole' => 'none',
            'image' => UploadedFile::fake()->createWithContent('notes.txt', 'not an image'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image', 'imageRole']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_hides_cards_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $card = $this->studyCardFor($owner);
        $this->signIn();

        $this->post("/api/study/cards/{$card->id}/image", [
            'imageRole' => 'answer',
            'image' => $this->jpegUpload(),
        ], ['Accept' => 'application/json'])
            ->assertNotFound();

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_cleans_up_uploaded_media_when_the_card_changes_during_upload(): void
    {
        $user = $this->signIn();
        $card = $this->studyCardFor($user);
        $persistUploadedImage = resolve(PersistUploadedStudyImageAction::class);

        $this->mock(PersistUploadedStudyImageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (int $userId, UploadedFile $image) use (
                $persistUploadedImage,
                $card,
            ) {
                $uploaded = $persistUploadedImage->handle($userId, $image);
                $card->forceFill(['back_text' => 'changed concurrently'])->save();

                return $uploaded;
            });

        $this->post("/api/study/cards/{$card->id}/image", [
            'imageRole' => 'answer',
            'image' => $this->jpegUpload(),
        ], ['Accept' => 'application/json'])
            ->assertConflict()
            ->assertExactJson([
                'message' => 'The study card changed while its image was being generated. Please retry.',
            ]);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
        $this->assertSame('changed concurrently', $card->refresh()->back_text);
        $this->assertNull($card->answer_json['answerImage']);
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

    private function jpegUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'camera.jpg',
            (string) base64_decode(
                '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/AX//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/AX//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IV//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z',
                true,
            ),
        );
    }
}
