<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\AdminInviteCode;
use Illuminate\Database\Eloquent\Collection;

class ListAdminInviteCodesAction
{
    /** @return Collection<int, AdminInviteCode> */
    public function handle(): Collection
    {
        return AdminInviteCode::query()
            ->with('adminUserProjection')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
