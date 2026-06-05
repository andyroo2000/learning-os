<?php

namespace App\Policies;

use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CardReviewEventPolicy
{
    public function view(User $user, CardReviewEvent $cardReviewEvent): Response
    {
        return $cardReviewEvent->card()
            ->whereHas('deck', fn ($query) => $query->where('user_id', $user->id))
            ->exists()
                ? Response::allow()
                : Response::denyAsNotFound();
    }

    public function delete(User $user, CardReviewEvent $cardReviewEvent): Response
    {
        return $this->view($user, $cardReviewEvent);
    }
}
