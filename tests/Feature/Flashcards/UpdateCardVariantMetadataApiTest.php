<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Carbon;

class UpdateCardVariantMetadataApiTest extends UpdateCardApiTestCase
{
    public function test_it_updates_variant_metadata(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'front_text' => '会社',
            'back_text' => 'company',
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->putJson("/api/cards/{$card->id}", [
                'front_text' => '  会社  ',
                'back_text' => '  company  ',
                'variant_group_id' => ' vocab-group-1 ',
                'variant_sentence_id' => ' sentence-1 ',
                'variant_kind' => ' SENTENCE_CLOZE ',
                'variant_stage' => ' +3 ',
                'variant_status' => ' AVAILABLE ',
                'variant_unlocked_at' => '2026-06-04T14:15:30.123456+05:30',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.front_text', '会社')
            ->assertJsonPath('data.back_text', 'company')
            ->assertJsonPath('data.variant_group_id', 'vocab-group-1')
            ->assertJsonPath('data.variant_sentence_id', 'sentence-1')
            ->assertJsonPath('data.variant_kind', VocabVariantKind::SentenceCloze->value)
            ->assertJsonPath('data.variant_stage', 3)
            ->assertJsonPath('data.variant_status', VocabVariantStatus::Available->value)
            ->assertJsonPath('data.new_queue_position', 1)
            ->assertJsonPath('data.variant_unlocked_at', '2026-06-04T08:45:30.000000Z');

        $card->refresh();
        $this->assertSame('vocab-group-1', $card->variant_group_id);
        $this->assertSame('sentence-1', $card->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceCloze->value, $card->variant_kind);
        $this->assertSame(3, $card->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $card->variant_status);
        $this->assertSame(1, $card->new_queue_position);
        $this->assertSame('2026-06-04T08:45:30.000000Z', $card->variant_unlocked_at?->toJSON());

        $entry = SyncFeedEntry::query()->sole();
        $this->assertEquals(CardSyncPayload::fromCard($card), $entry->payload);
    }

    public function test_it_clears_variant_metadata(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'old-group',
            'variant_sentence_id' => 'old-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
            'variant_retired_at' => Carbon::parse('2026-06-05T15:15:00Z'),
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->putJson("/api/cards/{$card->id}", [
                'front_text' => '会社',
                'back_text' => 'company',
                'variant_group_id' => '   ',
                'variant_sentence_id' => "\t",
                'variant_kind' => null,
                'variant_stage' => null,
                'variant_status' => null,
                'variant_unlocked_at' => null,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.variant_group_id', null)
            ->assertJsonPath('data.variant_sentence_id', null)
            ->assertJsonPath('data.variant_kind', null)
            ->assertJsonPath('data.variant_stage', null)
            ->assertJsonPath('data.variant_status', null)
            ->assertJsonPath('data.variant_unlocked_at', null);

        $card->refresh();
        $this->assertNull($card->variant_group_id);
        $this->assertNull($card->variant_retired_at);
        $this->assertNull($card->variant_sentence_id);
        $this->assertNull($card->variant_kind);
        $this->assertNull($card->variant_stage);
        $this->assertNull($card->variant_status);
        $this->assertNull($card->variant_unlocked_at);

        $entry = SyncFeedEntry::query()->sole();
        $this->assertEquals(CardSyncPayload::fromCard($card), $entry->payload);
    }

    public function test_resubmitting_unchanged_variant_metadata_preserves_retirement(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'vocab-group-1',
            'variant_stage' => 3,
            'variant_status' => VocabVariantStatus::Available,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
            'variant_retired_at' => Carbon::parse('2026-06-05T15:15:00Z'),
            'new_queue_position' => 1,
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'vocab-group-1',
            'variant_stage' => 3,
            'variant_status' => VocabVariantStatus::Available->value,
            'variant_unlocked_at' => '2026-06-05T14:15:00Z',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.variant_retired_at',
                '2026-06-05T15:15:00.000000Z',
            );

        $card->refresh();
        $this->assertSame('2026-06-05T15:15:00.000000Z', $card->variant_retired_at?->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
