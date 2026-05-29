<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class DeckPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_a_user_to_view_their_own_deck(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);

        $response = Gate::forUser($user)->inspect('view', $deck);

        $this->assertTrue($response->allowed());
    }

    public function test_it_hides_another_users_deck_when_viewing(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $deck = Deck::factory()->for($otherUser)->create();

        $response = Gate::forUser($user)->inspect('view', $deck);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public function test_it_allows_a_user_to_update_their_own_deck(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);

        $response = Gate::forUser($user)->inspect('update', $deck);

        $this->assertTrue($response->allowed());
    }

    public function test_it_hides_another_users_deck_when_updating(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $deck = Deck::factory()->for($otherUser)->create();

        $response = Gate::forUser($user)->inspect('update', $deck);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }
}
