<?php

namespace App\Policies;

use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DeckPolicy
{
    public function view(User $user, Deck $deck): Response
    {
        return $deck->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
