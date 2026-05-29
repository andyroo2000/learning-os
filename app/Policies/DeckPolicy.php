<?php

namespace App\Policies;

use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DeckPolicy
{
    public function view(User $user, Deck $deck): Response
    {
        return $this->owns($user, $deck)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, Deck $deck): Response
    {
        return $this->owns($user, $deck)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function owns(User $user, Deck $deck): bool
    {
        return $deck->user_id === $user->id;
    }
}
