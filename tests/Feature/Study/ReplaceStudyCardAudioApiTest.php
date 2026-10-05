<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Study\RegenerateStudyCardAnswerAudioTestCase;

class ReplaceStudyCardAudioApiTest extends RegenerateStudyCardAnswerAudioTestCase
{
    #[DataProvider('cardFormats')]
    public function test_replacement_preserves_format_progress_and_history(CardType $type, array $prompt, CardStudyStatus $status): void
    {
        $user = $this->signIn();
        $old = $this->generatedAudioFor($user, 'study/old.mp3');
        $card = $this->studyCardFor($user, [
            'card_type' => $type,
            'prompt_json' => $prompt,
            'answer_json' => ['expression' => '電車はたった今出たところです。', 'meaning' => 'The train just left.', 'answerAudio' => $this->audioReference($old)],
            'answer_audio_source' => 'generated',
            'study_status' => $status,
            'new_queue_position' => 42,
            'due_at' => '2026-11-20 15:40:52',
            'scheduler_state' => ['stability' => 45.2, 'difficulty' => 4.5],
            'variant_group_id' => 'n4-example',
            'variant_stage' => 3,
            'variant_status' => 'locked',
            'variant_unlock_requirement' => 'guru',
            'variant_retired_at' => '2026-09-01 12:00:00',
        ]);
        $card->mediaAssets()->attach($old);
        $review = CardReviewEvent::factory()->for($card)->create();
        $before = $card->fresh()->getAttributes();
        $reviewBefore = $review->fresh()->getAttributes();

        $response = $this->post("/api/study/cards/{$card->id}/audio", [
            'audio' => $this->wavUpload(), 'preserveCardFormat' => '1', 'expectedRevision' => '0',
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame($prompt, $response->json('prompt'));
        $this->assertSame($type->value, $response->json('cardType'));
        $card->refresh();
        $this->assertSame($this->nonAudioAttributes($before), $this->nonAudioAttributes($card->getAttributes()));
        $this->assertSame($reviewBefore, $review->fresh()->getAttributes());
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertSame('imported', $card->answer_audio_source);
        $this->assertSame(1, $card->content_revision);
        $this->assertSame(Arr::except(json_decode($before['answer_json'], true), ['answerAudio']), Arr::except($card->answer_json, ['answerAudio']));
        $this->assertDatabaseMissing('media_assets', ['id' => $old->id]);
        Storage::disk('media')->assertMissing($old->path);
        $this->assertUpdatedCardSync($card);
    }

    public static function cardFormats(): array
    {
        return [
            'reading in review' => [CardType::Recognition, ['cueText' => '電車はたった今出たところです。', 'cueReading' => '電車[でんしゃ]はたった今出たところです。'], CardStudyStatus::Review],
            'locked new cloze' => [CardType::Cloze, ['clozeText' => '電車は{{c1::たった今}}出たところです。', 'clozeHint' => 'just now'], CardStudyStatus::New],
            'buried production' => [CardType::Production, ['cueText' => 'The train just left.', 'cueMeaning' => 'Say it in Japanese'], CardStudyStatus::Buried],
        ];
    }

    public function test_replacement_updates_existing_front_and_answer_audio_without_removing_fields(): void
    {
        $user = $this->signIn();
        $old = $this->generatedAudioFor($user, 'study/listening.mp3');
        $card = $this->studyCardFor($user, [
            'prompt_json' => ['cueAudio' => $this->audioReference($old), 'cueImage' => null],
            'answer_json' => ['expression' => '電車はたった今出たところです。', 'answerAudio' => $this->audioReference($old)],
        ]);
        $card->mediaAssets()->attach($old);

        $response = $this->post("/api/study/cards/{$card->id}/audio", [
            'audio' => $this->wavUpload(), 'preserveCardFormat' => true,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame($response->json('answer.answerAudio'), $response->json('prompt.cueAudio'));
        $this->assertArrayHasKey('cueImage', $card->fresh()->prompt_json);
        $this->assertArrayNotHasKey('cueText', $card->fresh()->prompt_json);
        $this->assertDatabaseMissing('media_assets', ['id' => $old->id]);
    }

    public function test_stale_revision_is_rejected_before_uploading(): void
    {
        $user = $this->signIn();
        $card = $this->studyCardFor($user, ['prompt_json' => ['cueText' => '電車']]);
        $card->update(['back_text' => 'Changed after loading']);

        $this->post("/api/study/cards/{$card->id}/audio", [
            'audio' => $this->wavUpload(), 'preserveCardFormat' => true, 'expectedRevision' => 0,
        ], ['Accept' => 'application/json'])
            ->assertConflict()->assertJsonPath('code', 'card_revision_conflict')->assertJsonPath('card.revision', 1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_options_do_not_write_media(array $options): void
    {
        $card = $this->studyCardFor($this->signIn(), []);
        $this->post("/api/study/cards/{$card->id}/audio", ['audio' => $this->wavUpload(), ...$options], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(array_keys($options));
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public static function invalidOptions(): array
    {
        return [[['preserveCardFormat' => 'yes']], [['preserveCardFormat' => []]], [['expectedRevision' => -1]], [['expectedRevision' => []]]];
    }

    private function nonAudioAttributes(array $attributes): array
    {
        return Arr::except($attributes, ['answer_json', 'answer_audio_source', 'content_revision', 'search_text', 'updated_at']);
    }

    private function assertUpdatedCardSync(Card $card): void
    {
        $entry = SyncFeedEntry::query()->where('domain', CardSyncPayload::DOMAIN)
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)->where('resource_id', $card->id)->sole();
        $this->assertSame($card->ownerUserId(), $entry->user_id);
        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
        $this->assertEquals(CardSyncPayload::fromCard($card->fresh(['deck'])), $entry->payload);
    }

    private function wavUpload(): UploadedFile
    {
        $data = str_repeat(pack('v', 0), 1600);
        $bytes = 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '
            .pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($data)).$data;

        return UploadedFile::fake()->createWithContent('publisher.wav', $bytes);
    }
}
