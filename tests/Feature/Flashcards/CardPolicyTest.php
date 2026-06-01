<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CardPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_a_user_to_update_their_own_card(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);

        $response = Gate::forUser($user)->inspect('update', $card);

        $this->assertTrue($response->allowed());
    }

    public function test_it_allows_a_user_to_delete_their_own_card(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);

        $response = Gate::forUser($user)->inspect('delete', $card);

        $this->assertTrue($response->allowed());
    }

    public function test_it_allows_a_user_to_delete_their_already_soft_deleted_card(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);

        $card->delete();

        $response = Gate::forUser($user)->inspect('delete', $card);

        $this->assertTrue($response->allowed());
    }

    public function test_it_allows_a_user_to_delete_their_card_after_deck_cascade(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);

        // Deck soft-deletes cascade to cards; this locks in delete retry authorization.
        $card->deck->delete();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);

        $response = Gate::forUser($user)->inspect('delete', $card);

        $this->assertTrue($response->allowed());
    }

    public function test_it_hides_a_stale_card_model_after_deck_force_delete(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);

        $card->deck->forceDelete();

        $this->assertDatabaseMissing('cards', [
            'id' => $card->id,
        ]);

        $response = Gate::forUser($user)->inspect('delete', $card);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public function test_it_hides_another_users_card_when_updating(): void
    {
        $user = User::factory()->create();
        $card = Card::factory()->create();

        $response = Gate::forUser($user)->inspect('update', $card);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public function test_it_hides_a_card_with_a_soft_deleted_deck_when_updating(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user)->load('deck');

        // Guard against authorizing from a stale loaded deck relation after cascade.
        $card->deck->delete();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);

        $response = Gate::forUser($user)->inspect('update', $card);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public function test_it_hides_another_users_card_when_deleting(): void
    {
        $user = User::factory()->create();
        $card = Card::factory()->create();

        $response = Gate::forUser($user)->inspect('delete', $card);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }
}
