<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\DeleteCardAction;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_soft_deletes_a_card(): void
    {
        $card = $this->cardFor($this->signIn());

        app(DeleteCardAction::class)->handle($card);

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_it_no_ops_when_card_is_already_soft_deleted(): void
    {
        $card = $this->cardFor($this->signIn());

        $card->delete();

        app(DeleteCardAction::class)->handle($card);

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_it_retains_card_media_and_review_events(): void
    {
        $card = $this->cardFor($this->signIn());
        $mediaAsset = MediaAsset::factory()->create();
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        app(DeleteCardAction::class)->handle($card);

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewEvent->id,
            'card_id' => $card->id,
        ]);
    }
}
