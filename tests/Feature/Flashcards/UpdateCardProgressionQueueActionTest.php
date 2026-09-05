<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsCardSyncFeedEntries;
use Tests\TestCase;

class UpdateCardProgressionQueueActionTest extends TestCase
{
    use AssertsCardSyncFeedEntries;
    use RefreshDatabase;

    public function test_it_adds_new_cards_to_the_queue_when_they_become_progression_available(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $this->cardFor($user, ['deck_id' => $deck->id, 'new_queue_position' => 4]);
        $card = $this->cardFor($user, [
            'deck_id' => $deck->id,
            'new_queue_position' => null,
            'variant_status' => VocabVariantStatus::Locked,
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: $card->front_text,
                backText: $card->back_text,
                hasVariantStatus: true,
                variantStatus: VocabVariantStatus::Available,
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(VocabVariantStatus::Available->value, $card->variant_status);
        $this->assertSame(5, $card->new_queue_position);
        $this->assertSame(5, $this->assertCardSyncPayloadRecorded(
            $card->refresh(),
            SyncFeedOperation::Update,
        )->payload['new_queue_position']);
    }

    public function test_it_removes_new_cards_from_the_queue_when_they_become_locked(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'new_queue_position' => 4,
            'variant_status' => VocabVariantStatus::Available,
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: $card->front_text,
                backText: $card->back_text,
                hasVariantStatus: true,
                variantStatus: VocabVariantStatus::Locked,
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(VocabVariantStatus::Locked->value, $card->variant_status);
        $this->assertNull($card->new_queue_position);
        $this->assertNull($this->assertCardSyncPayloadRecorded(
            $card->refresh(),
            SyncFeedOperation::Update,
        )->payload['new_queue_position']);
    }
}
