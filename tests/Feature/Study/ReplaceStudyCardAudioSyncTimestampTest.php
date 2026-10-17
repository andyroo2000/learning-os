<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Study\Actions\ReplaceStudyCardAudioMediaAction;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Http\UploadedFile;
use Tests\Support\Study\RegenerateStudyCardAnswerAudioTestCase;

class ReplaceStudyCardAudioSyncTimestampTest extends RegenerateStudyCardAnswerAudioTestCase
{
    public function test_sync_contains_the_final_timestamp_when_media_replacement_crosses_a_second(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = $this->signIn();
        $old = $this->generatedAudioFor($user, 'study/old.mp3');
        $card = $this->studyCardFor($user, [
            'prompt_json' => ['cueText' => '犬です。'],
            'answer_json' => ['expression' => '犬です。', 'answerAudio' => $this->audioReference($old)],
        ]);
        $card->mediaAssets()->attach($old);
        $media = app(ReplaceStudyCardAudioMediaAction::class);
        $mock = $this->mock(ReplaceStudyCardAudioMediaAction::class);
        $mock->shouldReceive('discardUnreferenced')->andReturnUsing($media->discardUnreferenced(...));
        $mock->shouldReceive('discardFailedUpload')->andReturnUsing($media->discardFailedUpload(...));
        $mock->shouldReceive('attachReplacement')->once()->andReturnUsing(function ($card, $new, $old) use ($media): void {
            $this->travel(2)->seconds();
            $media->attachReplacement($card, $new, $old);
        });

        $this->post("/api/study/cards/{$card->id}/audio", [
            'audio' => $this->wavUpload(), 'preserveCardFormat' => true,
        ], ['Accept' => 'application/json'])->assertOk();

        $entry = SyncFeedEntry::query()->where('domain', CardSyncPayload::DOMAIN)
            ->where('user_id', $user->id)->where('resource_id', $card->id)->sole();
        $this->assertEquals(CardSyncPayload::fromCard($card->fresh(['deck'])), $entry->payload);
        $this->assertSame(now()->toJSON(), $entry->payload['updated_at']);
    }

    private function wavUpload(): UploadedFile
    {
        $data = str_repeat(pack('v', 0), 1600);
        $bytes = 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '
            .pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($data)).$data;

        return UploadedFile::fake()->createWithContent('fixture.wav', $bytes);
    }
}
