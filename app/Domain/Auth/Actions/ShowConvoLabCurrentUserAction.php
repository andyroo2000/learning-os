<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class ShowConvoLabCurrentUserAction
{
    public function handle(string $convoLabUserId): AdminUserProjection
    {
        $convoLabUserId = Str::lower(trim($convoLabUserId));
        if (! Str::isUuid($convoLabUserId)) {
            throw (new ModelNotFoundException)->setModel(AdminUserProjection::class);
        }

        return AdminUserProjection::query()
            ->where('convolab_id', $convoLabUserId)
            ->firstOrFail();
    }
}
