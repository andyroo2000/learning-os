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

    protected function deckFor(User $user): Deck
    {
        return Deck::factory()
            ->for($user)
            ->create();
    }

    protected function cardFor(User $user): Card
    {
        return Card::factory()
            ->for($this->deckFor($user))
            ->create();
    }
}
