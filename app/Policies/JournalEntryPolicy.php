<?php

namespace App\Policies;

use App\Domain\Journal\Models\JournalEntry;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class JournalEntryPolicy
{
    public function view(User $user, JournalEntry $entry): Response
    {
        return $this->ownership($user, $entry);
    }

    public function update(User $user, JournalEntry $entry): Response
    {
        return $this->ownership($user, $entry);
    }

    public function delete(User $user, JournalEntry $entry): Response
    {
        return $this->ownership($user, $entry);
    }

    private function ownership(User $user, JournalEntry $entry): Response
    {
        return $entry->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
