<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class ShowConvoLabCurrentUserAction
{
    public function handle(string $convoLabUserId, ?string $sourceSystem = null): AdminUserProjection
    {
        $convoLabUserId = Str::lower(trim($convoLabUserId));
        if (! Str::isUuid($convoLabUserId)) {
            throw (new ModelNotFoundException)->setModel(AdminUserProjection::class);
        }

        return AdminUserProjection::query()
            ->where('convolab_id', $convoLabUserId)
            ->when($sourceSystem !== null, fn ($query) => $query->where('source_system', $sourceSystem))
            ->firstOrFail();
    }
}
