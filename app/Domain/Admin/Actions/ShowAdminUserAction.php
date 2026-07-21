<?php

namespace App\Domain\Admin\Actions;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class ShowAdminUserAction
{
    public function handle(string $convoLabId): User
    {
        $convoLabId = strtolower(trim($convoLabId));
        if (! Str::isUuid($convoLabId)) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }

        return User::query()
            ->where('convolab_admin_visible', true)
            ->where('convolab_id', $convoLabId)
            ->firstOrFail();
    }
}
