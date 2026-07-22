<?php

namespace App\Domain\Auth\Actions;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class ResolveConvoLabUserAction
{
    public function handle(string $convoLabUserId): User
    {
        $convoLabUserId = Str::lower(trim($convoLabUserId));
        if (! Str::isUuid($convoLabUserId)) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }

        return User::query()
            ->where('convolab_id', $convoLabUserId)
            ->whereHas('adminUserProjection', fn ($query) => $query->whereKey($convoLabUserId))
            ->firstOrFail();
    }
}
