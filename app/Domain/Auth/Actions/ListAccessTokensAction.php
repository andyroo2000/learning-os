<?php

namespace App\Domain\Auth\Actions;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Sanctum\PersonalAccessToken;

class ListAccessTokensAction
{
    /**
     * @return Collection<int, PersonalAccessToken>
     */
    public function handle(User $user): Collection
    {
        return $user->tokens()
            ->latest('created_at')
            ->get();
    }
}
