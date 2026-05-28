<?php

namespace App\Policies;

use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CardPolicy
{
    public function view(User $user, Card $card): Response
    {
        return $this->userOwnsCard($user, $card)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, Card $card): Response
    {
        return $this->view($user, $card);
    }

    private function userOwnsCard(User $user, Card $card): bool
    {
        if ($card->relationLoaded('deck')) {
            return $card->deck !== null && $card->deck->user_id === $user->id;
        }

        $ownerId = $card->deck()->value('user_id');

        return $ownerId !== null && $ownerId === $user->id;
    }
}
