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

    public function test_it_hides_another_users_card_when_updating(): void
    {
        $user = User::factory()->create();
        $card = Card::factory()->create();

        $response = Gate::forUser($user)->inspect('update', $card);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }
}
