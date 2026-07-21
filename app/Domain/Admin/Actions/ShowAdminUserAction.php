<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class ShowAdminUserAction
{
    public function handle(string $convoLabId): AdminUserProjection
    {
        $convoLabId = strtolower(trim($convoLabId));
        if (! Str::isUuid($convoLabId)) {
            throw (new ModelNotFoundException)->setModel(AdminUserProjection::class);
        }

        return AdminUserProjection::query()
            ->where('convolab_id', $convoLabId)
            ->firstOrFail();
    }
}
