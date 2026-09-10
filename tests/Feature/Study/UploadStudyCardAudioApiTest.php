<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Actions\PersistUploadedStudyAudioAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class UploadStudyCardAudioApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
    }

    public function test_it_turns_an_owned_recognition_card_into_an_audio_recognition_card(): void
    {
        $user = $this->signIn();
        $card = $this->studyCardFor($user);

        $response = $this->post("/api/study/cards/{$card->id}/audio", [
            'audio' => $this->wavUpload(),
        ], ['Accept' => 'application/json']);

        $response
            ->assertOk()
            ->assertJsonPath('id', $card->id)
            ->assertJsonMissingPath('prompt.cueText')
            ->assertJsonPath('prompt.cueAudio.source', 'imported')
            ->assertJsonPath('answer.answerAudio.source', 'imported')
            ->assertJsonPath('answer.expression', '今日は行けない。')
            ->assertJsonPath('answer.meaning', 'I cannot go today.')
            ->assertJsonPath('answerAudioSource', 'imported')
            ->assertJsonPath('revision', 1);
        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $media = MediaAsset::query()->sole();
        $card->refresh();
        $this->assertSame($media->id, $card->prompt_json['cueAudio']['id']);
        $this->assertSame($media->id, $card->answer_json['answerAudio']['id']);
        $this->assertSame('audio/wav', $media->mime_type);
        $this->assertSame('dialogue.wav', $media->original_filename);
        $this->assertSame([$media->id], $card->mediaAssets()->pluck('media_assets.id')->all());
        Storage::disk('media')->assertExists($media->path);
    }

    public function test_capture_workflow_creates_uploads_and_promotes_the_card_to_the_queue_front(): void
    {
        $user = $this->signIn();
        $existing = $this->studyCardFor($user, ['new_queue_position' => 1]);
        $cardId = (string) Str::ulid();

        $this->postJson('/api/study/cards', [
            'id' => $cardId,
            'creationKind' => 'audio-recognition',
            'prompt' => ['cueText' => '今日は行けない。'],
            'answer' => [
                'expression' => '今日は行けない。',
                'meaning' => 'I cannot go today.',
                'sentenceJp' => '今日は行けない。',
                'sentenceEn' => 'I cannot go today.',
                'notes' => 'Captured from Example — https://www.youtube.com/watch?v=example',
            ],
        ])->assertCreated();

        $this->post("/api/study/cards/{$cardId}/audio", [
            'audio' => $this->wavUpload(),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonMissingPath('prompt.cueText')
            ->assertJsonPath('prompt.cueAudio.mediaKind', 'audio');

        $this->postJson("/api/study/new-queue/{$cardId}/promote")
            ->assertOk()
            ->assertJsonPath('items.0.id', strtolower($cardId));

        $this->assertLessThan(
            $existing->refresh()->new_queue_position,
            Card::query()->findOrFail(strtolower($cardId))->new_queue_position,
        );
    }

    public function test_it_replaces_and_deletes_unreferenced_managed_audio(): void
    {
        $user = $this->signIn();
        $oldMedia = MediaAsset::factory()->for($user)->create([
            'mime_type' => 'audio/wav',
            'path' => "study/uploads/{$user->id}/old.wav",
            'original_filename' => 'old.wav',
        ]);
        Storage::disk('media')->put($oldMedia->path, $this->wavBytes());
        $oldReference = [
            'id' => $oldMedia->id,
            'filename' => 'old.wav',
            'url' => "/api/study/media/{$oldMedia->id}",
            'mediaKind' => 'audio',
            'source' => 'imported',
        ];
        $card = $this->studyCardFor($user, [
            'prompt_json' => ['cueAudio' => $oldReference],
            'answer_json' => [
                'expression' => '今日は行けない。',
                'meaning' => 'I cannot go today.',
                'answerAudio' => $oldReference,
            ],
            'answer_audio_source' => 'imported',
        ]);
        $card->mediaAssets()->attach($oldMedia);

        $this->post("/api/study/cards/{$card->id}/audio", [
            'audio' => $this->wavUpload(),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertDatabaseMissing('media_assets', ['id' => $oldMedia->id]);
        Storage::disk('media')->assertMissing($oldMedia->path);
        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_rejects_invalid_audio_and_non_recognition_cards(): void
    {
        $user = $this->signIn();
        $recognition = $this->studyCardFor($user);
        $production = $this->studyCardFor($user, ['card_type' => CardType::Production]);

        $this->post("/api/study/cards/{$recognition->id}/audio", [
            'audio' => UploadedFile::fake()->createWithContent('not-audio.wav', 'not a wav'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['audio']);

        $this->post("/api/study/cards/{$production->id}/audio", [
            'audio' => $this->wavUpload(),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['audio']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_hides_cards_owned_by_another_user(): void
    {
        $card = $this->studyCardFor(User::factory()->create());
        $this->signIn();

        $this->post("/api/study/cards/{$card->id}/audio", [
            'audio' => $this->wavUpload(),
        ], ['Accept' => 'application/json'])->assertNotFound();

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_requires_authentication_before_accepting_audio(): void
    {
        $card = $this->studyCardFor(User::factory()->create());

        $this->post("/api/study/cards/{$card->id}/audio", [
            'audio' => $this->wavUpload(),
        ], ['Accept' => 'application/json'])->assertUnauthorized();

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_it_cleans_up_uploaded_media_when_the_card_changes_during_upload(): void
    {
        $user = $this->signIn();
        $card = $this->studyCardFor($user);
        $persistUploadedAudio = resolve(PersistUploadedStudyAudioAction::class);

        $this->mock(PersistUploadedStudyAudioAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (int $userId, UploadedFile $audio) use (
                $persistUploadedAudio,
                $card,
            ) {
                $uploaded = $persistUploadedAudio->handle($userId, $audio);
                $card->forceFill(['back_text' => 'changed concurrently'])->save();

                return $uploaded;
            });

        $this->post("/api/study/cards/{$card->id}/audio", [
            'audio' => $this->wavUpload(),
        ], ['Accept' => 'application/json'])
            ->assertConflict()
            ->assertExactJson([
                'message' => 'The study card changed while its audio was being uploaded. Please retry.',
            ]);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
        $this->assertSame('changed concurrently', $card->refresh()->back_text);
        $this->assertArrayNotHasKey('answerAudio', $card->answer_json);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function studyCardFor(User $user, array $attributes = []): Card
    {
        return Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '今日は行けない。',
            'back_text' => 'I cannot go today.',
            'card_type' => CardType::Recognition,
            'prompt_json' => ['cueText' => '今日は行けない。'],
            'answer_json' => [
                'expression' => '今日は行けない。',
                'meaning' => 'I cannot go today.',
            ],
            ...$attributes,
        ]);
    }

    private function wavUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('dialogue.wav', $this->wavBytes());
    }

    private function wavBytes(int $sampleRate = 16_000, int $sampleCount = 1_600): string
    {
        $data = str_repeat(pack('v', 0), $sampleCount);

        return 'RIFF'
            .pack('V', 36 + strlen($data))
            .'WAVEfmt '
            .pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16)
            .'data'
            .pack('V', strlen($data))
            .$data;
    }
}
