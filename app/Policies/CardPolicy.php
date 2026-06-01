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

    public function delete(User $user, Card $card): Response
    {
        return $this->userOwnsCardIncludingTrashedDeck($user, $card)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Keep this on single-record call sites; list authorization should avoid N+1s.
     */
    private function userOwnsCard(User $user, Card $card): bool
    {
        // Always query active decks: route model binding guards against trashed cards,
        // but a live card can carry a stale loaded deck after a cascade.
        $ownerId = $card->deck()->value('user_id');

        return $ownerId !== null && $ownerId === $user->id;
    }

    private function userOwnsCardIncludingTrashedDeck(User $user, Card $card): bool
    {
        // Always query with trashed decks, accepting the extra lookup so a loaded
        // relation resolved without trashed rows cannot break cascade-delete retries.
        // Deck force-deletes hard-delete cards through the FK, so retry ownership
        // only needs to support live and soft-deleted decks.
        // This delete-only path should not add a relationLoaded shortcut.
        $ownerId = $card->deck()->withTrashed()->value('user_id');

        return $ownerId !== null && $ownerId === $user->id;
    }
}
