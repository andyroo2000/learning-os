<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Actions\PromoteNewCardToFrontAction;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Actions\PersistUploadedStudyImageAction;
use App\Domain\Study\Exceptions\StudyCardImageValidationException;
use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class CaptureStudyCardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    public function test_it_atomically_creates_audio_and_optional_front_image_and_promotes_the_card(): void
    {
        $user = $this->signIn();
        $old = Card::factory()->for($this->deckFor($user))->create(['new_queue_position' => 1]);
        $id = (string) Str::ulid();
        $response = $this->postCapture($id, true)->assertCreated()
            ->assertJsonPath('id', strtolower($id))
            ->assertJsonMissingPath('prompt.cueText')
            ->assertJsonPath('prompt.cueImage.source', 'imported_image')
            ->assertJsonPath('prompt.cueAudio.source', 'imported')
            ->assertJsonPath('answerAudioSource', 'imported')
            ->assertJsonPath('answer.expression', '今日は行けない。');
        $card = Card::findOrFail(strtolower($id));
        $this->assertLessThan($old->new_queue_position, $card->new_queue_position);
        $this->assertSame(2, $card->mediaAssets()->count());
        $this->assertSame($response->json('prompt.cueAudio.id'), $response->json('answer.answerAudio.id'));
        foreach (MediaAsset::all() as $media) {
            Storage::disk('media')->assertExists($media->path);
        }
    }

    public function test_audio_only_capture_and_retry_do_not_duplicate_or_repromote(): void
    {
        $this->signIn();
        $id = (string) Str::ulid();
        $this->postCapture($id)->assertCreated()->assertJsonMissingPath('prompt.cueImage');
        $card = Card::findOrFail(strtolower($id));
        $card->new_queue_position = 10;
        $answer = $card->answer_json;
        ksort($answer);
        $card->answer_json = $answer;
        $card->save();
        $feedCount = DB::table('sync_feed_entries')->count();
        $this->postCapture(strtolower($id))->assertOk();
        $this->assertSame(10, $card->refresh()->new_queue_position);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('cards', 1);
        $this->assertSame($feedCount, DB::table('sync_feed_entries')->count());
    }

    public function test_image_capture_retry_is_idempotent_and_changed_image_presence_conflicts(): void
    {
        $this->signIn();
        $id = (string) Str::ulid();
        $this->postCapture($id, true)->assertCreated();
        $this->postCapture($id, true)->assertOk();
        $this->postCapture($id)->assertConflict();
        $this->assertDatabaseCount('cards', 1);
        $this->assertDatabaseCount('media_assets', 2);
    }

    #[DataProvider('captureFailures')]
    public function test_failed_capture_rolls_back_files_deck_card_and_sync_entries(string $action, Throwable $failure, int $status): void
    {
        $this->signIn();
        $feedCount = DB::table('sync_feed_entries')->count();
        $this->mock($action)->shouldReceive('handle')->once()->andThrow($failure);
        $response = $this->postCapture((string) Str::ulid(), true)->assertStatus($status);
        if ($failure instanceof StudyPreviewMediaGenerationException) {
            $response->assertJsonPath('message', $failure->getMessage());
        }
        $this->assertDatabaseCount('cards', 0);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('decks', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
        $this->assertSame($feedCount, DB::table('sync_feed_entries')->count());
    }

    public static function captureFailures(): array
    {
        return [
            'image persistence after audio' => [PersistUploadedStudyImageAction::class, StudyCardImageValidationException::invalidUpload(), 422],
            'promotion after both files' => [PromoteNewCardToFrontAction::class, new RuntimeException('Promotion unavailable'), 500],
            'image storage after audio' => [PersistUploadedStudyImageAction::class, StudyPreviewMediaGenerationException::storageFailed(), 500],
        ];
    }

    public function test_it_hides_cross_user_capture_ids_and_rejects_deleted_replays(): void
    {
        $this->signIn();
        $id = (string) Str::ulid();
        $this->postCapture($id)->assertCreated();
        Card::findOrFail(strtolower($id))->delete();
        $this->postCapture($id)->assertStatus(410);
        $this->signIn();
        $this->postCapture($id)->assertNotFound();
        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_rejects_invalid_image_and_unauthenticated_capture_without_writes(): void
    {
        $this->postCapture((string) Str::ulid())->assertUnauthorized();
        $this->signIn();
        $this->post('/api/study/cards/capture', [
            ...$this->payload((string) Str::ulid()),
            'image' => UploadedFile::fake()->createWithContent('bad.jpg', 'not an image'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('cards', 0);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    private function postCapture(string $id, bool $image = false)
    {
        $payload = $this->payload($id);
        if ($image) {
            $payload['image'] = UploadedFile::fake()->createWithContent('scene.png', base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1z8AAAAASUVORK5CYII='
            ));
        }

        return $this->post('/api/study/cards/capture', $payload, ['Accept' => 'application/json']);
    }

    private function payload(string $id): array
    {
        $data = str_repeat("\0", 3200);
        $wav = 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '
            .pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            .'data'.pack('V', strlen($data)).$data;

        return [
            'id' => $id,
            'japanese' => '今日は行けない。',
            'english' => 'I cannot go today.',
            'notes' => 'Captured from a video',
            'audio' => UploadedFile::fake()->createWithContent('dialogue.wav', $wav),
        ];
    }
}
