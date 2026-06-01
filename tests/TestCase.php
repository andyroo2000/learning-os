<?php

namespace Tests;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create();

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function deckFor(User $user, array $attributes = []): Deck
    {
        return Deck::factory()
            ->for($user)
            ->create($attributes);
    }

    protected function cardFor(User $user): Card
    {
        return Card::factory()
            ->for($this->deckFor($user))
            ->create();
    }
}
